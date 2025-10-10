<?php
// Create a new file called check_username.php in the same directory as your main file
// This file will handle the AJAX request to check if a username exists

session_start();
// Check if SuperAdmin is logged in
if (!isset($_SESSION['superadmin'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized access']);
    exit();
}

// Connect to database
require 'connection/db_connection.php';

// Get the username from the AJAX request
if (isset($_POST['username'])) {
    $username = trim($_POST['username']);
    
    // Prepare and execute the query
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    
    header('Content-Type: application/json');
    echo json_encode(['exists' => ($row['count'] > 0)]);
    
    $stmt->close();
} else {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'No username provided']);
}

$conn->close();
?>
