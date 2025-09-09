<?php
// Create a new file called check_user_data.php in the same directory as your main file
// This file will handle the AJAX request to check if a username or email exists

session_start();
// Check if SuperAdmin is logged in
if (!isset($_SESSION['superadmin'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized access']);
    exit();
}

// Connect to database
require 'connection/db_connection.php';
// Check username availability
if (isset($_POST['check']) && $_POST['check'] === 'username' && isset($_POST['username'])) {
    $username = trim($_POST['username']);
    
    // Prepare and execute the query
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM admins WHERE Admin_Username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    
    header('Content-Type: application/json');
    echo json_encode(['exists' => ($row['count'] > 0)]);
    
    $stmt->close();
} 
// Check email validity and availability
else if (isset($_POST['check']) && $_POST['check'] === 'email' && isset($_POST['email'])) {
    $email = trim($_POST['email']);
    $response = ['valid' => false, 'exists' => false, 'verified' => false];
    
    // First check if email format is valid
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $response['valid'] = true;
        
        // Then check if email already exists in database
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM admins WHERE Admin_Email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $response['exists'] = ($row['count'] > 0);
        $stmt->close();
        
        // Verify if email domain exists (basic check)
        $domain = substr(strrchr($email, "@"), 1);
        if ($domain) {
            // Check if MX record exists for domain
            $response['verified'] = checkdnsrr($domain, 'MX');
        }
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
} 
else {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid request']);
}

$conn->close();
?>