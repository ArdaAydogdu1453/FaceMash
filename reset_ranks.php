<?php
session_start();
require 'config.php';

// Ensure the user is an admin
if (!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Reset all scores to their default values
    $sql = "UPDATE photos SET wins = 0, losses = 0, rating = 1000";
    $conn->query($sql);
}

// Also, it's a good idea to clear the matchup history for all users
// as the ranking has fundamentally changed.
if (isset($_SESSION['shown_matchups'])) {
    unset($_SESSION['shown_matchups']);
}


header('Location: admin.php');
exit;
?> 