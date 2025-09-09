<?php
$conn = new mysqli("localhost", "root", "", "coach-hub");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
