<?php
session_start();

// Legacy + ASB session reset (yeni turnuva)
if (isset($_SESSION['shown_matchups'])) {
    unset($_SESSION['shown_matchups']);
}
unset($_SESSION['asb']);

// Redirect the user back to the main page to start over
header('Location: index.php');
exit;
?> 