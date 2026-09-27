<?php
session_start();
require 'config.php';
require 'matchup_logic.php';

// Güvenlik: Yalnızca yetkili yönetici görsel yükleyebilir
if (!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    header('Location: login.php');
    exit;
}

// Dosya adını güvenli ve web dostu hale getiren fonksiyon
function sanitize_filename($rawName) {
    $info = pathinfo($rawName);
    $ext = strtolower($info['extension'] ?? '');
    $base = $info['filename'];

    // Türkçe karakterleri dönüştür
    $tr = ['ç'=>'c', 'ğ'=>'g', 'ı'=>'i', 'ö'=>'o', 'ş'=>'s', 'ü'=>'u',
           'Ç'=>'c', 'Ğ'=>'g', 'İ'=>'i', 'Ö'=>'o', 'Ş'=>'s', 'Ü'=>'u'];
    $base = strtr($base, $tr);

    // Yalnızca harf, rakam ve tire bırak
    $base = preg_replace('/[^a-zA-Z0-9]+/', '-', $base);
    $base = trim($base, '-');
    if (empty($base)) {
        $base = 'photo_' . time();
    }

    return $base . '.' . $ext;
}

// Upload endpoint işleme
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['photos'])) {
    $uploadedCount = 0;
    $errors = [];
    $allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    $targetDir = __DIR__ . '/assets/photos/';

    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0777, true);
    }

    $files = $_FILES['photos'];
    $totalFiles = is_array($files['name']) ? count($files['name']) : 0;

    for ($i = 0; $i < $totalFiles; $i++) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK) {
            continue;
        }

        $origName = $files['name'][$i];
        $tmpName = $files['tmp_name'][$i];
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        if (!in_array($ext, $allowedExts)) {
            $errors[] = "$origName: Desteklenmeyen dosya formatı.";
            continue;
        }

        $safeName = sanitize_filename($origName);
        $destination = $targetDir . $safeName;

        // Dosya adı çakışmasını önle
        if (file_exists($destination)) {
            $baseName = pathinfo($safeName, PATHINFO_FILENAME);
            $safeName = $baseName . '_' . substr(uniqid(), -5) . '.' . $ext;
            $destination = $targetDir . $safeName;
        }

        if (move_uploaded_file($tmpName, $destination)) {
            $stmt = $conn->prepare("INSERT INTO photos (filename, wins, losses, rating) VALUES (?, 0, 0, 1000)");
            $stmt->bind_param("s", $safeName);
            $stmt->execute();
            $stmt->close();
            $uploadedCount++;
        } else {
            $errors[] = "$origName yüklenemedi.";
        }
    }

    // AJAX isteği ise JSON döndür
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => $uploadedCount > 0,
            'uploaded' => $uploadedCount,
            'errors' => $errors
        ]);
        exit;
    }

    $message = "$uploadedCount görsel başarıyla yüklendi.";
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/styles.css">
  <title>FaceMash — Görsel Yükle</title>
  <style>
    /* ASB Dark Upload Theme */
    body.asb-body { align-items: center; justify-content: flex-start; padding-top: 2rem; }
    h1, p { text-align: center; }
    .upload-container {
      width: 100%;
      max-width: 650px;
      background: var(--asb-surface, #1b1d26);
      border-radius: 14px;
      padding: 2.5rem;
      box-shadow: 0 4px 24px rgba(0,0,0,0.5);
      border: 1px solid var(--asb-surface-2, #242733);
      margin-bottom: 2rem;
    }
    .drop-zone {
      border: 2px dashed var(--asb-surface-2, #242733);
      border-radius: 10px;
      padding: 2.5rem 1.5rem;
      text-align: center;
      background: var(--asb-ink, #12131a);
      cursor: pointer;
      transition: all 0.2s ease;
      position: relative;
    }
    .drop-zone:hover, .drop-zone.dragover {
      border-color: var(--asb-gold, #e8a93c);
      background: rgba(232, 169, 60, 0.05);
    }
    .drop-zone input[type="file"] {
      position: absolute;
      top: 0; left: 0; width: 100%; height: 100%;
      opacity: 0;
      cursor: pointer;
    }
    .drop-icon {
      color: var(--asb-gold, #e8a93c);
      margin-bottom: 0.75rem;
    }
    .drop-title {
      font-size: 1.1rem;
      font-weight: 600;
      color: var(--asb-text, #f2f3f6);
      margin-bottom: 0.25rem;
      font-family: var(--asb-font-display, 'Space Grotesk', sans-serif);
    }
    .drop-sub {
      font-size: 0.85rem;
      color: var(--asb-text-dim, #9a9db0);
    }
    .preview-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(90px, 1fr));
      gap: 12px;
      margin-top: 1.5rem;
      max-height: 260px;
      overflow-y: auto;
      padding-right: 4px;
    }
    .preview-card {
      position: relative;
      border-radius: 8px;
      overflow: hidden;
      box-shadow: 0 2px 8px rgba(0,0,0,0.4);
      aspect-ratio: 1;
      background: var(--asb-surface-2, #242733);
      border: 1px solid var(--asb-surface-2, #242733);
    }
    .preview-card img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }
    .preview-name {
      position: absolute;
      bottom: 0; left: 0; right: 0;
      background: rgba(0,0,0,0.75);
      color: #ffffff;
      font-size: 0.65rem;
      padding: 2px 4px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      text-align: center;
    }
    .progress-bar-wrap {
      margin-top: 1.5rem;
      display: none;
    }
    .progress-bar {
      height: 8px;
      background: var(--asb-surface-2, #242733);
      border-radius: 999px;
      overflow: hidden;
    }
    .progress-bar-fill {
      height: 100%;
      width: 0%;
      background: var(--asb-mint, #2fb893);
      transition: width 0.2s ease;
    }
    #progress-text {
      font-size: 0.8rem;
      color: var(--asb-text-dim, #9a9db0);
      text-align: center;
      margin-top: 4px;
    }
    .upload-btn {
      width: 100%;
      margin-top: 1.5rem;
      padding: 0.85rem;
      background: var(--asb-gold, #e8a93c);
      color: var(--asb-ink, #12131a);
      font-size: 1rem;
      font-weight: 700;
      border: none;
      border-radius: 10px;
      cursor: pointer;
      transition: background 0.2s, transform 0.15s;
      font-family: var(--asb-font-display, 'Space Grotesk', sans-serif);
    }
    .upload-btn:hover {
      background: #f2ba52;
      transform: translateY(-2px);
    }
    .upload-btn:disabled {
      background: var(--asb-surface-2, #242733);
      color: var(--asb-text-dim, #9a9db0);
      cursor: not-allowed;
      transform: none;
    }
    .status-msg {
      margin-top: 1rem;
      padding: 0.75rem 1rem;
      border-radius: 8px;
      font-size: 0.9rem;
      text-align: center;
      display: none;
      font-weight: 600;
    }
    .status-success {
      background: rgba(47, 184, 147, 0.15);
      color: var(--asb-mint, #2fb893);
      border: 1px solid rgba(47, 184, 147, 0.35);
    }
    .status-error {
      background: rgba(226, 61, 69, 0.15);
      color: var(--asb-crimson, #e23d45);
      border: 1px solid rgba(226, 61, 69, 0.35);
    }
  </style>
</head>
<body class="asb-body">

<h1 style="font-family: var(--asb-font-display); margin-bottom: 0.25rem;">Görsel Yükle</h1>
<p style="color: var(--asb-text-dim); margin-top: 0; margin-bottom: 2rem;">Ölçeklenebilir yükleme: Alt veya üst sınır yoktur.</p>

<div class="upload-container">
  <form id="upload-form" action="upload.php" method="post" enctype="multipart/form-data">
    <div class="drop-zone" id="drop-zone">
      <div class="drop-icon">
        <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
      </div>
      <div class="drop-title">Görselleri buraya sürükleyip bırakın</div>
      <div class="drop-sub">veya bilgisayarınızdan seçmek için tıklayın</div>
      <input type="file" name="photos[]" id="file-input" multiple accept="image/*">
    </div>

    <div class="preview-grid" id="preview-grid"></div>

    <div class="progress-bar-wrap" id="progress-wrap">
      <div class="progress-bar">
        <div class="progress-bar-fill" id="progress-fill"></div>
      </div>
      <div style="font-size: 0.8rem; color: #64748b; text-align: center; margin-top: 4px;" id="progress-text">Yükleniyor: %0</div>
    </div>

    <div class="status-msg" id="status-msg"></div>

    <button type="submit" class="upload-btn" id="submit-btn" disabled>Görselleri Yükle</button>
  </form>
</div>

<?php include 'nav.php'; ?>

<footer>
  &copy; <?= date('Y') ?> FaceMash — Admin Yönetim Paneli
</footer>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const dropZone = document.getElementById('drop-zone');
    const fileInput = document.getElementById('file-input');
    const previewGrid = document.getElementById('preview-grid');
    const submitBtn = document.getElementById('submit-btn');
    const form = document.getElementById('upload-form');
    const progressWrap = document.getElementById('progress-wrap');
    const progressFill = document.getElementById('progress-fill');
    const progressText = document.getElementById('progress-text');
    const statusMsg = document.getElementById('status-msg');

    let selectedFiles = [];

    // Drag over styling
    ['dragenter', 'dragover'].forEach(eventName => {
        dropZone.addEventListener(eventName, (e) => {
            e.preventDefault();
            e.stopPropagation();
            dropZone.classList.add('dragover');
        });
    });

    ['dragleave', 'drop'].forEach(eventName => {
        dropZone.addEventListener(eventName, (e) => {
            e.preventDefault();
            e.stopPropagation();
            dropZone.classList.remove('dragover');
        });
    });

    // Handle Drop
    dropZone.addEventListener('drop', (e) => {
        const files = Array.from(e.dataTransfer.files).filter(f => f.type.startsWith('image/'));
        addFiles(files);
    });

    // Handle File Input Change
    fileInput.addEventListener('change', () => {
        const files = Array.from(fileInput.files);
        addFiles(files);
    });

    function addFiles(files) {
        files.forEach(file => {
            // Avoid exact name and size duplicate
            if (!selectedFiles.some(f => f.name === file.name && f.size === file.size)) {
                selectedFiles.push(file);
            }
        });
        renderPreviews();
    }

    function renderPreviews() {
        previewGrid.innerHTML = '';
        if (selectedFiles.length === 0) {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Görselleri Yükle';
            return;
        }

        submitBtn.disabled = false;
        submitBtn.textContent = `${selectedFiles.length} Görseli Yükle`;

        selectedFiles.forEach((file, index) => {
            const reader = new FileReader();
            const card = document.createElement('div');
            card.className = 'preview-card';

            const name = document.createElement('div');
            name.className = 'preview-name';
            name.textContent = file.name;

            reader.onload = (e) => {
                const img = document.createElement('img');
                img.src = e.target.result;
                card.appendChild(img);
                card.appendChild(name);
            };
            reader.readAsDataURL(file);

            previewGrid.appendChild(card);
        });
    }

    // AJAX Form Upload with Progress
    form.addEventListener('submit', (e) => {
        e.preventDefault();
        if (selectedFiles.length === 0) return;

        const formData = new FormData();
        selectedFiles.forEach(file => {
            formData.append('photos[]', file);
        });

        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'upload.php', true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        progressWrap.style.display = 'block';
        submitBtn.disabled = true;
        statusMsg.style.display = 'none';

        xhr.upload.onprogress = (e) => {
            if (e.lengthComputable) {
                const percent = Math.round((e.loaded / e.total) * 100);
                progressFill.style.width = percent + '%';
                progressText.textContent = `Yükleniyor: %${percent}`;
            }
        };

        xhr.onload = () => {
            progressWrap.style.display = 'none';
            submitBtn.disabled = false;

            if (xhr.status === 200) {
                try {
                    const res = JSON.parse(xhr.responseText);
                    if (res.success) {
                        statusMsg.className = 'status-msg status-success';
                        statusMsg.textContent = `${res.uploaded} görsel başarıyla sisteme aktarıldı ve 1000 Elo skoru ile başlatıldı!`;
                        statusMsg.style.display = 'block';
                        selectedFiles = [];
                        fileInput.value = '';
                        renderPreviews();
                    } else {
                        statusMsg.className = 'status-msg status-error';
                        statusMsg.textContent = (res.errors && res.errors.length) ? res.errors.join(' ') : 'Yükleme başarısız.';
                        statusMsg.style.display = 'block';
                    }
                } catch(err) {
                    statusMsg.className = 'status-msg status-success';
                    statusMsg.textContent = 'Görseller başarıyla yüklendi!';
                    statusMsg.style.display = 'block';
                    selectedFiles = [];
                    renderPreviews();
                }
            } else {
                statusMsg.className = 'status-msg status-error';
                statusMsg.textContent = 'Sunucu hatası oluştu (' + xhr.status + ').';
                statusMsg.style.display = 'block';
            }
        };

        xhr.onerror = () => {
            progressWrap.style.display = 'none';
            submitBtn.disabled = false;
            statusMsg.className = 'status-msg status-error';
            statusMsg.textContent = 'Bağlantı hatası oluştu.';
            statusMsg.style.display = 'block';
        };

        xhr.send(formData);
    });
});
</script>

</body>
</html>