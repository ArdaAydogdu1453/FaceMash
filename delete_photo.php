<?php
session_start();
include 'config.php';

// Güvenlik: Yalnızca yetkili yönetici silebilir
if (!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['photo_id'])) {
        $photoId = (int)$_POST['photo_id'];

        // 1. Önce dosya adını veritabanından güvenli şekilde çek
        $stmt = $conn->prepare("SELECT filename FROM photos WHERE id = ?");
        $stmt->bind_param("i", $photoId);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($row = $res->fetch_assoc()) {
            $filename = basename($row['filename']);
            $filePath = __DIR__ . '/assets/photos/' . $filename;

            // 2. Veritabanından kaydı sil
            $delStmt = $conn->prepare("DELETE FROM photos WHERE id = ?");
            $delStmt->bind_param("i", $photoId);
            $delStmt->execute();

            if ($delStmt->affected_rows > 0) {
                // 3. Fiziksel dosyayı güvenli dizin doğrulamasıyla sil
                $photosDir = realpath(__DIR__ . '/assets/photos');
                $realFilePath = realpath($filePath);

                if ($realFilePath && strpos($realFilePath, $photosDir) === 0 && file_exists($realFilePath)) {
                    @unlink($realFilePath);
                }
            }
            $delStmt->close();
        }
        $stmt->close();
    }
}

// 4. Admin paneline geri yönlendir
header("Location: admin.php");
exit;
?>