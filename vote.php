<?php
/**
 * ASB v2.1 — Adaptive Swiss-Bandit Vote Endpoint
 * ------------------------------------------------
 * Processes votes with CSRF verification, pair spoofing protection,
 * atomic row-locked transactions (FOR UPDATE), symmetric Elo calculation,
 * and 0ms Pusher WebSocket broadcasting.
 */

// Secure session configuration
if (session_status() === PHP_SESSION_NONE) {
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        session_set_cookie_params([
            'lifetime' => $params['lifetime'],
            'path'     => $params['path'],
            'domain'   => $params['domain'],
            'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/matchup_logic.php';

// Enable strict MySQLi error reporting
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/**
 * Triggers a Pusher event via HTTP API using HMAC-SHA256 signature.
 */
function triggerPusherEvent($data)
{
    if (!defined('PUSHER_APP_ID') || empty(PUSHER_APP_ID)) return;

    $appId   = PUSHER_APP_ID;
    $key     = PUSHER_KEY;
    $secret  = PUSHER_SECRET;
    $cluster = PUSHER_CLUSTER;
    $channel = PUSHER_CHANNEL;
    $event   = PUSHER_EVENT;

    $host      = "api-{$cluster}.pusher.com";
    $path      = "/apps/{$appId}/events";
    $timestamp = time();

    $body = json_encode([
        'name'     => $event,
        'channels' => [$channel],
        'data'     => json_encode($data)
    ]);

    $bodyMd5     = md5($body);
    $queryParams = "auth_key={$key}&auth_timestamp={$timestamp}&auth_version=1.0&body_md5={$bodyMd5}";
    $toSign      = "POST\n{$path}\n{$queryParams}";
    $signature   = hash_hmac('sha256', $toSign, $secret);

    $url = "https://{$host}{$path}?{$queryParams}&auth_signature={$signature}";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     $body);
    curl_setopt($ch, CURLOPT_HTTPHEADER,     ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT,        4);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_exec($ch);
    curl_close($ch);
}

/**
 * Fetches the current top leaderboard from DB for Pusher streaming.
 */
function fetchLeaderboard(mysqli $conn): array
{
    $res = $conn->query("
        SELECT id, filename, wins, losses, rating,
               ROUND((wins / NULLIF(wins + losses, 0)) * 100, 1) AS win_rate
        FROM photos
        ORDER BY rating DESC, wins DESC, id ASC
        LIMIT 25
    ");

    $board = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $row['rating']      = (int)round($row['rating']);
            $row['win_rate']    = $row['win_rate'] !== null ? (float)$row['win_rate'] : 0.0;
            $row['displayName'] = format_display_name($row['filename']);
            $board[] = $row;
        }
    }
    return $board;
}

/**
 * Symmetric K-Factor preserves zero-sum Elo dynamics.
 */
function asb_k_factor(int $matchesW, int $matchesL): int
{
    $m = min($matchesW, $matchesL);
    if ($m < 5)  return 32;
    if ($m < 20) return 24;
    return 16;
}

/**
 * Bayesian-smoothed community preference feedback.
 */
function asb_community_message(int $wins, int $losses): array
{
    $total = $wins + $losses;
    $smoothedPct = (int) round((($wins + 1) / ($total + 2)) * 100);

    if ($total < 3) {
        return ['label' => 'Kalibre Ediliyor', 'pct' => null, 'is_underdog' => false];
    }
    if ($smoothedPct < 48) {
        return [
            'label' => "%{$smoothedPct} Aykırı Zevk! (Cesur Karar)",
            'pct' => $smoothedPct,
            'is_underdog' => true
        ];
    }
    return [
        'label' => "%{$smoothedPct} Çoğunlukla Aynı Zevk!",
        'pct' => $smoothedPct,
        'is_underdog' => false
    ];
}

function asb_fail(int $httpCode, string $error): void
{
    http_response_code($httpCode);
    echo json_encode(['success' => false, 'error' => $error]);
    exit;
}

// ─── Input Parsing (JSON or POST Form) ───────────────────────────────────────
$rawInput = file_get_contents('php://input');
$input    = json_decode($rawInput, true) ?: $_POST;

$winnerId = (int)($input['winner_id'] ?? $input['chosen_id'] ?? 0);
$loserId  = (int)($input['loser_id']  ?? $input['other_id']  ?? 0);
$csrf     = (string)($input['csrf']    ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

if (!$winnerId || !$loserId || $winnerId === $loserId) {
    asb_fail(400, 'Geçersiz aday kimlikleri.');
}

// ─── Session & CSRF Verification ─────────────────────────────────────────────
if (empty($_SESSION['asb']) || empty($_SESSION['asb']['current_matchup'])) {
    asb_fail(409, 'Aktif bir eşleşme oturumu bulunamadı.');
}

if (!hash_equals($_SESSION['asb']['csrf'] ?? '', $csrf)) {
    asb_fail(403, 'Güvenlik doğrulaması (CSRF) başarısız oldu.');
}

// ─── Hardware GPU Fingerprint & Anti-Spam Rate Limiting ─────────────────────
$deviceGpu = trim((string)($input['device_gpu'] ?? 'Bilinmeyen Cihaz'));
$deviceId  = trim((string)($input['device_id'] ?? ''));

$nowMicro = microtime(true);
if (isset($_SESSION['asb']['last_vote_micro'])) {
    $diff = $nowMicro - (float)$_SESSION['asb']['last_vote_micro'];
    if ($diff < 0.12) {
        asb_fail(429, 'Hızlı spam tıklama algılandı. Lütfen normal tempoda oy kullanınız.');
    }
}
$_SESSION['asb']['last_vote_micro'] = $nowMicro;
if ($deviceGpu) {
    $_SESSION['asb']['device_gpu'] = $deviceGpu;
}
if ($deviceId) {
    $_SESSION['asb']['device_id'] = $deviceId;
}

// ─── Active Pair Validation (Spoofing Protection) ────────────────────────────
$current = $_SESSION['asb']['current_matchup'];
$validPair =
    ($winnerId === (int)$current['a'] && $loserId === (int)$current['b']) ||
    ($winnerId === (int)$current['b'] && $loserId === (int)$current['a']);

if (!$validPair) {
    asb_fail(409, 'Sunucudaki aktif eşleşmeyle uyuşmayan oy isteği.');
}

// ─── Atomic Row-Locked Elo Transaction ───────────────────────────────────────
$conn->begin_transaction();
try {
    $stmt = $conn->prepare("SELECT id, filename, rating, wins, losses FROM photos WHERE id IN (?, ?) FOR UPDATE");
    $stmt->bind_param('ii', $winnerId, $loserId);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $rows[(int)$r['id']] = $r;
    }
    $stmt->close();

    if (!isset($rows[$winnerId]) || !isset($rows[$loserId])) {
        throw new RuntimeException('photo_not_found');
    }

    $winner = $rows[$winnerId];
    $loser  = $rows[$loserId];

    $ratingW  = (float)$winner['rating'];
    $ratingL  = (float)$loser['rating'];
    $matchesW = (int)$winner['wins'] + (int)$winner['losses'];
    $matchesL = (int)$loser['wins']  + (int)$loser['losses'];

    $expectedW = asb_win_probability($ratingW, $ratingL);
    $expectedL = 1.0 - $expectedW;

    $k = asb_k_factor($matchesW, $matchesL);

    $newRatingW = max(100.0, $ratingW + $k * (1.0 - $expectedW));
    $newRatingL = max(100.0, $ratingL + $k * (0.0 - $expectedL));
    $eloDelta   = (int)round($newRatingW - $ratingW);

    $updW = $conn->prepare("UPDATE photos SET rating = ?, wins = wins + 1 WHERE id = ?");
    $updW->bind_param('di', $newRatingW, $winnerId);
    $updW->execute();
    $updW->close();

    $updL = $conn->prepare("UPDATE photos SET rating = ?, losses = losses + 1 WHERE id = ?");
    $updL->bind_param('di', $newRatingL, $loserId);
    $updL->execute();
    $updL->close();

    $conn->commit();

    // 0ms Real-Time Pusher Broadcast to all connected leaderboards
    $leaderboard = fetchLeaderboard($conn);
    triggerPusherEvent($leaderboard);

} catch (Throwable $e) {
    $conn->rollback();
    asb_fail(500, 'Veritabanı güncellemesi başarısız oldu: ' . $e->getMessage());
}

// ─── Post-Commit Session State Progression ───────────────────────────────────
$_SESSION['asb']['step']++;
$_SESSION['asb']['seen'][] = $winnerId;
$_SESSION['asb']['seen'][] = $loserId;

$winnerName = format_display_name($winner['filename']);
$loserName  = format_display_name($loser['filename']);

if (!isset($_SESSION['asb']['session_stats'][$winnerId])) {
    $_SESSION['asb']['session_stats'][$winnerId] = [
        'wins' => 0, 'losses' => 0, 'filename' => $winner['filename'], 'name' => $winnerName
    ];
}
$_SESSION['asb']['session_stats'][$winnerId]['wins']++;

if (!isset($_SESSION['asb']['session_stats'][$loserId])) {
    $_SESSION['asb']['session_stats'][$loserId] = [
        'wins' => 0, 'losses' => 0, 'filename' => $loser['filename'], 'name' => $loserName
    ];
}
$_SESSION['asb']['session_stats'][$loserId]['losses']++;

$currentStep = (int)$_SESSION['asb']['step'];
$community   = asb_community_message((int)$winner['wins'] + 1, (int)$winner['losses']);
$isComplete  = ($currentStep >= ASB_TARGET_STEPS);

$podium      = null;
$nextMatchup = null;

if ($isComplete) {
    $_SESSION['asb']['completed'] = true;
    $_SESSION['asb']['current_matchup'] = null;
    $podium = asb_build_podium($_SESSION['asb']['session_stats'], $conn);
} else {
    $nextMatchup = get_adaptive_matchup($conn, $currentStep + 1, $_SESSION['asb']['seen']);
    if ($nextMatchup) {
        $_SESSION['asb']['current_matchup'] = [
            'a' => (int)$nextMatchup['photo_a']['id'],
            'b' => (int)$nextMatchup['photo_b']['id'],
        ];
    }
}

echo json_encode([
    'success'            => true,
    'community_label'    => $community['label'],
    'community_pct'      => $community['pct'],
    'is_underdog'        => $community['is_underdog'] ?? false,
    'elo_delta'          => $eloDelta,
    'current_step'       => $currentStep,
    'target_steps'       => ASB_TARGET_STEPS,
    'is_sprint_complete' => $isComplete,
    'next_matchup'       => $nextMatchup,
    'podium'             => $podium,
]);
exit;
?>