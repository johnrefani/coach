<?php
session_start();

// Determine which login page to redirect to based on user type in session
$redirect_page = "index.php"; // Default redirection

// Check which type of user is currently logged in
if (isset($_SESSION['superadmin'])) {
    $redirect_page = "loginsuperadmin.php";
} elseif (isset($_SESSION['admin'])) {
    $redirect_page = "loginadmin.php";
} elseif (isset($_SESSION['mentor'])) {
    $redirect_page = "login_mentor.php";
} elseif (isset($_SESSION['mentee'])) {
    $redirect_page = "login_mentee.php";
}

// Clear all session data
session_unset();
session_destroy();

// Redirect to the appropriate login page
header("Location: " . $redirect_page);
exit();
?>
