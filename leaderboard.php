<?php
session_start();
include 'config.php';
include 'matchup_logic.php';

// Initial fetch sorted by rating, wins, and id
$result = $conn->query("
    SELECT id, filename, wins, losses, rating,
           ROUND((wins / NULLIF(wins + losses, 0)) * 100, 1) AS win_rate
    FROM photos 
    ORDER BY rating DESC, wins DESC, id ASC 
    LIMIT 25
");

$topPhotos = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $row['rating'] = (int)round($row['rating']);
        $row['win_rate'] = $row['win_rate'] !== null ? (float)$row['win_rate'] : 0.0;
        $row['displayName'] = format_display_name($row['filename']);
        $topPhotos[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>FaceMash — Live Leaderboard</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/styles.css">
  <link rel="stylesheet" href="assets/styles_asb.css">
  <!-- Pusher 0ms Real-Time WebSocket Client -->
  <script src="https://js.pusher.com/8.4.0/pusher.min.js"></script>
  <style>
    body.asb-body {
      padding: 2.5rem 1rem;
    }
    .leaderboard-header {
      text-align: center;
      margin-bottom: 2rem;
    }
    .leaderboard-header h1 {
      font-family: var(--asb-font-display);
      font-size: 2rem;
      color: var(--asb-text);
      margin: 0 0 0.5rem 0;
    }
    .live-indicator {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      font-size: 0.85rem;
      color: var(--asb-mint);
      font-weight: 700;
      background: rgba(47, 184, 147, 0.12);
      padding: 5px 14px;
      border-radius: 999px;
      border: 1px solid rgba(47, 184, 147, 0.25);
      box-shadow: 0 2px 10px rgba(47, 184, 147, 0.15);
    }
    .live-dot {
      width: 8px;
      height: 8px;
      background: var(--asb-mint);
      border-radius: 50%;
      box-shadow: 0 0 8px var(--asb-mint);
      animation: pulse 1.5s infinite;
    }
    @keyframes pulse {
      0% { opacity: 1; transform: scale(1); }
      50% { opacity: 0.4; transform: scale(1.2); }
      100% { opacity: 1; transform: scale(1); }
    }
    .table-container {
      width: 100%;
      max-width: 960px;
      background: var(--asb-surface);
      border-radius: 16px;
      box-shadow: 0 8px 32px rgba(0, 0, 0, 0.4);
      border: 1px solid var(--asb-surface-2);
      overflow: hidden;
      margin-bottom: 2rem;
    }
    table {
      width: 100%;
      border-collapse: collapse;
      text-align: left;
    }
    th {
      background: var(--asb-surface-2);
      color: var(--asb-text-dim);
      font-family: var(--asb-font-display);
      font-size: 0.8rem;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      padding: 1rem 1.25rem;
      border-bottom: 1px solid rgba(255, 255, 255, 0.06);
      font-weight: 700;
    }
    td {
      padding: 1rem 1.25rem;
      border-bottom: 1px solid rgba(255, 255, 255, 0.04);
      vertical-align: middle;
      font-size: 0.95rem;
      color: var(--asb-text);
    }
    tbody tr {
      transition: background-color 0.15s ease;
    }
    tbody tr:hover {
      background-color: var(--asb-surface-2);
    }
    .rank-cell {
      font-weight: 700;
      width: 60px;
      text-align: center;
    }
    .rank-badge {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 30px;
      height: 30px;
      border-radius: 50%;
      font-weight: 700;
      font-size: 0.85rem;
      font-family: var(--asb-font-display);
    }
    .rank-1 { background: rgba(232, 169, 60, 0.2); color: var(--asb-gold); border: 1px solid rgba(232, 169, 60, 0.4); box-shadow: 0 0 10px rgba(232, 169, 60, 0.25); }
    .rank-2 { background: rgba(255, 255, 255, 0.1); color: #e2e8f0; border: 1px solid rgba(255, 255, 255, 0.2); }
    .rank-3 { background: rgba(249, 115, 22, 0.2); color: #fb923c; border: 1px solid rgba(249, 115, 22, 0.35); }
    .photo-thumb {
      width: 48px;
      height: 48px;
      border-radius: 10px;
      object-fit: cover;
      box-shadow: 0 2px 8px rgba(0,0,0,0.3);
    }
    .name-cell {
      font-weight: 600;
      color: var(--asb-text);
    }
    .elo-cell {
      font-family: var(--asb-font-display);
      font-weight: 700;
      color: var(--asb-gold);
      font-size: 1.1rem;
    }
    .win-bar-wrap {
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .win-bar {
      flex: 1;
      height: 6px;
      background: var(--asb-surface-2);
      border-radius: 999px;
      overflow: hidden;
      min-width: 60px;
    }
    .win-bar-fill {
      height: 100%;
      background: var(--asb-mint);
      border-radius: 999px;
      box-shadow: 0 0 6px rgba(47, 184, 147, 0.4);
    }
    .rank-shift {
      font-size: 0.75rem;
      margin-left: 4px;
      font-weight: 700;
    }
    .rank-shift.up { color: var(--asb-mint); }
    .rank-shift.down { color: var(--asb-crimson); }

    /* Mobile responsive improvements */
    @media (max-width: 640px) {
      .table-container {
        border-radius: 12px;
      }
      th, td {
        padding: 0.75rem 0.5rem;
        font-size: 0.85rem;
      }
      .photo-thumb {
        width: 40px;
        height: 40px;
      }
      .rank-badge {
        width: 26px;
        height: 26px;
        font-size: 0.75rem;
      }
      .elo-cell {
        font-size: 0.95rem;
      }
      .win-bar {
        min-width: 40px;
      }
      .win-bar-wrap {
        gap: 6px;
      }
      .rank-cell {
        width: 45px;
      }
      th:nth-child(5), 
      td:nth-child(5) {
        display: none; /* Hide win rate percentage on mobile */
      }
    }
  </style>
</head>
<body class="asb-body">

<div class="leaderboard-header">
  <h1>Live Leaderboard</h1>
  <div class="live-indicator">
    <span class="live-dot"></span> Live 0 ms WebSocket (Pusher) Active
  </div>
</div>

<div class="table-container">
  <table>
    <thead>
      <tr>
        <th class="rank-cell">Rank</th>
        <th>Image</th>
        <th>Name</th>
        <th>Elo Score</th>
        <th>Win Rate</th>
        <th>W / L</th>
      </tr>
    </thead>
    <tbody id="leaderboard-tbody">
      <?php if (empty($topPhotos)): ?>
        <tr><td colspan="6" style="text-align:center; padding: 2.5rem; color:var(--asb-text-dim);">No registered images found yet.</td></tr>
      <?php else: ?>
        <?php foreach ($topPhotos as $index => $photo): ?>
          <?php 
            $rank = $index + 1;
            $badgeClass = $rank === 1 ? 'rank-1' : ($rank === 2 ? 'rank-2' : ($rank === 3 ? 'rank-3' : ''));
          ?>
          <tr data-id="<?= $photo['id'] ?>">
            <td class="rank-cell">
              <?php if ($badgeClass): ?>
                <span class="rank-badge <?= $badgeClass ?>"><?= $rank ?></span>
              <?php else: ?>
                <?= $rank ?>
              <?php endif; ?>
            </td>
            <td>
              <img class="photo-thumb" src="assets/photos/<?= htmlspecialchars($photo['filename']) ?>" alt="<?= htmlspecialchars($photo['displayName']) ?>">
            </td>
            <td class="name-cell"><?= htmlspecialchars($photo['displayName']) ?></td>
            <td class="elo-cell"><?= $photo['rating'] ?></td>
            <td>
              <div class="win-bar-wrap">
                <div class="win-bar">
                  <div class="win-bar-fill" style="width: <?= $photo['win_rate'] ?>%;"></div>
                </div>
                <span style="font-family: var(--asb-font-display); font-size:0.88rem; color:var(--asb-text-dim);">%<?= $photo['win_rate'] ?></span>
              </div>
            </td>
            <td style="color:var(--asb-text-dim);"><?= $photo['wins'] ?> / <?= $photo['losses'] ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php include 'nav.php'; ?>

<footer style="margin-top:2rem; color:var(--asb-text-dim); font-size:0.85rem;">
  &copy; <?= date('Y') ?> FaceMash — Elo Ranking System
</footer>

<script>
document.addEventListener('DOMContentLoaded', () => {
    let previousRanks = new Map();
    const tbody = document.getElementById('leaderboard-tbody');

    // Populate initial rank mapping
    tbody.querySelectorAll('tr[data-id]').forEach((row, index) => {
        previousRanks.set(row.dataset.id, index);
    });

    // ─── 0ms Pusher WebSocket Connection ──────────────────────────────────────
    const pusher = new Pusher('f92b8b23f9eb68b2f1ba', {
        cluster: 'eu'
    });

    const channel = pusher.subscribe('facemash');
    channel.bind('leaderboard-update', (data) => {
        if (Array.isArray(data)) {
            updateWithFLIP(data);
        }
    });

    function updateWithFLIP(newData) {
        // F - First: Record old positions
        const oldRects = new Map();
        tbody.querySelectorAll('tr[data-id]').forEach(row => {
            oldRects.set(row.dataset.id, row.getBoundingClientRect());
        });

        // L - Last: Render new DOM
        renderTable(newData);

        // I - Invert & P - Play
        tbody.querySelectorAll('tr[data-id]').forEach(row => {
            const id = row.dataset.id;
            const oldRect = oldRects.get(id);

            if (oldRect) {
                const newRect = row.getBoundingClientRect();
                const deltaY = oldRect.top - newRect.top;

                if (deltaY !== 0) {
                    row.style.transition = 'none';
                    row.style.transform = `translateY(${deltaY}px)`;
                    
                    // Force Reflow
                    void row.offsetHeight;

                    row.style.transition = 'transform 0.5s cubic-bezier(0.2, 0.8, 0.2, 1)';
                    row.style.transform = '';
                }
            }
        });
    }

    function renderTable(data) {
        if (!data || data.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; padding: 2.5rem; color:var(--asb-text-dim);">No registered images found yet.</td></tr>';
            return;
        }

        const newRanks = new Map();
        data.forEach((item, index) => {
            newRanks.set(String(item.id), index);
        });

        let html = '';
        data.forEach((photo, index) => {
            const rank = index + 1;
            const badgeClass = rank === 1 ? 'rank-1' : (rank === 2 ? 'rank-2' : (rank === 3 ? 'rank-3' : ''));
            
            const oldRank = previousRanks.get(String(photo.id));
            let shiftHtml = '';
            if (oldRank !== undefined && oldRank !== index) {
                if (index < oldRank) {
                    shiftHtml = `<span class="rank-shift up">▲ ${oldRank - index}</span>`;
                } else {
                    shiftHtml = `<span class="rank-shift down">▼ ${index - oldRank}</span>`;
                }
            }

            const rankDisplay = badgeClass 
                ? `<span class="rank-badge ${badgeClass}">${rank}</span>` 
                : `${rank}`;

            html += `
              <tr data-id="${photo.id}">
                <td class="rank-cell">${rankDisplay} ${shiftHtml}</td>
                <td>
                  <img class="photo-thumb" src="assets/photos/${encodeURIComponent(photo.filename)}" alt="${escapeHtml(photo.displayName)}">
                </td>
                <td class="name-cell">${escapeHtml(photo.displayName)}</td>
                <td class="elo-cell">${photo.rating}</td>
                <td>
                  <div class="win-bar-wrap">
                    <div class="win-bar">
                      <div class="win-bar-fill" style="width: ${photo.win_rate}%;"></div>
                    </div>
                    <span style="font-family: var(--asb-font-display); font-size:0.88rem; color:var(--asb-text-dim);">%${photo.win_rate}</span>
                  </div>
                </td>
                <td style="color:var(--asb-text-dim);">${photo.wins} / ${photo.losses}</td>
              </tr>
            `;
        });

        tbody.innerHTML = html;
        previousRanks = newRanks;
    }

    function escapeHtml(str) {
        if (!str) return '';
        return str.replace(/[&<>'"]/g, tag => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
        }[tag] || tag));
    }
});
</script>

</body>
</html>
