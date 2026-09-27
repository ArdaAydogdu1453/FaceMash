<?php
/**
 * FaceMash — Adaptive Swiss-Bandit Arena
 * ---------------------------------------
 * Ultra-snappy pairwise comparison arena with:
 *  - Hardware GPU WebGL fingerprinting for anti-spam rate limiting
 *  - Olympic-style 3-Tier Podium rendered directly in place of comparison cards
 *  - Viewport-fitted layout (100vh zero scrolling)
 *  - Ultra-fast 140ms transitions
 *  - Crisp SVG icons & native Web Audio synthesis
 */

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

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/matchup_logic.php';

// Reset session if explicitly requested — redirect ile URL'yi temizle
// (?reset=1 adres çubuğunda yapışıp kalmasın, yoksa "yeni oyuna geçilmiş hala gözüküyor" sanılıyor)
if (isset($_GET['reset'])) {
    $_SESSION['asb'] = [
        'session_id'      => bin2hex(random_bytes(16)),
        'step'            => 0,
        'current_matchup' => null,
        'seen'            => [],
        'session_stats'   => [],
        'csrf'            => bin2hex(random_bytes(32)),
        'completed'       => false,
    ];
    header('Location: index.php');
    exit;
}
if (empty($_SESSION['asb']) || empty($_SESSION['asb']['session_id'])) {
    $_SESSION['asb'] = [
        'session_id'      => bin2hex(random_bytes(16)),
        'step'            => 0,
        'current_matchup' => null,
        'seen'            => [],
        'session_stats'   => [],
        'csrf'            => bin2hex(random_bytes(32)),
        'completed'       => false,
    ];
}

$isCompleted = !empty($_SESSION['asb']['completed']);
$podium      = null;
$matchup     = null;
$playerTag   = strtoupper(substr($_SESSION['asb']['session_id'] ?? 'USER', 0, 4));

if ($isCompleted) {
    // F5 Defense: Load podium if already finished
    $podium = asb_build_podium($_SESSION['asb']['session_stats'] ?? [], $conn);
} else {
    $currentStep = (int)$_SESSION['asb']['step'] + 1;

    // Session Persistence: Re-use active matchup on page refresh
    if (!empty($_SESSION['asb']['current_matchup']['a']) && !empty($_SESSION['asb']['current_matchup']['b'])) {
        $idA = (int)$_SESSION['asb']['current_matchup']['a'];
        $idB = (int)$_SESSION['asb']['current_matchup']['b'];

        $stmt = $conn->prepare("SELECT id, filename, rating, wins, losses FROM photos WHERE id IN (?, ?)");
        $stmt->bind_param("ii", $idA, $idB);
        $stmt->execute();
        $res = $stmt->get_result();
        $pair = [];
        while ($r = $res->fetch_assoc()) {
            $r['name'] = format_display_name($r['filename']);
            $r['displayName'] = $r['name'];
            $r['rating'] = (int)round($r['rating']);
            $pair[$r['id']] = $r;
        }
        $stmt->close();

        if (isset($pair[$idA], $pair[$idB])) {
            $photoA = $pair[$idA];
            $photoB = $pair[$idB];
            $ratingDelta = abs((float)$photoA['rating'] - (float)$photoB['rating']);
            $matchup = [
                'stage'           => asb_stage_for_step($currentStep),
                'photo_a'         => $photoA,
                'photo_b'         => $photoB,
                'is_close_battle' => ($ratingDelta <= ASB_CLOSE_BATTLE_DELTA),
            ];
        }
    }

    // Generate new matchup if none active
    if (!$matchup) {
        $matchup = get_adaptive_matchup($conn, $currentStep, $_SESSION['asb']['seen'] ?? []);
        if ($matchup) {
            $_SESSION['asb']['current_matchup'] = [
                'a' => (int)$matchup['photo_a']['id'],
                'b' => (int)$matchup['photo_b']['id'],
            ];
        }
    }
}

