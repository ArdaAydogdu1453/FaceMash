<?php
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

require 'config.php';
require 'matchup_logic.php';

// Strict Admin Security Check & Session Hijacking Defense
if (empty($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    header('Location: login.php');
    exit;
}

// Fingerprint check (IP binding)
if (isset($_SESSION['admin_ip']) && $_SESSION['admin_ip'] !== $_SERVER['REMOTE_ADDR']) {
    session_destroy();
    header('Location: login.php?error=hijack_detected');
    exit;
}

// Compute Statistics
$statsQuery = $conn->query("
    SELECT COUNT(*) as total_photos, 
           COALESCE(SUM(wins), 0) as total_votes,
           COALESCE(MAX(rating), 1000) as max_rating,
           COALESCE(MIN(rating), 1000) as min_rating
    FROM photos
");
$stats = $statsQuery->fetch_assoc();

// List all candidates sorted by Elo desc
$result = $conn->query("SELECT * FROM photos ORDER BY rating DESC, wins DESC, id ASC");
?>
<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>FaceMash — Yönetim Paneli</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/styles.css">
  <link rel="stylesheet" href="assets/styles_asb.css">
  <style>
    body.asb-body {
      padding: 2rem 1.25rem;
    }
    .admin-wrapper {
      width: 100%;
      max-width: 1050px;
      margin: 0 auto;
    }
    .admin-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 2rem;
      border-bottom: 1px solid var(--asb-surface-2);
      padding-bottom: 1.25rem;
    }
    .admin-header h1 {
      font-family: var(--asb-font-display);
      font-size: 2rem;
      margin: 0;
      color: var(--asb-text);
      text-align: left;
    }
    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
      gap: 1.25rem;
      margin-bottom: 2rem;
    }
    .stat-card {
      background: var(--asb-surface) !important;
      border-radius: 14px;
      padding: 1.5rem;
      box-shadow: 0 4px 20px rgba(0,0,0,0.3) !important;
      border: 1px solid var(--asb-surface-2) !important;
    }
    .stat-label {
      font-family: var(--asb-font-display);
      font-size: 0.85rem;
      color: var(--asb-text-dim) !important;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.05em;
    }
    .stat-val {
      font-family: var(--asb-font-display);
      font-size: 2.1rem;
      font-weight: 700;
      color: #ffffff !important;
      margin-top: 0.4rem;
    }
    .stat-val.gold {
      color: var(--asb-gold) !important;
    }
    .panel-card {
      background: var(--asb-surface);
      border-radius: 16px;
      padding: 1.75rem;
      box-shadow: 0 8px 30px rgba(0, 0, 0, 0.4);
      border: 1px solid var(--asb-surface-2);
      margin-bottom: 2.5rem;
    }
    .toolbar {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 1.5rem;
      flex-wrap: wrap;
      gap: 1rem;
    }
    .toolbar h2 {
      font-family: var(--asb-font-display);
      color: var(--asb-text);
      margin: 0;
      font-size: 1.3rem;
    }
    .toolbar-actions {
      display: flex;
      gap: 0.75rem;
    }
    .btn {
      padding: 0.65rem 1.25rem;
      border-radius: 10px;
      font-family: var(--asb-font-display);
      font-weight: 700;
      font-size: 0.88rem;
      cursor: pointer;
      border: none;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      transition: all 0.2s ease;
    }
    .btn-upload {
      background: var(--asb-gold);
      color: var(--asb-ink);
    }
    .btn-upload:hover {
      background: #f2ba52;
      transform: translateY(-2px);
    }
    .btn-warning {
      background: rgba(232, 169, 60, 0.15);
      border: 1px solid var(--asb-gold);
      color: var(--asb-gold);
    }
    .btn-warning:hover {
      background: var(--asb-gold);
      color: var(--asb-ink);
    }
    .btn-danger {
      background: rgba(226, 61, 69, 0.15);
      border: 1px solid var(--asb-crimson);
      color: #fca5a5;
    }
    .btn-danger:hover {
      background: var(--asb-crimson);
      color: #fff;
    }
    .btn-sm-delete {
      background: rgba(226, 61, 69, 0.15);
      color: #fca5a5;
      padding: 0.35rem 0.85rem;
      border-radius: 8px;
      font-size: 0.8rem;
      font-weight: 600;
      border: 1px solid rgba(226, 61, 69, 0.3);
      cursor: pointer;
      transition: all 0.2s;
    }
    .btn-sm-delete:hover {
      background: var(--asb-crimson);
      color: #fff;
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
      padding: 0.9rem 1rem;
      border-bottom: 1px solid rgba(255, 255, 255, 0.08);
      font-weight: 700;
    }
    td {
      padding: 0.9rem 1rem;
      border-bottom: 1px solid rgba(255, 255, 255, 0.04);
      vertical-align: middle;
      font-size: 0.92rem;
      color: var(--asb-text);
    }
    tbody tr:hover {
      background-color: var(--asb-surface-2);
    }
    .thumb {
      width: 48px;
      height: 48px;
      border-radius: 8px;
      object-fit: cover;
      box-shadow: 0 2px 8px rgba(0,0,0,0.3);
    }
    .candidate-title {
      font-weight: 700;
      color: #ffffff;
      font-size: 0.95rem;
    }
    .candidate-file {
      font-size: 0.75rem;
      color: var(--asb-text-dim);
    }
    .elo-val {
      font-family: var(--asb-font-display);
      font-weight: 700;
      color: var(--asb-gold);
      font-size: 1.05rem;
    }
  </style>
