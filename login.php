<?php
session_start();

// Database connection
$servername = "localhost";
$db_username = "root";
$db_password = "";
$dbname = "coach";

$conn = new mysqli($servername, $db_username, $db_password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Make sure form submitted via POST
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get and sanitize form inputs
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    // Check if user is a mentee
    $stmt = $conn->prepare("SELECT * FROM mentee_profiles WHERE Username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $menteeResult = $stmt->get_result();

    if ($menteeResult->num_rows == 1) {
        $user = $menteeResult->fetch_assoc();
        $hashedPassword = $user['Password'];

        if (password_verify($password, $hashedPassword)) {
            $_SESSION['username'] = $username;
            $_SESSION['first_name'] = $user['First_Name']; // ✅ Store for welcome message
            $_SESSION['login_success'] = true;             // ✅ Trigger popup in CoachMentee.php

            header("Location: CoachMenteeHome.php");
            exit();
        } else {
            echo "<script>alert('Incorrect password.'); window.location.href='login_mentee.php';</script>";
            exit();
        }
    } else {
        echo "<script>alert('Username not found.'); window.location.href='login_mentee.php';</script>";
        exit();
    }
}

$conn->close();
?>
