<?php
$conn = new mysqli("localhost", "root", "", "coach");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>