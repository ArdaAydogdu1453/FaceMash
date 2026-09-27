<?php
/**
 * ASB v2.1 — Adaptive Swiss-Bandit Matchmaking Engine
 * ----------------------------------------------------
 * High-performance active learning & Multi-Armed Bandit matching engine.
 * Solves the O(N^2) combinatorial explosion by maximizing Information Entropy
 * and prioritizing under-represented candidates (UCB1 cold-start).
 *
 * Fully integrated with FaceMash schema: photos(id, filename, rating, wins, losses)
 */

const ASB_TARGET_STEPS        = 15;  // Standard sprint session length
const ASB_CANDIDATE_POOL_SIZE = 8;   // 4 explore + 4 exploit in mid stages
const ASB_CLOSE_BATTLE_DELTA  = 60;  // Elo diff under this triggers "Basa Bas Mucadele" badge
const ASB_COOLDOWN_HARD       = 3;   // Exclude candidates seen in the last N matches
const ASB_COOLDOWN_SOFT       = 7;   // Soft penalty for candidates seen in the last N matches
const ASB_REPEAT_PENALTY      = 0.18;// Balanced penalty against maximum entropy (1.0)

/**
 * Normalizes filenames into clean display titles.
 * Example: "ali-yerlikaya.jpg" -> "Ali Yerlikaya"
 */
function format_display_name(string $filename): string
{
    $name = pathinfo($filename, PATHINFO_FILENAME);
    $name = preg_replace('/[-_.]+/', ' ', $name);
    $name = trim(preg_replace('/\s+/', ' ', $name));
    if (function_exists('mb_convert_case')) {
        return mb_convert_case($name, MB_CASE_TITLE, "UTF-8");
    }
    return ucwords($name);
}

/**
 * Uncertainty function: candidates with fewer matches have higher uncertainty.
 */
function asb_uncertainty(int $matches): float
{
    return 1.0 / sqrt($matches + 1);
}

/**
 * Standard Elo win probability (logistic curve).
 */
function asb_win_probability(float $ratingA, float $ratingB): float
{
    return 1.0 / (1.0 + (10.0 ** (($ratingB - $ratingA) / 400.0)));
}

/**
 * Shannon Information Entropy — peaks at p=0.5 (maximum 1.0 bit of information).
 */
function asb_entropy(float $p): float
{
    $p = max(min($p, 0.999999), 0.000001);
    return -$p * log($p, 2) - (1.0 - $p) * log(1.0 - $p, 2);
}

/**
 * Determines session tournament stage based on current step number.
 */
function asb_stage_for_step(int $step): string
{
    if ($step <= 4)  return 'discovery';    // UCB1 Exploration / Cold Start
    if ($step <= 9)  return 'calibration';  // Mid Swiss-bracket pairing
    if ($step <= 13) return 'challenger';   // High-stakes Contender battles
    return 'final';                         // Championship Apex clash
}

/**
 * Returns photo IDs from the last $matchCount matches (2 photos per match).
 */
function asb_recent_photo_ids(array $seen, int $matchCount): array
{
    if (empty($seen)) return [];
    return array_map('intval', array_slice($seen, -($matchCount * 2)));
}

/**
 * Fetches candidate pool based on session stage and exclusion rules.
 * Dynamically synthesizes the 'name' field from 'filename' to remain 100% compatible
 * with the photos table schema.
 */