$displayStep = (int)$_SESSION['asb']['step'] + 1;
?>
<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="asb-csrf" content="<?= htmlspecialchars($_SESSION['asb']['csrf'] ?? '') ?>">
  <title>FaceMash — Comparison Arena</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/styles.css">
  <link rel="stylesheet" href="assets/styles_asb.css">
  <style>
    /* Viewport-Fitted Zero-Scroll Layout */
    html, body.asb-body {
      height: 100vh;
      overflow-y: auto;
      overflow-x: hidden;
      margin: 0;
      padding: 0;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: space-between;
    }
    .asb-top-meta {
      width: min(720px, 94%);
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 0.75rem 0.5rem 0.25rem 0.5rem;
      gap: 8px;
    }
    .asb-meta-left {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
    }
    .asb-player-badge, .asb-gpu-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: var(--asb-surface);
      border: 1px solid var(--asb-surface-2);
      padding: 0.3rem 0.75rem;
      border-radius: 999px;
      font-family: var(--asb-font-display);
      font-weight: 600;
      font-size: 0.78rem;
      color: var(--asb-text-dim);
    }
    .asb-gpu-badge {
      max-width: 180px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    @media (max-width: 480px) {
      .asb-gpu-badge {
        max-width: 120px;
      }
      .asb-player-badge {
        font-size: 0.7rem;
        padding: 0.25rem 0.6rem;
      }
    }
    .player-dot {
      width: 7px;
      height: 7px;
      background: var(--asb-mint);
      border-radius: 50%;
      box-shadow: 0 0 6px var(--asb-mint);
    }
    .asb-shell {
      flex: 1;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 0.85rem;
      padding: 0.5rem 1rem;
      width: 100%;
      max-width: 720px;
      box-sizing: border-box;
    }
    .asb-tracker {
      width: min(480px, 100%);
    }
    .asb-tracker__label {
      display: flex;
      align-items: center;
      justify-content: space-between;
      font-family: var(--asb-font-display);
      font-weight: 600;
      font-size: 0.88rem;
      color: var(--asb-text-dim);
      margin-bottom: 0.4rem;
    }
    .asb-round-badge {
      color: var(--asb-text);
      font-weight: 700;
    }
    .asb-tracker__ticks {
      display: flex;
      gap: 0.25rem;
    }
    .asb-tick {
      flex: 1;
      height: 5px;
      border-radius: 3px;
      background: var(--asb-surface-2);
      transition: background 0.2s ease, transform 0.2s ease;
    }
    .asb-tick.is-done { background: var(--asb-mint); }
    .asb-tick.is-current {
      background: var(--asb-gold);
      transform: scaleY(1.4);
    }
    .asb-arena {
      position: relative;
      display: grid;
      grid-template-columns: 1fr auto 1fr;
      align-items: center;
      gap: 1.25rem;
      width: 100%;
      min-height: 280px;
    }
    .asb-card {
      position: relative;
      border: 2px solid var(--asb-surface-2);
      border-radius: 14px;
      overflow: hidden;
      background: var(--asb-surface);
      cursor: pointer;
      padding: 0;
      height: clamp(230px, 44vh, 340px);
      aspect-ratio: 3 / 4;
      margin: 0 auto;
      transition: border-color 0.15s ease, transform 0.12s ease;
      box-shadow: 0 4px 18px rgba(0,0,0,0.35);
    }
    .asb-card:hover:not(:disabled) {
      border-color: var(--asb-gold);
      transform: translateY(-3px);
    }
    .asb-card.is-chosen {
      transform: scale(0.96);
      border-color: var(--asb-gold);
    }
    .asb-card img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
    }
    .asb-card__name {
      position: absolute;
      top: 0; left: 0; right: 0;
      padding: 0.6rem 0.8rem;
      background: linear-gradient(180deg, rgba(18, 19, 26, 0.9) 0%, rgba(18, 19, 26, 0) 100%);
      color: #ffffff;
      font-family: var(--asb-font-display);
      font-weight: 700;
      font-size: 0.92rem;
      text-align: left;
    }
    .asb-card__reveal {
      position: absolute;
      left: 50%;
      bottom: 12px;
      transform: translate(-50%, 6px);
      padding: 0.4rem 0.85rem;
      border-radius: 999px;
      background: var(--asb-mint);
      color: var(--asb-ink);
      font-family: var(--asb-font-display);
      font-weight: 700;
      font-size: 0.8rem;
      white-space: nowrap;
      opacity: 0;
      transition: opacity 0.15s ease, transform 0.15s ease;
      pointer-events: none;
      box-shadow: 0 4px 12px rgba(47, 184, 147, 0.4);
    }
    .asb-card__reveal.is-visible {
      opacity: 1;
      transform: translate(-50%, 0);
    }
    .asb-card__reveal--underdog {
      background: #a855f7;
      color: #ffffff;
      box-shadow: 0 4px 12px rgba(168, 85, 247, 0.5);
    }
    .asb-vs {
      font-family: var(--asb-font-display);
      font-weight: 800;
      font-size: 0.95rem;
      color: var(--asb-text-dim);
      background: var(--asb-surface-2);
      width: 38px;
      height: 38px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: 0 2px 8px rgba(0,0,0,0.3);
    }
    .asb-close-badge {
      position: absolute;
      top: -0.85rem;
      left: 50%;
      transform: translateX(-50%);
      background: var(--asb-crimson);
      color: var(--asb-text);
      font-family: var(--asb-font-display);
      font-weight: 700;
      font-size: 0.75rem;
      padding: 0.3rem 0.85rem;
      border-radius: 999px;
      display: inline-flex;
      align-items: center;
      gap: 5px;
      z-index: 5;
      box-shadow: 0 4px 14px rgba(226, 61, 69, 0.4);
    }
    .asb-hint {
      color: var(--asb-text-dim);
      font-size: 0.82rem;
      margin: 0;
    }
    .main-nav {
      margin-top: 0.5rem !important;
      margin-bottom: 0.75rem !important;
    }
    /* Podyum (arena-içi) — harici CSS cache'e takılsa bile çalışsın diye kritik stiller inline */
    .asb-podium-head { text-align: center; margin-bottom: 0.25rem; }
    .asb-podium-title { font-family: var(--asb-font-display); font-weight: 800; font-size: 1.5rem; color: var(--asb-text); }
    .asb-podium-sub { font-size: 0.85rem; color: var(--asb-text-dim); margin-top: 0.25rem; }
    .asb-pop-in { animation: asbPopIn 0.35s cubic-bezier(0.2,0.9,0.3,1.2); }
    @keyframes asbPopIn { from { opacity: 0; transform: translateY(14px) scale(0.97); } to { opacity: 1; transform: none; } }
    .pillar-rank { font-family: var(--asb-font-display); font-weight: 800; font-size: 0.7rem; width: 22px; height: 22px; border-radius: 50%; display: flex; align-items: center; justify-content: center; background: var(--asb-surface-2); color: var(--asb-text-dim); margin-bottom: 2px; }
    .pillar-rank.gold-rank { background: var(--asb-gold); color: var(--asb-ink); box-shadow: 0 0 12px rgba(232,169,60,0.55); }
    @media (max-width: 640px) {
      .asb-card {
        height: clamp(160px, 28vh, 220px);
      }
      .asb-arena {
        grid-template-columns: 1fr auto 1fr;
        gap: 0.5rem;
      }
      .asb-vs { 
        width: 32px; 
        height: 32px; 
        font-size: 0.75rem; 
      }
      .asb-card__name {
        font-size: 0.8rem;
        padding: 0.4rem 0.6rem;
      }
    }
  </style>
