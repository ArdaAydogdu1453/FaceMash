<?php
session_start();
require 'config.php';

// Güvenlik: Yalnızca yetkili yönetici işlem yapabilir
if (!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['photo_ids'])) {
    $photoIds = $_POST['photo_ids'];
    
    if (is_array($photoIds) && !empty($photoIds)) {
        $getFilenameStmt = $conn->prepare("SELECT filename FROM photos WHERE id = ?");
        $deleteRecordStmt = $conn->prepare("DELETE FROM photos WHERE id = ?");
        $photosDir = realpath(__DIR__ . '/assets/photos');

        foreach ($photoIds as $id) {
            $photoId = (int)$id;

            // 1. Dosya adını çek
            $getFilenameStmt->bind_param("i", $photoId);
            $getFilenameStmt->execute();
            $result = $getFilenameStmt->get_result();

            if ($row = $result->fetch_assoc()) {
                $filename = basename($row['filename']);
                $filePath = __DIR__ . '/assets/photos/' . $filename;
                
                // 2. Veritabanından sil
                $deleteRecordStmt->bind_param("i", $photoId);
                $deleteRecordStmt->execute();

                // 3. Fiziksel dosyayı güvenli yolla sil
                $realFilePath = realpath($filePath);
                if ($realFilePath && $photosDir && strpos($realFilePath, $photosDir) === 0 && file_exists($realFilePath)) {
                    @unlink($realFilePath);
                }
            }
        }
        $getFilenameStmt->close();
        $deleteRecordStmt->close();
    }
}

header('Location: admin.php');
exit;
?>