</head>
<body class="asb-body">

<div class="admin-wrapper">
  <div class="admin-header">
    <h1>Yönetim Kontrol Paneli</h1>
    <a href="logout.php" class="btn btn-danger">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
      Güvenli Çıkış
    </a>
  </div>

  <div class="stats-grid">
    <div class="stat-card">
      <div class="stat-label">Toplam Görsel</div>
      <div class="stat-val"><?= $stats['total_photos'] ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Toplam Oynanan Maç</div>
      <div class="stat-val"><?= $stats['total_votes'] ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">En Yüksek Elo</div>
      <div class="stat-val gold"><?= round($stats['max_rating']) ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">En Düşük Elo</div>
      <div class="stat-val" style="color: var(--asb-text-dim) !important;"><?= round($stats['min_rating']) ?></div>
    </div>
  </div>

  <div class="panel-card">
    <div class="toolbar">
      <h2>Fotoğraf Havuzu ve Performans Sıralaması</h2>
      <div class="toolbar-actions">
        <a href="upload.php" class="btn btn-upload">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
          Yeni Görseller Yükle
        </a>
        <form action="reset_ranks.php" method="post" onsubmit="return confirm('Tüm fotoğrafların Elo puanları 1000 olarak sıfırlanacak. Onaylıyor musunuz?');" style="margin: 0;">
          <button type="submit" class="btn btn-warning">Puanları Sıfırla</button>
        </form>
      </div>
    </div>

    <form action="bulk_delete.php" method="post" id="bulk-delete-form" onsubmit="return confirm('Seçilen tüm fotoğrafları kalıcı olarak silmek istediğinizden emin misiniz?');">
      <table>
        <thead>
          <tr>
            <th style="width: 40px; text-align: center;"><input type="checkbox" id="select-all"></th>
            <th>ID</th>
            <th>Önizleme</th>
            <th>Dosya / Görünen İsim</th>
            <th>Elo Skoru</th>
            <th>G</th>
            <th>M</th>
            <th style="text-align: right;">İşlem</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($result->num_rows === 0): ?>
            <tr><td colspan="8" style="text-align: center; padding: 2.5rem; color: var(--asb-text-dim);">Veritabanında kayıtlı fotoğraf bulunamadı.</td></tr>
          <?php else: ?>
            <?php while ($row = $result->fetch_assoc()): ?>
              <tr>
                <td style="text-align: center;">
                  <input type="checkbox" name="photo_ids[]" value="<?= $row['id'] ?>">
                </td>
                <td style="font-weight: 600; color: var(--asb-text-dim);">#<?= $row['id'] ?></td>
                <td>
                  <img class="thumb" src="assets/photos/<?= htmlspecialchars($row['filename']) ?>" alt="Fotoğraf">
                </td>
                <td>
                  <div class="candidate-title"><?= htmlspecialchars(format_display_name($row['filename'])) ?></div>
                  <div class="candidate-file"><?= htmlspecialchars($row['filename']) ?></div>
                </td>
                <td class="elo-val"><?= round($row['rating']) ?></td>
                <td style="color: var(--asb-mint); font-weight: 600;"><?= $row['wins'] ?></td>
                <td style="color: var(--asb-crimson); font-weight: 600;"><?= $row['losses'] ?></td>
                <td style="text-align: right;">
                  <button type="button" class="btn-sm-delete" onclick="deleteSinglePhoto(<?= $row['id'] ?>)">Sil</button>
                </td>
              </tr>
            <?php endwhile; ?>
          <?php endif; ?>
        </tbody>
      </table>

      <?php if ($result->num_rows > 0): ?>
        <div style="margin-top: 1.5rem;">
          <button type="submit" class="btn btn-danger">Seçilenleri Toplu Sil</button>
        </div>
      <?php endif; ?>
    </form>
  </div>
</div>

<!-- Gizli tekli silme formu -->
<form id="single-delete-form" action="delete_photo.php" method="post" style="display:none;">
  <input type="hidden" name="photo_id" id="single-delete-id">
</form>

<?php include 'nav.php'; ?>

<footer style="margin-top:2rem; color:var(--asb-text-dim); font-size:0.85rem;">
  &copy; <?= date('Y') ?> FaceMash — Admin Yönetim Paneli
</footer>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const selectAll = document.getElementById('select-all');
    const checkboxes = document.querySelectorAll('input[name="photo_ids[]"]');

    if (selectAll) {
        selectAll.addEventListener('change', (e) => {
            checkboxes.forEach(cb => cb.checked = e.target.checked);
        });
    }
});

function deleteSinglePhoto(id) {
    if (confirm('Bu görseli silmek istediğinizden emin misiniz?')) {
        const form = document.getElementById('single-delete-form');
        document.getElementById('single-delete-id').value = id;
        form.submit();
    }
}
</script>

</body>
</html>