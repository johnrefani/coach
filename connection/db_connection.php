<?php
$conn = new mysqli("localhost", "coach-user", "coach2025Hub!", "coach-hub");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
