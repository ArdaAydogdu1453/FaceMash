<?php
/**
 * FaceMash - Database & Pusher Configuration Template
 * 
 * Copy this file to `config.php` and configure your credentials.
 */

// MySQL Database Credentials
$servername = getenv('DB_HOST') ?: "localhost";
$username   = getenv('DB_USER') ?: "root";
$password   = getenv('DB_PASS') !== false ? getenv('DB_PASS') : "";
$dbname     = getenv('DB_NAME') ?: "facemash";

// Administrative Access Credentials
define('ADMIN_USERNAME', getenv('ADMIN_USER') ?: 'admin');
define('ADMIN_PASSWORD', getenv('ADMIN_PASS') ?: 'facemash_admin_password');

// Pusher 0ms WebSocket Real-Time Configuration
define('PUSHER_APP_ID',  getenv('PUSHER_APP_ID')  ?: 'YOUR_PUSHER_APP_ID');
define('PUSHER_KEY',     getenv('PUSHER_KEY')     ?: 'YOUR_PUSHER_KEY');
define('PUSHER_SECRET',  getenv('PUSHER_SECRET')  ?: 'YOUR_PUSHER_SECRET');
define('PUSHER_CLUSTER', getenv('PUSHER_CLUSTER') ?: 'eu');
define('PUSHER_CHANNEL', 'facemash');
define('PUSHER_EVENT',   'leaderboard-update');

// Initialize Database Connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Verify Connection Status
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

// UTF-8 Character Support
$conn->set_charset("utf8mb4");
?>