</head>
<body class="asb-body">

<div class="asb-top-meta">
  <div class="asb-meta-left">
    <div class="asb-player-badge">
      <span class="player-dot"></span> Player #<?= $playerTag ?>
    </div>
    <div class="asb-gpu-badge" id="asbGpuBadge" title="Hardware Verification">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="4" width="16" height="16" rx="2" ry="2"></rect><rect x="9" y="9" width="6" height="6"></rect><line x1="9" y1="1" x2="9" y2="4"></line><line x1="15" y1="1" x2="15" y2="4"></line><line x1="9" y1="20" x2="9" y2="23"></line><line x1="15" y1="20" x2="15" y2="23"></line><line x1="20" y1="9" x2="23" y2="9"></line><line x1="20" y1="14" x2="23" y2="14"></line><line x1="1" y1="9" x2="4" y2="9"></line><line x1="1" y1="14" x2="4" y2="14"></line></svg>
      <span id="asbGpuName">Scanning Hardware...</span>
    </div>
  </div>
  <button type="button" class="asb-sound-btn" id="asbSoundToggle" title="Toggle Sound">
    <svg id="svgSoundOn" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"></polygon><path d="M19.07 4.93a10 10 0 0 1 0 14.14M15.54 8.46a5 5 0 0 1 0 7.07"></path></svg>
    <svg id="svgSoundOff" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none;"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"></polygon><line x1="23" y1="9" x2="17" y2="15"></line><line x1="17" y1="9" x2="23" y2="15"></line></svg>
  </button>
</div>