function asb_fetch_candidate_pool(mysqli $conn, string $stage, array $hardExcludeIds): array
{
    $hardExcludeIds = array_map('intval', array_filter($hardExcludeIds));
    $excludeSql = !empty($hardExcludeIds)
        ? ('AND id NOT IN (' . implode(',', $hardExcludeIds) . ')')
        : '';

    $cols = "id, filename, rating, wins, losses";

    if ($stage === 'discovery') {
        $sql = "SELECT $cols FROM photos
                WHERE 1=1 $excludeSql
                ORDER BY (wins + losses) ASC, RAND()
                LIMIT " . ASB_CANDIDATE_POOL_SIZE;
    } elseif ($stage === 'final') {
        $sql = "SELECT $cols FROM photos
                WHERE 1=1 $excludeSql
                ORDER BY rating DESC, wins DESC
                LIMIT " . ASB_CANDIDATE_POOL_SIZE;
    } else { // calibration / challenger
        $half = (int) floor(ASB_CANDIDATE_POOL_SIZE / 2);
        $sql = "(SELECT $cols FROM photos WHERE 1=1 $excludeSql
                 ORDER BY (wins + losses) ASC, RAND() LIMIT $half)
                UNION
                (SELECT $cols FROM photos WHERE 1=1 $excludeSql
                 ORDER BY rating DESC LIMIT $half)";
    }

    $result = $conn->query($sql);
    $rows = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $row['name'] = format_display_name($row['filename']);
            $row['displayName'] = $row['name'];
            $row['rating'] = (int)round($row['rating']);
            $rows[] = $row;
        }
    }
    return $rows;
}

/**
 * Selects the optimal pair from the candidate pool maximizing information gain:
 *   Score = Entropy(p) * Uncertainty(A) * Uncertainty(B) - RepeatPenalty
 */
function asb_select_best_pair(array $pool, array $softPenaltyIds): ?array
{
    $n = count($pool);
    if ($n < 2) return null;

    $best = null;
    $bestScore = -INF;

    for ($i = 0; $i < $n; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
            $a = $pool[$i];
            $b = $pool[$j];
            if ((int)$a['id'] === (int)$b['id']) continue;

            $matchesA = (int)$a['wins'] + (int)$a['losses'];
            $matchesB = (int)$b['wins'] + (int)$b['losses'];

            $p = asb_win_probability((float)$a['rating'], (float)$b['rating']);
            $entropy = asb_entropy($p);
            $uA = asb_uncertainty($matchesA);
            $uB = asb_uncertainty($matchesB);

            $repeatPenalty = 0.0;
            if (in_array((int)$a['id'], $softPenaltyIds, true) ||
                in_array((int)$b['id'], $softPenaltyIds, true)) {
                $repeatPenalty = ASB_REPEAT_PENALTY;
            }

            $score = ($entropy * $uA * $uB) - $repeatPenalty;

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = [$a, $b];
            }
        }
    }

    return $best;
}

/**
 * Main Entry Point: returns next adaptive matchup for the user's active session.
 */
function get_adaptive_matchup(mysqli $conn, int $currentStep, array $seenPhotoIds = []): ?array
{
    $stage = asb_stage_for_step($currentStep);

    $hardExclude = asb_recent_photo_ids($seenPhotoIds, ASB_COOLDOWN_HARD);
    $softExclude = asb_recent_photo_ids($seenPhotoIds, ASB_COOLDOWN_SOFT);

    $pool = asb_fetch_candidate_pool($conn, $stage, $hardExclude);

    // Fallback: If hard exclusion leaves fewer than 2 candidates, release exclusion
    if (count($pool) < 2) {
        $pool = asb_fetch_candidate_pool($conn, $stage, []);
    }

    // Ultimate fallback for very small datasets
    if (count($pool) < 2) {
        $res = $conn->query("SELECT id, filename, rating, wins, losses FROM photos ORDER BY RAND() LIMIT 2");
        $pool = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $r['name'] = format_display_name($r['filename']);
                $r['displayName'] = $r['name'];
                $r['rating'] = (int)round($r['rating']);
                $pool[] = $r;
            }
        }
    }

    $pair = asb_select_best_pair($pool, $softExclude);
    if (!$pair) return null;

    // Shuffle left/right so presentation is unbiased
    shuffle($pair);
    [$photoA, $photoB] = $pair;
    $ratingDelta = abs((float)$photoA['rating'] - (float)$photoB['rating']);

    return [
        'stage'           => $stage,
        'photo_a'         => $photoA,
        'photo_b'         => $photoB,
        'is_close_battle' => ($ratingDelta <= ASB_CLOSE_BATTLE_DELTA),
    ];
}

