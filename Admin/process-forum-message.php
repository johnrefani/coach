<?php
session_start();

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "coach";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// SESSION CHECK
if (!isset($_SESSION['admin_username'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Not authorized']);
    exit();
}

// Get admin's name for display
$currentUser = $_SESSION['admin_username'];
$stmt = $conn->prepare("SELECT Admin_Name FROM admins WHERE Admin_Username = ?");
$stmt->bind_param("s", $currentUser);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $displayName = $row['Admin_Name'];
} else {
    $displayName = $currentUser;
}

// Handle message submission for forum chat
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message'], $_POST['forum_id'])) {
    $message = trim($_POST['message']);
    $forumId = $_POST['forum_id'];
    $isAdmin = 1; // Admin is always 1
    
    if (!empty($message)) {
        $stmt = $conn->prepare("INSERT INTO chat_messages (username, display_name, message, is_admin, chat_type, forum_id) VALUES (?, ?, ?, ?, 'forum', ?)");
        $stmt->bind_param("sssii", $currentUser, $displayName, $message, $isAdmin, $forumId);
        $stmt->execute();
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit();
    }
}

header('Content-Type: application/json');
echo json_encode(['success' => false, 'message' => 'Invalid request']);
?>