<div class="asb-shell" id="asbShell">

  <!-- Adım ve İlerleme Takipçisi -->
  <div class="asb-tracker" id="asbTracker">
    <?php if ($isCompleted): ?>
      <div class="asb-tracker__label" style="justify-content:center; color:var(--asb-gold);">
        <span style="display:inline-flex;align-items:center;gap:8px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"></path><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"></path><path d="M4 22h16"></path><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"></path><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"></path><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"></path></svg>Tournament Complete — Champions Podium</span>
      </div>
      <div class="asb-tracker__ticks">
        <?php for ($i = 1; $i <= ASB_TARGET_STEPS; $i++): ?>
          <span class="asb-tick is-done"></span>
        <?php endfor; ?>
      </div>
    <?php else: ?>
      <div class="asb-tracker__label">
        <span class="asb-round-badge">
          Comparison <strong id="asbStepNum"><?= min($displayStep, ASB_TARGET_STEPS) ?></strong> / <?= ASB_TARGET_STEPS ?>
        </span>
        <span style="font-size: 0.78rem; color: var(--asb-mint);">Tournament Session</span>
      </div>
      <div class="asb-tracker__ticks" id="asbTicks">
        <?php for ($i = 1; $i <= ASB_TARGET_STEPS; $i++): ?>
          <span class="asb-tick <?= $i < $displayStep ? 'is-done' : ($i === $displayStep ? 'is-current' : '') ?>"></span>
        <?php endfor; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Karşılaşma Arenası: Tamamlandığında doğrudan podyuma dönüşür -->
  <div class="asb-arena" id="asbArena" <?= $isCompleted ? 'style="display:block;"' : '' ?>
       data-photo-a="<?= (int)($matchup['photo_a']['id'] ?? 0) ?>"
       data-photo-b="<?= (int)($matchup['photo_b']['id'] ?? 0) ?>">

    <?php if ($isCompleted): ?>
      <!-- Arena İçinde Doğrudan Şampiyonluk Podyumu (karşılaştırma kartlarının YERİNE gelir) -->
      <?php if (!empty($podium)): ?>
      <div class="asb-podium-head">
        <div class="asb-podium-title"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-4px;margin-right:8px;color:var(--asb-gold);"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"></path><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"></path><path d="M4 22h16"></path><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"></path><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"></path><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"></path></svg>Session Complete!</div>
        <div class="asb-podium-sub">Your personal podium from this <?= ASB_TARGET_STEPS ?>-step tournament:</div>
      </div>
      <div class="asb-tier-podium asb-pop-in">
        <?php if (isset($podium[1])): ?>
          <div class="asb-podium-pillar rank-silver">
            <div class="pillar-rank">2</div>
            <div class="pillar-photo-wrap">
              <img src="assets/photos/<?= htmlspecialchars($podium[1]['filename']) ?>" alt="<?= htmlspecialchars($podium[1]['name']) ?>" loading="eager">
            </div>
            <div class="pillar-name" title="<?= htmlspecialchars($podium[1]['name']) ?>"><?= htmlspecialchars($podium[1]['name']) ?></div>
            <div class="pillar-score"><?= (int)$podium[1]['wins'] ?>G · <?= (int)$podium[1]['losses'] ?>M</div>
            <div class="pillar-base silver-base"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-1px;margin-right:4px;"><circle cx="12" cy="8" r="6"></circle><path d="M15.5 13 17 22l-5-3-5 3 1.5-9"></path></svg>2. GÜMÜŞ</div>
          </div>
        <?php endif; ?>

        <?php if (isset($podium[0])): ?>
          <div class="asb-podium-pillar rank-gold">
            <div class="pillar-rank gold-rank">1</div>
            <div style="color:var(--asb-gold); margin-bottom:-2px;">
              <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"></path><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"></path><path d="M4 22h16"></path><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"></path><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"></path><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"></path></svg>
            </div>
            <div class="pillar-photo-wrap">
              <img src="assets/photos/<?= htmlspecialchars($podium[0]['filename']) ?>" alt="<?= htmlspecialchars($podium[0]['name']) ?>" loading="eager">
            </div>
            <div class="pillar-name" title="<?= htmlspecialchars($podium[0]['name']) ?>"><?= htmlspecialchars($podium[0]['name']) ?></div>
            <div class="pillar-score"><?= (int)$podium[0]['wins'] ?>G · <?= (int)$podium[0]['losses'] ?>M</div>
            <div class="pillar-base gold-base">ŞAMPİYON</div>
          </div>
        <?php endif; ?>

        <?php if (isset($podium[2])): ?>
          <div class="asb-podium-pillar rank-bronze">
            <div class="pillar-rank">3</div>
            <div class="pillar-photo-wrap">
              <img src="assets/photos/<?= htmlspecialchars($podium[2]['filename']) ?>" alt="<?= htmlspecialchars($podium[2]['name']) ?>" loading="eager">
            </div>
            <div class="pillar-name" title="<?= htmlspecialchars($podium[2]['name']) ?>"><?= htmlspecialchars($podium[2]['name']) ?></div>
            <div class="pillar-score"><?= (int)$podium[2]['wins'] ?>G · <?= (int)$podium[2]['losses'] ?>M</div>
            <div class="pillar-base bronze-base"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-1px;margin-right:4px;"><circle cx="12" cy="8" r="6"></circle><path d="M15.5 13 17 22l-5-3-5 3 1.5-9"></path></svg>3. BRONZ</div>
          </div>
        <?php endif; ?>
      </div>
      <?php else: ?>
      <!-- Asla boş kalmasın: veri yoksa bile aksiyonlar göster -->
      <div class="asb-podium-head">
        <div class="asb-podium-title"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-4px;margin-right:8px;color:var(--asb-gold);"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"></path><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"></path><path d="M4 22h16"></path><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"></path><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"></path><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"></path></svg>Session Complete!</div>
        <div class="asb-podium-sub">Podyum verisi yüklenemedi, ama yeni turnuva hemen başlayabilir.</div>
      </div>
      <?php endif; ?>

      <div class="asb-podium-actions">
        <a href="index.php?reset=1" class="asb-btn asb-btn--primary">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"></path><path d="M3 3v5h5"></path></svg>
          Start New Tournament
        </a>
        <button type="button" id="asbShareBtn" class="asb-btn asb-btn--share">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
          Copy Results
        </button>
        <a href="leaderboard.php" class="asb-btn">Leaderboard</a>
      </div>
      <div id="asbToast" class="asb-toast" hidden>Results copied to clipboard!</div>

    <?php elseif ($matchup): ?>
      <?php if (!empty($matchup['is_close_battle'])): ?>
        <div class="asb-close-badge">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 17.5L3 6V3h3l11.5 11.5"></path><path d="M13 19l6-6"></path><path d="M16 16l4 4"></path><path d="M19 21l2-2"></path></svg>
          Başa Baş Mücadele
        </div>
      <?php endif; ?>

      <button class="asb-card" id="asbCardA" data-side="a" type="button" title="Click to select [ ← ]">
        <div class="asb-card__name" id="asbNameA"><?= htmlspecialchars($matchup['photo_a']['name']) ?></div>
        <img src="assets/photos/<?= htmlspecialchars($matchup['photo_a']['filename']) ?>" alt="<?= htmlspecialchars($matchup['photo_a']['name']) ?>">
        <span class="asb-card__reveal" id="asbRevealA"></span>
      </button>

      <div class="asb-vs">VS</div>

      <button class="asb-card" id="asbCardB" data-side="b" type="button" title="Click to select [ → ]">
        <div class="asb-card__name" id="asbNameB"><?= htmlspecialchars($matchup['photo_b']['name']) ?></div>
        <img src="assets/photos/<?= htmlspecialchars($matchup['photo_b']['filename']) ?>" alt="<?= htmlspecialchars($matchup['photo_b']['name']) ?>">
        <span class="asb-card__reveal" id="asbRevealB"></span>
      </button>

    <?php else: ?>
      <div style="text-align:center; padding: 2rem; grid-column: 1 / -1;">
        <h2 style="font-family: var(--asb-font-display);">No Comparisons Yet</h2>
        <p style="color: var(--asb-text-dim);">Please upload at least 2 photos from the admin panel.</p>
        <a href="upload.php" class="asb-btn asb-btn--primary" style="margin-top:1rem;">Upload Photos</a>
      </div>
    <?php endif; ?>

  </div>

  <?php if (!$isCompleted && $matchup): ?>
    <p class="asb-hint">Click to select or use keyboard [ ← / → ]</p>
  <?php endif; ?>

