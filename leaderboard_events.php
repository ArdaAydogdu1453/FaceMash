<?php
/**
 * FaceMash - Leaderboard JSON Polling Endpoint
 *
 * Returns current leaderboard data as JSON and exits immediately.
 * Compatible with shared hosting (InfinityFree, etc.) where
 * persistent SSE connections are killed after 30 seconds.
 *
 * The client polls this endpoint every 3 seconds via setInterval.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Access-Control-Allow-Origin: *');

include 'config.php';
include 'matchup_logic.php';

$query = "
    SELECT id, filename, wins, losses, rating,
           ROUND((wins / NULLIF(wins + losses, 0)) * 100, 1) AS win_rate
    FROM photos
    ORDER BY rating DESC, wins DESC, id ASC
    LIMIT 25
";

$result = $conn->query($query);
$topPhotos = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $row['rating']      = (int)round($row['rating']);
        $row['win_rate']    = $row['win_rate'] !== null ? (float)$row['win_rate'] : 0.0;
        $row['displayName'] = format_display_name($row['filename']);
        $topPhotos[] = $row;
    }
}

$conn->close();
echo json_encode($topPhotos);
exit;
?>