/**
 * Backward compatibility wrapper for legacy callers.
 */
function get_new_matchup($conn)
{
    $step = isset($_SESSION['asb']['step']) ? (int)$_SESSION['asb']['step'] + 1 : 1;
    $seen = $_SESSION['asb']['seen'] ?? [];
    $matchup = get_adaptive_matchup($conn, $step, $seen);
    if (!$matchup) return null;
    return [$matchup['photo_a'], $matchup['photo_b']];
}

/**
 * Generates the user's personal session podium from in-session stats.
 * ASLA boş dönmez (DB'de foto varsa): session eksikse global top-3 ile doldurur.
 */
function asb_build_podium(array $sessionStats, ?mysqli $conn = null): array
{
    $items = [];
    $seenIds = [];
    foreach ($sessionStats as $photoId => $s) {
        if (!is_array($s) || empty($s['filename'])) continue;
        $pid = (int)$photoId;
        if ($pid <= 0 || isset($seenIds[$pid])) continue;
        $seenIds[$pid] = true;
        $fn = (string)$s['filename'];
        $items[] = [
            'photo_id' => $pid,
            'wins'     => (int)($s['wins'] ?? 0),
            'losses'   => (int)($s['losses'] ?? 0),
            'filename' => $fn,
            'name'     => !empty($s['name']) ? (string)$s['name'] : format_display_name($fn),
        ];
    }

    // Sort by wins desc, then losses asc
    usort($items, function ($a, $b) {
        return ($b['wins'] <=> $a['wins']) ?: ($a['losses'] <=> $b['losses']);
    });

    // Fallback: session'da 3'ten az aday varsa DB global top ile tamamla.
    // Böylece ?reset sonrası / F5 sonrası / session GC sonrası ASLA boş podyum çıkmaz.
    if (count($items) < 3 && $conn && !$conn->connect_error) {
        try {
            $existingIds = array_keys($seenIds);
            $excludeSql = !empty($existingIds)
                ? 'WHERE id NOT IN (' . implode(',', array_map('intval', $existingIds)) . ')'
                : '';
            // Önce session'da görülenleri dışla, yetmezse tüm tablodan tamamla
            $res = $conn->query("SELECT id, filename, rating, wins, losses FROM photos $excludeSql ORDER BY rating DESC, wins DESC LIMIT 3");
            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    $pid = (int)$r['id'];
                    if (isset($seenIds[$pid])) continue;
                    $seenIds[$pid] = true;
                    $items[] = [
                        'photo_id' => $pid,
                        'wins'     => (int)$r['wins'],
                        'losses'   => (int)$r['losses'],
                        'filename' => $r['filename'],
                        'name'     => format_display_name($r['filename']),
                    ];
                    if (count($items) >= 3) break;
                }
                $res->free();
            }
            // Hâlâ <3 ise (küçük dataset) kısıtlamasız doldur
            if (count($items) < 3) {
                $res2 = $conn->query("SELECT id, filename, wins, losses FROM photos ORDER BY rating DESC, wins DESC LIMIT 3");
                if ($res2) {
                    while ($r = $res2->fetch_assoc()) {
                        $pid = (int)$r['id'];
                        if (isset($seenIds[$pid])) continue;
                        $seenIds[$pid] = true;
                        $items[] = [
                            'photo_id' => $pid,
                            'wins'     => (int)$r['wins'],
                            'losses'   => (int)$r['losses'],
                            'filename' => $r['filename'],
                            'name'     => format_display_name($r['filename']),
                        ];
                        if (count($items) >= 3) break;
                    }
                    $res2->free();
                }
            }
        } catch (Throwable $e) {
            // DB hatasında session verisiyle devam et
        }
    }

    return array_slice($items, 0, 3);
}
?>