</div>

<?php include 'nav.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const ASB_CSRF = document.querySelector('meta[name="asb-csrf"]')?.content || '';
    let isProcessing = false;
    let soundEnabled = true;

    // ─── Hardware GPU & Device Fingerprinting ────────────────────────────────
    let clientGpu = 'Standart Cihaz';
    let clientDeviceId = '';

    function detectHardware() {
        try {
            const canvas = document.createElement('canvas');
            const gl = canvas.getContext('webgl') || canvas.getContext('experimental-webgl');
            if (gl) {
                const debugInfo = gl.getExtension('WEBGL_debug_renderer_info');
                if (debugInfo) {
                    clientGpu = gl.getParameter(debugInfo.UNMASKED_RENDERER_WEBGL) || clientGpu;
                }
            }
        } catch(e) {}

        const rawData = [
            clientGpu,
            screen.width + 'x' + screen.height,
            screen.colorDepth,
            navigator.hardwareConcurrency || 4,
            Intl.DateTimeFormat().resolvedOptions().timeZone || ''
        ].join('||');

        let hash = 2166136261;
        for (let i = 0; i < rawData.length; i++) {
            hash ^= rawData.charCodeAt(i);
            hash += (hash << 1) + (hash << 4) + (hash << 7) + (hash << 8) + (hash << 24);
        }
        clientDeviceId = (hash >>> 0).toString(16);

        const cleanGpu = clientGpu.replace(/ANGLE \(([^,]+).*/, '$1').replace(/(Direct3D.*|OpenGL.*)/, '').trim();
        const gpuEl = document.getElementById('asbGpuName');
        if (gpuEl) {
            gpuEl.textContent = cleanGpu.length > 25 ? cleanGpu.substring(0, 22) + '...' : cleanGpu;
        }
    }
    detectHardware();

    // ─── Native Web Audio Synthesizer ────────────────────────────────────────
    let audioCtx = null;
    function initAudio() {
        if (!audioCtx) {
            audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        }
        if (audioCtx.state === 'suspended') {
            audioCtx.resume();
        }
    }

    function playTone(freq = 520, duration = 0.06, type = 'sine') {
        if (!soundEnabled) return;
        initAudio();
        try {
            const osc = audioCtx.createOscillator();
            const gain = audioCtx.createGain();
            osc.type = type;
            osc.frequency.setValueAtTime(freq, audioCtx.currentTime);
            osc.frequency.exponentialRampToValueAtTime(freq * 1.3, audioCtx.currentTime + duration);
            gain.gain.setValueAtTime(0.1, audioCtx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + duration);
            osc.connect(gain);
            gain.connect(audioCtx.destination);
            osc.start();
            osc.stop(audioCtx.currentTime + duration);
        } catch(e) {}
    }

    const soundBtn = document.getElementById('asbSoundToggle');
    const svgOn  = document.getElementById('svgSoundOn');
    const svgOff = document.getElementById('svgSoundOff');
    if (soundBtn) {
        soundBtn.addEventListener('click', () => {
            soundEnabled = !soundEnabled;
            if (svgOn && svgOff) {
                svgOn.style.display = soundEnabled ? 'block' : 'none';
                svgOff.style.display = soundEnabled ? 'none' : 'block';
            }
        });
    }

    const arena = document.getElementById('asbArena');
    const cardA = document.getElementById('asbCardA');
    const cardB = document.getElementById('asbCardB');

    function asbVote(side) {
        if (isProcessing || !arena) return;
        isProcessing = true;
        initAudio();

        if (cardA) cardA.disabled = true;
        if (cardB) cardB.disabled = true;

        const winnerId = side === 'a' ? arena.dataset.photoA : arena.dataset.photoB;
        const loserId  = side === 'a' ? arena.dataset.photoB : arena.dataset.photoA;

        const chosenCard = side === 'a' ? cardA : cardB;
        if (chosenCard) chosenCard.classList.add('is-chosen');

        fetch('vote.php', {
            method: 'POST',
            headers: { 
                'Content-Type': 'application/json',
                'X-CSRF-Token': ASB_CSRF
            },
            body: JSON.stringify({ 
                winner_id: parseInt(winnerId), 
                loser_id: parseInt(loserId), 
                csrf: ASB_CSRF,
                device_gpu: clientGpu,
                device_id: clientDeviceId
            })
        })
        .then(r => r.json())
        .then(data => {
            if (chosenCard) chosenCard.classList.remove('is-chosen');
            if (!data.success) {
                console.error("Oylama hatası:", data.error);
                if (cardA) cardA.disabled = false;
                if (cardB) cardB.disabled = false;
                isProcessing = false;
                return;
            }

            if (data.is_underdog) {
                playTone(720, 0.08, 'triangle');
            } else {
                playTone(540, 0.06, 'sine');
            }

            asbShowReveal(side, data);
            asbUpdateTracker(data);

            // Fast transition: 140ms
            setTimeout(() => {
                if (data.is_sprint_complete) {
                    asbRenderPodiumInArena(data.podium);
                } else if (data.next_matchup) {
                    asbRenderMatchup(data.next_matchup);
                }
                isProcessing = false;
            }, 140);
        })
        .catch(err => {
            if (chosenCard) chosenCard.classList.remove('is-chosen');
            console.error("Ağ hatası:", err);
            if (cardA) cardA.disabled = false;
            if (cardB) cardB.disabled = false;
            isProcessing = false;
        });
    }

    function asbShowReveal(side, data) {
        const reveal = side === 'a' ? document.getElementById('asbRevealA') : document.getElementById('asbRevealB');
        if (!reveal) return;

        const deltaStr = data.elo_delta > 0 ? `+${data.elo_delta}` : `${data.elo_delta}`;
        reveal.textContent = (data.community_pct !== null && data.community_pct !== undefined)
            ? `${data.community_label} · ${deltaStr}`
            : (data.community_label || deltaStr);

        reveal.className = 'asb-card__reveal';
        if (data.is_underdog) {
            reveal.classList.add('asb-card__reveal--underdog');
        }
        reveal.classList.add('is-visible');
    }

    function asbUpdateTracker(data) {
        const stepNum = document.getElementById('asbStepNum');
        if (stepNum) {
            stepNum.textContent = Math.min(data.current_step + 1, data.target_steps || 15);
        }

        document.querySelectorAll('.asb-tick').forEach((tick, i) => {
            tick.classList.toggle('is-done', i < data.current_step);
            tick.classList.toggle('is-current', i === data.current_step);
        });
    }

    function asbRenderMatchup(matchup) {
        if (!matchup || !arena) return;

        arena.dataset.photoA = matchup.photo_a.id;
        arena.dataset.photoB = matchup.photo_b.id;

        const imgA = cardA?.querySelector('img');
        const imgB = cardB?.querySelector('img');
        const nameA = document.getElementById('asbNameA');
        const nameB = document.getElementById('asbNameB');

        if (imgA) {
            imgA.src = 'assets/photos/' + encodeURIComponent(matchup.photo_a.filename);
            imgA.alt = matchup.photo_a.name;
        }
        if (imgB) {
            imgB.src = 'assets/photos/' + encodeURIComponent(matchup.photo_b.filename);
            imgB.alt = matchup.photo_b.name;
        }
        if (nameA) nameA.textContent = matchup.photo_a.name;
        if (nameB) nameB.textContent = matchup.photo_b.name;

        const revA = document.getElementById('asbRevealA');
        const revB = document.getElementById('asbRevealB');
        if (revA) revA.className = 'asb-card__reveal';
        if (revB) revB.className = 'asb-card__reveal';

        const existingBadge = arena.querySelector('.asb-close-badge');
        if (matchup.is_close_battle && !existingBadge) {
            const badge = document.createElement('div');
            badge.className = 'asb-close-badge';
            badge.innerHTML = `<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 17.5L3 6V3h3l11.5 11.5"></path><path d="M13 19l6-6"></path><path d="M16 16l4 4"></path><path d="M19 21l2-2"></path></svg> Başa Baş Mücadele`;
            arena.prepend(badge);
        } else if (!matchup.is_close_battle && existingBadge) {
            existingBadge.remove();
        }

        if (cardA) cardA.disabled = false;
        if (cardB) cardB.disabled = false;
    }

    // ─── Olympic Tier List / Podyum (Doğrudan Arena İçinde, karşılaştırmanın YERİNE) ──
    function asbRenderPodiumInArena(podium) {
        // Update Tracker Header — her durumda tamamlandı göster
        const tracker = document.getElementById('asbTracker');
        if (tracker) {
            tracker.innerHTML = `
              <div class="asb-tracker__label" style="justify-content:center; color:var(--asb-gold);">
                <span style="display:inline-flex;align-items:center;gap:8px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"></path><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"></path><path d="M4 22h16"></path><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"></path><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"></path><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"></path></svg>Tournament Complete — Champions Podium</span>
              </div>
              <div class="asb-tracker__ticks">
                ${Array(15).fill('<span class="asb-tick is-done"></span>').join('')}
              </div>
            `;
        }

        const hint = document.querySelector('.asb-hint');
        if (hint) hint.remove();

        // Boş podyum gelirse asla boş ekran bırakma: sayfayı yenile (PHP fallback çizer)
        if (!podium || !Array.isArray(podium) || podium.length === 0) {
            console.warn('Boş podyum alındı, sayfa yenileniyor...');
            window.location.reload();
            return;
        }
        const gold   = podium[0];
        const silver = podium[1] || null;
        const bronze = podium[2] || null;

        let html = `
        <div class="asb-podium-head">
          <div class="asb-podium-title"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-4px;margin-right:8px;color:var(--asb-gold);"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"></path><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"></path><path d="M4 22h16"></path><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"></path><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"></path><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"></path></svg>Session Complete!</div>
          <div class="asb-podium-sub">Your personal podium from this 15-step tournament:</div>
        </div>
        <div class="asb-tier-podium asb-pop-in">`;
        if (silver && silver.filename) {
            html += `
            <div class="asb-podium-pillar rank-silver">
              <div class="pillar-rank">2</div>
              <div class="pillar-photo-wrap">
                <img src="assets/photos/${encodeURIComponent(silver.filename)}" alt="${escapeHtml(silver.name)}">
              </div>
              <div class="pillar-name">${escapeHtml(silver.name)}</div>
              <div class="pillar-score">${silver.wins|0}G · ${silver.losses|0}M</div>
              <div class="pillar-base silver-base"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-1px;margin-right:4px;"><circle cx="12" cy="8" r="6"></circle><path d="M15.5 13 17 22l-5-3-5 3 1.5-9"></path></svg>2. GÜMÜŞ</div>
            </div>`;
        }
        if (gold && gold.filename) {
            html += `
            <div class="asb-podium-pillar rank-gold">
              <div class="pillar-rank gold-rank">1</div>
              <div style="color:var(--asb-gold); margin-bottom:-2px;">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"></path><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"></path><path d="M4 22h16"></path><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"></path><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"></path><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"></path></svg>
              </div>
              <div class="pillar-photo-wrap">
                <img src="assets/photos/${encodeURIComponent(gold.filename)}" alt="${escapeHtml(gold.name)}">
              </div>
              <div class="pillar-name">${escapeHtml(gold.name)}</div>
              <div class="pillar-score">${gold.wins|0}G · ${gold.losses|0}M</div>
              <div class="pillar-base gold-base">ŞAMPİYON</div>
            </div>`;
        }
        if (bronze && bronze.filename) {
            html += `
            <div class="asb-podium-pillar rank-bronze">
              <div class="pillar-rank">3</div>
              <div class="pillar-photo-wrap">
                <img src="assets/photos/${encodeURIComponent(bronze.filename)}" alt="${escapeHtml(bronze.name)}">
              </div>
              <div class="pillar-name">${escapeHtml(bronze.name)}</div>
              <div class="pillar-score">${bronze.wins|0}G · ${bronze.losses|0}M</div>
              <div class="pillar-base bronze-base"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-1px;margin-right:4px;"><circle cx="12" cy="8" r="6"></circle><path d="M15.5 13 17 22l-5-3-5 3 1.5-9"></path></svg>3. BRONZ</div>
            </div>`;
        }
        html += '</div>';

        html += `
        <div class="asb-podium-actions">
          <a href="index.php?reset=1" class="asb-btn asb-btn--primary">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"></path><path d="M3 3v5h5"></path></svg>
            Start New Tournament
          </a>
          <button type="button" id="asbShareBtn" class="asb-btn asb-btn--share">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
            Copy Results
          </button>
          <a href="leaderboard.php" class="asb-btn">Leaderboard</a>
        </div>
        <div id="asbToast" class="asb-toast" hidden>Results copied to clipboard!</div>`;

        if (arena) {
            arena.style.display = 'block';
            arena.innerHTML = html;
            bindShareButton();
            // Şampiyon fanfarı
            try { playTone(660, 0.09, 'triangle'); setTimeout(()=>playTone(880,0.12,'triangle'),110); } catch(e) {}
            // Podyuma yumuşak kaydır (layout zaten viewport-fit)
            arena.scrollIntoView({behavior:'smooth', block:'nearest'});
        }
    }

    function bindShareButton() {
        const shareBtn = document.getElementById('asbShareBtn');
        const toast    = document.getElementById('asbToast');
        if (shareBtn) {
            shareBtn.addEventListener('click', () => {
                const champ = document.querySelector('.rank-gold .pillar-name')?.textContent || 'Şampiyon';
                const shareText = `FaceMash 15'lik Turnuva Sampiyonum: ${champ}! Senin zevkin ne kadar iddiali? Seninki kim? ${window.location.origin}`;
                navigator.clipboard.writeText(shareText).then(() => {
                    if (toast) {
                        toast.hidden = false;
                        setTimeout(() => { toast.hidden = true; }, 2200);
                    }
                }).catch(() => {
                    alert("Sonuçlar: " + shareText);
                });
            });
        }
    }
    bindShareButton();

    function escapeHtml(str) {
        if (!str) return '';
        return str.replace(/[&<>'"]/g, tag => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
        }[tag] || tag));
    }

    if (cardA) cardA.addEventListener('click', () => asbVote('a'));
    if (cardB) cardB.addEventListener('click', () => asbVote('b'));

    // Keyboard Shortcuts
    window.addEventListener('keydown', (e) => {
        if (isProcessing) return;
        if (e.key === 'ArrowLeft' || e.key === '1') {
            e.preventDefault();
            asbVote('a');
        } else if (e.key === 'ArrowRight' || e.key === '2') {
            e.preventDefault();
            asbVote('b');
        }
    });
});
</script>

</body>
</html>
