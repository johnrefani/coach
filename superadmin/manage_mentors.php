<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'Super Admin') {
    header("Location: ../login.php");
    exit();
}

require '../connection/db_connection.php';
require '../vendor/autoload.php';

try {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
} catch (\Exception $e) {
    error_log("Dotenv Error in manage_mentors.php: " . $e->getMessage());
}

$admin_icon = !empty($_SESSION['superadmin_icon']) ? $_SESSION['superadmin_icon'] : '../uploads/img/default_pfp.png';
$admin_name = !empty($_SESSION['first_name']) ? $_SESSION['first_name'] : 'Admin';

// Handle AJAX request for fetching the assigned course for a mentor
if (isset($_GET['action']) && $_GET['action'] === 'get_assigned_course') {
    header('Content-Type: application/json');
    $mentor_id = $_GET['mentor_id'] ?? 0;
    
    $get_mentor_name = "SELECT CONCAT(first_name, ' ', last_name) AS full_name FROM users WHERE user_id = ? AND user_type = 'Mentor'";
    $stmt = $conn->prepare($get_mentor_name);
    $stmt->bind_param("i", $mentor_id);
    $stmt->execute();
    $stmt->bind_result($mentor_name);
    $stmt->fetch();
    $stmt->close();

    $assigned_course = null;

    if ($mentor_name) {
        $sql = "SELECT Course_ID, Course_Title FROM courses WHERE Assigned_Mentor = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $mentor_name);
        $stmt->execute();
        $result = $stmt->get_result();
        $assigned_course = $result->fetch_assoc();
        $stmt->close();
    }
    
    echo json_encode($assigned_course);
    exit();
}

// Handle AJAX request for removing a mentor's course assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'remove_assigned_course') {
    header('Content-Type: application/json');
    $course_id = $_POST['course_id'];
    $mentor_id = $_POST['mentor_id'] ?? null;
    
    try {
        $conn->begin_transaction();
        
        // Get course title and mentor details before removal
        $get_details = "SELECT c.Course_Title, u.email, CONCAT(u.first_name, ' ', u.last_name) AS full_name 
                       FROM courses c 
                       LEFT JOIN users u ON c.Assigned_Mentor = CONCAT(u.first_name, ' ', u.last_name)
                       WHERE c.Course_ID = ?";
        $stmt = $conn->prepare($get_details);
        $stmt->bind_param("i", $course_id);
        $stmt->execute();
        $stmt->bind_result($course_title, $mentor_email, $mentor_full_name);
        $stmt->fetch();
        $stmt->close();
        
        // Remove assignment - set Assigned_Mentor to NULL
        $update_course = "UPDATE courses SET Assigned_Mentor = NULL WHERE Course_ID = ?";
        $stmt = $conn->prepare($update_course);
        $stmt->bind_param("i", $course_id);
        $stmt->execute();
        $stmt->close();
        
        $conn->commit();
        
        // Send email notification
        $email_sent_status = 'Email not sent';
        $sendgrid_api_key = $_ENV['SENDGRID_API_KEY'] ?? null;
        $from_email = $_ENV['FROM_EMAIL'] ?? 'noreply@coach.com';
        
        if ($sendgrid_api_key && $mentor_email) {
            try {
                $email = new \SendGrid\Mail\Mail();
                $sender_name = $_ENV['FROM_NAME'] ?? "BPSUCOACH";
                
                $email->setFrom($from_email, $sender_name);
                $email->setSubject("Course Assignment Removed - COACH Program");
                $email->addTo($mentor_email, $mentor_full_name);
                
                $html_body = "
                <html>
                <head>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 5px; background-color:rgb(241, 223, 252); }
                    .header { background-color: #562b63; padding: 15px; color: white; text-align: center; border-radius: 5px 5px 0 0; }
                    .content { padding: 20px; background-color: #f9f9f9; }
                    .course-box { background-color: #fff; border: 1px solid #ddd; padding: 15px; margin: 15px 0; border-radius: 5px; }
                    .footer { text-align: center; padding: 10px; font-size: 12px; color: #777; }
                </style>
                </head>
                <body>
                <div class='container'>
                    <div class='header'>
                    <h2>Course Assignment Update</h2>
                    </div>
                    <div class='content'>
                    <p>Dear $mentor_full_name,</p>
                    <p>This is to inform you that your course assignment has been removed by the administrator.</p>
                    
                    <div class='course-box'>
                        <p><strong>Removed Course:</strong> $course_title</p>
                    </div>
                    
                    <p>You are no longer assigned to mentor this course. If you have any questions or concerns, please contact the administrator.</p>
                    <p>Best regards,<br>The COACH Team</p>
                    </div>
                    <div class='footer'>
                    <p>&copy; " . date("Y") . " COACH. All rights reserved.</p>
                    </div>
                </div>
                </body>
                </html>
                ";
                
                $email->addContent("text/html", $html_body);
                
                $sendgrid = new \SendGrid($sendgrid_api_key);
                $response = $sendgrid->send($email);
                
                if ($response->statusCode() >= 200 && $response->statusCode() < 300) {
                    $email_sent_status = 'Email sent successfully';
                }
                
            } catch (\Exception $email_e) {
                error_log("Course Removal Email Exception: " . $email_e->getMessage());
            }
        }
        
        echo json_encode(['success' => true, 'message' => 'Course assignment successfully removed! ' . $email_sent_status]);
        
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Error removing course assignment: ' . $e->getMessage()]);
    }
    exit();
}

// Handle AJAX requests for fetching available courses
if (isset($_GET['action']) && $_GET['action'] === 'get_available_courses') {
    header('Content-Type: application/json');
    
    // Only get courses with NULL or empty Assigned_Mentor
    $sql = "SELECT Course_ID, Course_Title FROM courses WHERE (Assigned_Mentor IS NULL OR Assigned_Mentor = '') ORDER BY Course_Title ASC";
    $result = $conn->query($sql);
    
    $available_courses = [];
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $available_courses[] = $row;
        }
    }
    
    echo json_encode($available_courses);
    exit();
}

// Handle AJAX request for changing course assignment (REASSIGNMENT)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_course') {
    header('Content-Type: application/json');
    $mentor_id = $_POST['mentor_id'];
    $old_course_id = $_POST['old_course_id'] ?? null;
    $new_course_id = $_POST['new_course_id'];
    
    try {
        $conn->begin_transaction();
        
        // Get mentor details
        $get_mentor = "SELECT email, CONCAT(first_name, ' ', last_name) AS full_name FROM users WHERE user_id = ?";
        $stmt = $conn->prepare($get_mentor);
        $stmt->bind_param("i", $mentor_id);
        $stmt->execute();
        $stmt->bind_result($mentor_email, $mentor_full_name);
        $stmt->fetch();
        $stmt->close();
        
        // Get old course title if exists
        $old_course_title = null;
        if ($old_course_id) {
            $get_old_course = "SELECT Course_Title FROM courses WHERE Course_ID = ?";
            $stmt = $conn->prepare($get_old_course);
            $stmt->bind_param("i", $old_course_id);
            $stmt->execute();
            $stmt->bind_result($old_course_title);
            $stmt->fetch();
            $stmt->close();
            
            // Remove old assignment - set Assigned_Mentor to NULL
            $update_old = "UPDATE courses SET Assigned_Mentor = NULL WHERE Course_ID = ?";
            $stmt = $conn->prepare($update_old);
            $stmt->bind_param("i", $old_course_id);
            $stmt->execute();
            $stmt->close();
        }
        
        // Assign new course
        $update_new = "UPDATE courses SET Assigned_Mentor = ? WHERE Course_ID = ?";
        $stmt = $conn->prepare($update_new);
        $stmt->bind_param("si", $mentor_full_name, $new_course_id);
        $stmt->execute();
        $stmt->close();
        
        // Get new course title
        $get_new_course = "SELECT Course_Title FROM courses WHERE Course_ID = ?";
        $stmt = $conn->prepare($get_new_course);
        $stmt->bind_param("i", $new_course_id);
        $stmt->execute();
        $stmt->bind_result($new_course_title);
        $stmt->fetch();
        $stmt->close();
        
        $conn->commit();
        
        // Send email notification for REASSIGNMENT ONLY (not removal)
        $email_sent_status = 'Email not sent';
        $sendgrid_api_key = $_ENV['SENDGRID_API_KEY'] ?? null;
        $from_email = $_ENV['FROM_EMAIL'] ?? 'noreply@coach.com';
        
        if ($sendgrid_api_key && $mentor_email) {
            try {
                $email = new \SendGrid\Mail\Mail();
                $sender_name = $_ENV['FROM_NAME'] ?? "BPSUCOACH";
                
                $email->setFrom($from_email, $sender_name);
                $email->setSubject("Course Assignment Reassigned - COACH Program");
                $email->addTo($mentor_email, $mentor_full_name);
                
                // Customize message based on whether there was a previous assignment
                $change_text = $old_course_title 
                    ? "Your handled course has been reassigned from <strong>$old_course_title</strong> to a new course." 
                    : "You have been assigned to a new course.";
                
                $html_body = "
                <html>
                <head>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 5px; background-color:rgb(241, 223, 252); }
                    .header { background-color: #562b63; padding: 15px; color: white; text-align: center; border-radius: 5px 5px 0 0; }
                    .content { padding: 20px; background-color: #f9f9f9; }
                    .course-box { background-color: #fff; border: 1px solid #ddd; padding: 15px; margin: 15px 0; border-radius: 5px; }
                    .footer { text-align: center; padding: 10px; font-size: 12px; color: #777; }
                </style>
                </head>
                <body>
                <div class='container'>
                    <div class='header'>
                    <h2>Course Assignment Reassigned</h2>
                    </div>
                    <div class='content'>
                    <p>Dear $mentor_full_name,</p>
                    <p>$change_text</p>
                    
                    <div class='course-box'>";
                
                if ($old_course_title) {
                    $html_body .= "<p><strong>Previous Course:</strong> $old_course_title</p>";
                }
                
                $html_body .= "
                        <p><strong>New Course Assignment:</strong> $new_course_title</p>
                    </div>
                    
                    <p>Please log in at <a href='https://coach-hub.online/login.php'>COACH</a> to view your updated course assignment and continue mentoring.</p>
                    <p>If you have any questions or concerns, please contact the administrator.</p>
                    <p>Best regards,<br>The COACH Team</p>
                    </div>
                    <div class='footer'>
                    <p>&copy; " . date("Y") . " COACH. All rights reserved.</p>
                    </div>
                </div>
                </body>
                </html>
                ";
                
                $email->addContent("text/html", $html_body);
                
                $sendgrid = new \SendGrid($sendgrid_api_key);
                $response = $sendgrid->send($email);
                
                if ($response->statusCode() >= 200 && $response->statusCode() < 300) {
                    $email_sent_status = 'Email sent successfully';
                }
                
            } catch (\Exception $email_e) {
                error_log("Course Reassignment Email Exception: " . $email_e->getMessage());
            }
        }
        
        echo json_encode(['success' => true, 'message' => "Course assignment updated to '$new_course_title'. $email_sent_status"]);
        
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Error changing course: ' . $e->getMessage()]);
    }
    exit();
}

// Handle AJAX request for approving a mentor and assigning a course (INITIAL APPROVAL)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'approve_with_course') {
    header('Content-Type: application/json');
    $mentor_id = $_POST['mentor_id'];
    $course_id = $_POST['course_id'];
    
    try {
        $conn->begin_transaction();
        
        $get_mentor = "SELECT email, CONCAT(first_name, ' ', last_name) AS full_name, user_type FROM users WHERE user_id = ?";
        $stmt = $conn->prepare($get_mentor);
        $stmt->bind_param("i", $mentor_id);
        $stmt->execute();
        $stmt->bind_result($mentor_email, $mentor_full_name, $user_type);
        $stmt->fetch();
        $stmt->close();
        
        if ($user_type !== 'Mentor') {
             throw new Exception("User is not a Mentor.");
        }

        $update_user = "UPDATE users SET status = 'Approved', reason = NULL WHERE user_id = ? AND status = 'Under Review'";
        $stmt = $conn->prepare($update_user);
        $stmt->bind_param("i", $mentor_id);
        $stmt->execute();
        $stmt->close();

        $update_course = "UPDATE courses SET Assigned_Mentor = ? WHERE Course_ID = ?";
        $stmt = $conn->prepare($update_course);
        $stmt->bind_param("si", $mentor_full_name, $course_id);
        $stmt->execute();
        $stmt->close();
        
        $get_course_title = "SELECT Course_Title FROM courses WHERE Course_ID = ?";
        $stmt = $conn->prepare($get_course_title);
        $stmt->bind_param("i", $course_id);
        $stmt->execute();
        $stmt->bind_result($course_title);
        $stmt->fetch();
        $stmt->close();
        
        $conn->commit();
        
        $email_sent_status = 'Email not sent (Error)';
        
        $sendgrid_api_key = $_ENV['SENDGRID_API_KEY'] ?? null;
        $from_email = $_ENV['FROM_EMAIL'] ?? 'noreply@coach.com';
        
        if ($sendgrid_api_key) {
            try {
                $email = new \SendGrid\Mail\Mail();
                $sender_name = $_ENV['FROM_NAME'] ?? "BPSUCOACH";
                
                $email->setFrom($from_email, $sender_name);
                $email->setSubject("Congratulations! Your Mentor Application Has Been Approved");
                $email->addTo($mentor_email, $mentor_full_name);
                
                $html_body = "
                <html>
                <head>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 5px; background-color:rgb(241, 223, 252); }
                    .header { background-color: #562b63; padding: 15px; color: white; text-align: center; border-radius: 5px 5px 0 0; }
                    .content { padding: 20px; background-color: #f9f9f9; }
                    .course-box { background-color: #fff; border: 1px solid #ddd; padding: 15px; margin: 15px 0; border-radius: 5px; }
                    .footer { text-align: center; padding: 10px; font-size: 12px; color: #777; }
                </style>
                </head>
                <body>
                <div class='container'>
                    <div class='header'>
                    <h2>Congratulations! Your Mentor Application Has Been Approved</h2>
                    </div>
                    <div class='content'>
                    <p>Dear $mentor_full_name,</p>
                    <p>We are pleased to inform you that your application to become a Mentor has been approved!</p>
                    
                    <div class='course-box'>
                        <p>You have been assigned to mentor the course: <strong>$course_title</strong>.</p>
                    </div>
                    
                    <p>Please log in at <a href='https://coach-hub.online/login.php'>COACH</a> to view your assigned course and start mentoring.</p>
                    <p>Thank you for joining the COACH program.</p>
                    <p>Best regards,<br>The COACH Team</p>
                    </div>
                    <div class='footer'>
                    <p>&copy; " . date("Y") . " COACH. All rights reserved.</p>
                    </div>
                </div>
                </body>
                </html>
                ";
                
                $email->addContent("text/html", $html_body);
                
                $sendgrid = new \SendGrid($sendgrid_api_key);
                $response = $sendgrid->send($email);
                
                if ($response->statusCode() >= 200 && $response->statusCode() < 300) {
                    $email_sent_status = 'Email sent successfully (Status: ' . $response->statusCode() . ').';
                } else {
                    $email_sent_status = 'SendGrid API error (Status: ' . $response->statusCode() . '). Check PHP error log.';
                    error_log("SendGrid Approval Error: Status=" . $response->statusCode() . ", Body=" . ($response->body() ?: 'No body response'));
                }
                
            } catch (\Exception $email_e) {
                error_log("Approval Email Exception: " . $email_e->getMessage());
                $email_sent_status = 'Exception error. Check PHP error log.';
            }
        } else {
            $email_sent_status = 'Error: SendGrid API key or FROM_EMAIL is missing in .env.';
        }
        
        echo json_encode(['success' => true, 'message' => "Mentor approved and assigned to course '$course_title'. Email status: $email_sent_status"]);
        
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Transaction failed: ' . $e->getMessage()]);
    }
    exit();
}

// Handle AJAX request for rejecting a mentor
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reject_mentor') {
    header('Content-Type: application/json');
    $mentor_id = $_POST['mentor_id'];
    $reason = $_POST['reason'];
    
    try {
        $conn->begin_transaction();
        
        $update_user = "UPDATE users SET status = 'Rejected', reason = ? WHERE user_id = ?";
        $stmt = $conn->prepare($update_user);
        $stmt->bind_param("si", $reason, $mentor_id);
        $stmt->execute();
        $stmt->close();
        
        // Get mentor details
        $get_mentor = "SELECT email, CONCAT(first_name, ' ', last_name) AS full_name, user_type FROM users WHERE user_id = ?";
        $stmt = $conn->prepare($get_mentor);
        $stmt->bind_param("i", $mentor_id);
        $stmt->execute();
        $stmt->bind_result($mentor_email, $mentor_full_name, $user_type);
        $stmt->fetch();
        $stmt->close();
        
        $conn->commit();
        
        $email_sent_status = 'Email not sent (Error)';
        
        $sendgrid_api_key = $_ENV['SENDGRID_API_KEY'] ?? null;
        $from_email = $_ENV['FROM_EMAIL'] ?? 'noreply@coach.com';
        
        if ($sendgrid_api_key) {
            try {
                $email = new \SendGrid\Mail\Mail();
                $sender_name = $_ENV['FROM_NAME'] ?? "BPSUCOACH";
                
                $email->setFrom($from_email, $sender_name);
                $email->setSubject("Update on Your Mentor Application - COACH Program");
                $email->addTo($mentor_email, $mentor_full_name);
                
                $html_body = "
                <html>
                <head>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 5px; background-color:rgb(241, 223, 252); }
                    .header { background-color: #562b63; padding: 15px; color: white; text-align: center; border-radius: 5px 5px 0 0; }
                    .content { padding: 20px; background-color: #f9f9f9; }
                    .reason-box { background-color: #fff; border: 1px solid #ddd; padding: 15px; margin: 15px 0; border-radius: 5px; color: #cc0000; font-weight: bold; }
                    .footer { text-align: center; padding: 10px; font-size: 12px; color: #777; }
                </style>
                </head>
                <body>
                <div class='container'>
                    <div class='header'>
                    <h2>Application Status Update</h2>
                    </div>
                    <div class='content'>
                    <p>Dear $mentor_full_name,</p>
                    <p>Thank you for your interest in becoming a Mentor for the COACH program. After careful consideration, we regret to inform you that your application has been **Rejected**.</p>
                    
                    <p>The reason provided for the rejection is:</p>
                    <div class='reason-box'>
                        <p>$reason</p>
                    </div>
                    
                    <p>We appreciate you taking the time to apply.</p>
                    <p>Best regards,<br>The COACH Team</p>
                    </div>
                    <div class='footer'>
                    <p>&copy; " . date("Y") . " COACH. All rights reserved.</p>
                    </div>
                </div>
                </body>
                </html>
                ";
                
                $email->addContent("text/html", $html_body);
                
                $sendgrid = new \SendGrid($sendgrid_api_key);
                $response = $sendgrid->send($email);
                
                if ($response->statusCode() >= 200 && $response->statusCode() < 300) {
                    $email_sent_status = 'Email sent successfully (Status: ' . $response->statusCode() . ').';
                } else {
                    $email_sent_status = 'SendGrid API error (Status: ' . $response->statusCode() . '). Check PHP error log.';
                    error_log("SendGrid Rejection Error: Status=" . $response->statusCode() . ", Body=" . ($response->body() ?: 'No body response'));
                }
                
            } catch (\Exception $email_e) {
                error_log("Rejection Email Exception: " . $email_e->getMessage());
                $email_sent_status = 'Exception error. Check PHP error log.';
            }
        } else {
            $email_sent_status = 'Error: SendGrid API key or FROM_EMAIL is missing in .env.';
        }

        echo json_encode(['success' => true, 'message' => "Mentor rejected. Email status: $email_sent_status"]);
        
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Transaction failed: ' . $e->getMessage()]);
    }
    exit();
}

// After all AJAX handlers, get the data for the tables
$approved = [];
$applicants = [];
$rejected = [];

// The main query to fetch all mentor data. FIX: Changed 'user_icon' to 'icon AS user_icon'
$sql = "SELECT user_id, first_name, last_name, dob, gender, email, contact_number, icon AS user_icon, status, reason FROM users WHERE user_type = 'Mentor'";
$result = $conn->query($sql);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $course_title = null;
        
        // Find the assigned course (if any)
        $mentor_full_name = $row['first_name'] . ' ' . $row['last_name'];
        $course_query = "SELECT Course_ID, Course_Title FROM courses WHERE Assigned_Mentor = ?";
        $stmt_course = $conn->prepare($course_query);
        $stmt_course->bind_param("s", $mentor_full_name);
        $stmt_course->execute();
        $result_course = $stmt_course->get_result();
        
        if ($course_row = $result_course->fetch_assoc()) {
            $row['assigned_course_id'] = $course_row['Course_ID'];
            $row['assigned_course_title'] = $course_row['Course_Title'];
        } else {
            $row['assigned_course_id'] = null;
            $row['assigned_course_title'] = 'Unassigned';
        }
        $stmt_course->close();

        if ($row['status'] === 'Approved') {
            $approved[] = $row;
        } elseif ($row['status'] === 'Under Review') {
            $applicants[] = $row;
        } elseif ($row['status'] === 'Rejected') {
            $rejected[] = $row;
        }
    }
} else {
    // Log the error for the developer
    error_log("SQL Error in manage_mentors.php main query: " . $conn->error);
}

// Convert PHP arrays to JSON for JavaScript
$approved_json = json_encode($approved);
$applicants_json = json_encode($applicants);
$rejected_json = json_encode($rejected);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Mentors - Super Admin</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/admin_dashboard.css">
    <link rel="stylesheet" href="../css/manage_mentors.css">
</head>
<body>

    <nav class="sidebar close">
        <header>
            <div class="image-text">
                <span class="image">
                    <img src="../uploads/img/bpsu_logo.png" alt="logo">
                </span>

                <div class="text logo-text">
                    <span class="name">BPSU</span>
                    <span class="profession">COACH</span>
                </div>
            </div>
            <img src="../uploads/img/menu.svg" class="navToggle" alt="menu">
        </header>

        <div class="menu-bar">
            <div class="menu">
                <ul class="menu-links">
                    <li class="nav-link">
                        <a href="admin_dashboard.php">
                            <img src="../uploads/img/dashboard.svg" alt="icon">
                            <span class="text nav-text">Dashboard</span>
                        </a>
                    </li>
                    <li class="nav-link">
                        <a href="moderators.php">
                            <img src="../uploads/img/moderators.svg" alt="icon">
                            <span class="text nav-text">Moderators</span>
                        </a>
                    </li>
                    <li class="nav-link active">
                        <a href="manage_mentors.php">
                            <img src="../uploads/img/manage_mentors.svg" alt="icon">
                            <span class="text nav-text">Manage Mentors</span>
                        </a>
                    </li>
                    <li class="nav-link">
                        <a href="manage_mentees.php">
                            <img src="../uploads/img/manage_mentees.svg" alt="icon">
                            <span class="text nav-text">Manage Mentees</span>
                        </a>
                    </li>
                    <li class="nav-link">
                        <a href="manage_courses.php">
                            <img src="../uploads/img/manage_courses.svg" alt="icon">
                            <span class="text nav-text">Manage Courses</span>
                        </a>
                    </li>
                    <li class="nav-link">
                        <a href="manage_resources.php">
                            <img src="../uploads/img/manage_resources.svg" alt="icon">
                            <span class="text nav-text">Manage Resources</span>
                        </a>
                    </li>
                </ul>
            </div>

            <div class="bottom-content">
                <li class="nav-link">
                    <a href="#" id="logoutLink">
                        <img src="../uploads/img/logout.svg" alt="icon">
                        <span class="text nav-text">Logout</span>
                    </a>
                </li>
            </div>
        </div>
    </nav>

    <section class="home">
        <div class="header-content">
            <div class="header-title">
                <h1>Manage Mentors</h1>
            </div>
            <div class="admin-profile">
                <span class="admin-name"><?php echo htmlspecialchars($admin_name); ?></span>
                <img src="<?php echo htmlspecialchars($admin_icon); ?>" alt="Admin Icon" class="admin-icon">
            </div>
        </div>

        <div class="container">
            <div class="mentor-tabs">
                <button class="tab-button active" id="btnApproved">Approved Mentors (<?php echo count($approved); ?>)</button>
                <button class="tab-button" id="btnApplicants">New Applicants (<?php echo count($applicants); ?>)</button>
                <button class="tab-button" id="btnRejected">Rejected Mentors (<?php echo count($rejected); ?>)</button>
            </div>

            <div class="mentor-content-area">
                <div class="search-box">
                    <input type="text" id="searchInput" placeholder="Search by ID, Name, or Email..." onkeyup="searchMentors()">
                    <img src="../uploads/img/search.svg" alt="search">
                </div>

                <div class="table-container">
                    <table id="mentorsTable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Icon</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Gender</th>
                                <th>Contact No.</th>
                                <th class="course-column">Assigned Course</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>

    <div id="courseAssignmentPopup" class="modal-popup">
        <div class="modal-content small-modal">
            <span class="close-button" onclick="closeCourseAssignmentPopup()">&times;</span>
            <h2>Approve Mentor & Assign Course</h2>
            <form id="assignCourseForm">
                <input type="hidden" id="mentorIdAssign" name="mentor_id">
                <p>Approve **<span id="mentorNameAssign" class="bold-name"></span>** and assign an initial course.</p>

                <div class="input-group">
                    <label for="availableCoursesAssign">Select Course:</label>
                    <select id="availableCoursesAssign" name="course_id" required>
                        <option value="">Loading courses...</option>
                    </select>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn-cancel" onclick="closeCourseAssignmentPopup()">Cancel</button>
                    <button type="submit" class="btn-submit">Approve & Assign</button>
                </div>
            </form>
        </div>
    </div>

    <div id="updateCoursePopup" class="modal-popup">
        <div class="modal-content small-modal">
            <span class="close-button" onclick="closeUpdateCoursePopup()">&times;</span>
            <h2>Update Course Assignment</h2>
            <div id="courseChangePopup" class="sub-modal">
                <form id="changeCourseForm">
                    <input type="hidden" id="mentorIdChange" name="mentor_id">
                    <input type="hidden" id="oldCourseId" name="old_course_id">

                    <p>Change the course assignment for **<span id="mentorNameChange" class="bold-name"></span>**.</p>
                    <p>Current Course: <span id="currentCourseTitle" class="bold-course"></span></p>

                    <div class="input-group">
                        <label for="availableCoursesChange">New Course:</label>
                        <select id="availableCoursesChange" name="new_course_id" required>
                            <option value="">Loading courses...</option>
                        </select>
                    </div>

                    <div class="form-actions">
                        <button type="button" class="btn-cancel" onclick="closeUpdateCoursePopup()">Cancel</button>
                        <button type="submit" class="btn-submit">Change Course</button>
                    </div>
                </form>
            </div>

            <hr class="modal-separator">
            
            <div class="sub-modal">
                <p>To unassign the mentor from their current course, click the button below.</p>
                <div class="form-actions">
                    <button type="button" class="btn-danger" id="btnRemoveAssignment">Remove Assignment</button>
                </div>
            </div>
        </div>
    </div>
    
    <div id="rejectionModal" class="modal-popup">
        <div class="modal-content small-modal">
            <span class="close-button" onclick="closeRejectionModal()">&times;</span>
            <h2>Reject Mentor Application</h2>
            <form id="rejectMentorForm">
                <input type="hidden" id="mentorIdReject" name="mentor_id">
                <p>You are about to reject the application for **<span id="mentorNameReject" class="bold-name"></span>**.</p>
                <div class="input-group">
                    <label for="rejectionReason">Reason for Rejection (required):</label>
                    <textarea id="rejectionReason" name="reason" rows="4" required></textarea>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn-cancel" onclick="closeRejectionModal()">Cancel</button>
                    <button type="submit" class="btn-danger">Reject Mentor</button>
                </div>
            </form>
        </div>
    </div>

    <div id="customAlertModal" class="modal-popup">
        <div class="modal-content small-modal">
            <h3 id="customAlertTitle">Alert</h3>
            <p id="customAlertMessage"></p>
            <div class="form-actions">
                <button id="customAlertClose" type="button" onclick="closeCustomAlert()">OK</button>
            </div>
        </div>
    </div>
    
    <div id="customConfirmModal" class="modal-popup">
        <div class="modal-content small-modal">
            <h3 id="customConfirmTitle">Confirm Action</h3>
            <p id="customConfirmMessage"></p>
            <div class="form-actions">
                <button id="customConfirmCancel" type="button" onclick="closeCustomConfirm()">Cancel</button>
                <button id="customConfirmOK" type="button">Proceed</button>
            </div>
        </div>
    </div>
    
    <div id="logoutDialog" class="logout-dialog" style="display: none;">
        <div class="logout-content">
            <h3>Confirm Logout</h3>
            <p>Are you sure you want to log out?</p>
            <div class="dialog-buttons">
                <button id="cancelLogout" type="button">Cancel</button>
                <button id="confirmLogoutBtn" type="button">Logout</button>
            </div>
        </div>
    </div>

    <script>
        // PHP variables passed to JavaScript
        const approved = <?php echo $approved_json; ?>;
        const applicants = <?php echo $applicants_json; ?>;
        const rejected = <?php echo $rejected_json; ?>;
        const mentorTableBody = document.querySelector('#mentorsTable tbody');
        const tabButtons = document.querySelectorAll('.tab-button');
        const searchInput = document.getElementById('searchInput');

        let currentMentorData = []; // Data currently displayed in the table
        let currentMentorId = null; // Used for modals/actions

        // ================================== Helpers ==================================
        function showCustomAlert(title, message) {
            document.getElementById('customAlertTitle').textContent = title;
            document.getElementById('customAlertMessage').textContent = message;
            document.getElementById('customAlertModal').style.display = 'flex';
        }

        function closeCustomAlert() {
            document.getElementById('customAlertModal').style.display = 'none';
        }

        function showCustomConfirm(title, message, callback) {
            document.getElementById('customConfirmTitle').textContent = title;
            document.getElementById('customConfirmMessage').textContent = message;
            document.getElementById('customConfirmModal').style.display = 'flex';

            const okButton = document.getElementById('customConfirmOK');
            okButton.onclick = function() {
                closeCustomConfirm();
                callback(true);
            };
        }
        
        function closeCustomConfirm() {
            document.getElementById('customConfirmModal').style.display = 'none';
        }

        function createMentorRow(mentor, isApplicant) {
            const row = document.createElement('tr');
            row.classList.add('data-row');
            row.dataset.id = mentor.user_id;
            row.dataset.status = mentor.status;

            // Icon URL fallback
            const iconUrl = mentor.user_icon && mentor.user_icon !== 'default_pfp.png' ? mentor.user_icon : '../uploads/img/default_pfp.png';

            // Course Info display
            let courseHtml = '';
            if (mentor.status === 'Approved') {
                if (mentor.assigned_course_title && mentor.assigned_course_title !== 'Unassigned') {
                    courseHtml = `<span class="course-assigned" data-course-id="${mentor.assigned_course_id}">${mentor.assigned_course_title}</span>`;
                } else {
                    courseHtml = `<span class="course-unassigned">Unassigned</span>`;
                }
            } else {
                 courseHtml = `<span class="course-unassigned">N/A</span>`;
            }

            // Action Buttons
            let actionHtml = '';
            if (mentor.status === 'Under Review') {
                actionHtml = `
                    <button class="btn btn-approve" onclick="openCourseAssignmentPopup(${mentor.user_id}, '${mentor.first_name} ${mentor.last_name}')">Approve</button>
                    <button class="btn btn-reject" onclick="openRejectionModal(${mentor.user_id}, '${mentor.first_name} ${mentor.last_name}')">Reject</button>
                `;
            } else if (mentor.status === 'Approved') {
                actionHtml = `
                    <button class="btn btn-edit-course" onclick="openUpdateCoursePopup(${mentor.user_id}, '${mentor.first_name} ${mentor.last_name}', '${mentor.assigned_course_id}', '${mentor.assigned_course_title}')">Edit Course</button>
                    `;
            } else if (mentor.status === 'Rejected') {
                actionHtml = `
                    <button class="btn btn-view-reason" onclick="showCustomAlert('Rejection Reason', '${mentor.reason.replace(/'/g, "\\'")}')">View Reason</button>
                    `;
            }

            row.innerHTML = `
                <td>${mentor.user_id}</td>
                <td><img src="${iconUrl}" alt="icon" class="user-icon"></td>
                <td>${mentor.first_name} ${mentor.last_name}</td>
                <td>${mentor.email}</td>
                <td>${mentor.gender}</td>
                <td>${mentor.contact_number}</td>
                <td class="course-column">${courseHtml}</td>
                <td class="status-cell status-${mentor.status.replace(/\s/g, '')}">${mentor.status}</td>
                <td class="action-cell">${actionHtml}</td>
            `;
            return row;
        }

        function populateTable(data, isApplicantView) {
            mentorTableBody.innerHTML = ''; // Clear existing rows
            currentMentorData = data;
            
            if (data.length === 0) {
                const noDataRow = document.createElement('tr');
                noDataRow.classList.add('no-data');
                noDataRow.innerHTML = `<td colspan="9" style="text-align: center;">No mentors in this category.</td>`;
                mentorTableBody.appendChild(noDataRow);
            } else {
                data.forEach(mentor => {
                    mentorTableBody.appendChild(createMentorRow(mentor, isApplicantView));
                });
            }
            // Ensure search is run on new data to maintain filter state if any
            searchMentors();
        }

        function showTable(data, isApplicantView) {
            // Remove active class from all buttons
            tabButtons.forEach(btn => btn.classList.remove('active'));

            // Set active class on the correct button
            if (data === approved) {
                document.getElementById('btnApproved').classList.add('active');
            } else if (data === applicants) {
                document.getElementById('btnApplicants').classList.add('active');
            } else if (data === rejected) {
                document.getElementById('btnRejected').classList.add('active');
            }

            populateTable(data, isApplicantView);
        }

        // ================================== Filtering/Search ==================================
        function searchMentors() {
            const input = searchInput.value.toLowerCase().trim();
            const rows = document.querySelectorAll('#mentorsTable tbody tr.data-row');
            let found = false;

            rows.forEach(row => {
                // Only search in currently visible/loaded data rows
                if (row.style.display !== 'none' && !row.classList.contains('no-data')) {
                    const id = row.cells[0].innerText.toLowerCase();
                    const name = row.cells[2].innerText.toLowerCase();
                    const email = row.cells[3].innerText.toLowerCase();

                    if (id.includes(input) || name.includes(input) || email.includes(input)) {
                        row.style.display = '';
                        found = true;
                    } else {
                        row.style.display = 'none';
                    }
                }
            });
            
            // Handle no data row visibility
            const noDataRow = document.querySelector('#mentorsTable tbody tr.no-data');
            if (noDataRow) {
                // If there is a filter but nothing is found, we need a 'no results' message
                // If the initial load had no data, it already shows the 'no mentors' message
                if (currentMentorData.length > 0) {
                     noDataRow.innerHTML = `<td colspan="9" style="text-align: center;">No results found for "${searchInput.value.trim()}".</td>`;
                }
                noDataRow.style.display = found ? 'none' : (currentMentorData.length > 0 ? '' : noDataRow.style.display);
            }
        }

        // ================================== Course & Data Fetching ==================================
        function fetchAvailableCourses(selectElementId, mentorId = null) {
            const selectElement = document.getElementById(selectElementId);
            selectElement.innerHTML = '<option value="">Loading courses...</option>';

            fetch(`manage_mentors.php?action=get_available_courses`)
                .then(response => response.json())
                .then(data => {
                    selectElement.innerHTML = '<option value="">-- Select Course --</option>';
                    if (data && data.length > 0) {
                        data.forEach(course => {
                            const option = document.createElement('option');
                            option.value = course.Course_ID;
                            option.textContent = course.Course_Title;
                            selectElement.appendChild(option);
                        });
                    } else {
                        selectElement.innerHTML = '<option value="">No unassigned courses available</option>';
                        selectElement.disabled = true;
                    }
                })
                .catch(error => {
                    console.error('Error fetching available courses:', error);
                    selectElement.innerHTML = '<option value="">Error loading courses</option>';
                });
        }

        // ================================== Modal Handlers ==================================
        // --- Course Assignment (Approve) ---
        const courseAssignmentPopup = document.getElementById('courseAssignmentPopup');
        const assignCourseForm = document.getElementById('assignCourseForm');

        function openCourseAssignmentPopup(mentorId, mentorName) {
            currentMentorId = mentorId;
            document.getElementById('mentorIdAssign').value = mentorId;
            document.getElementById('mentorNameAssign').textContent = mentorName;
            fetchAvailableCourses('availableCoursesAssign'); // Fetch unassigned courses
            courseAssignmentPopup.style.display = 'flex';
        }

        function closeCourseAssignmentPopup() {
            courseAssignmentPopup.style.display = 'none';
        }

        assignCourseForm.onsubmit = function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('action', 'approve_with_course');
            
            showCustomConfirm('Confirm Approval', 'Are you sure you want to **APPROVE** this mentor and assign the selected course? An email notification will be sent.', (confirmed) => {
                if (confirmed) {
                    fetch('manage_mentors.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        closeCourseAssignmentPopup();
                        if (data.success) {
                            showCustomAlert('Success', data.message);
                            setTimeout(() => window.location.reload(), 2000); // Reload page to update data tables
                        } else {
                            showCustomAlert('Error', data.message);
                        }
                    })
                    .catch(error => {
                        closeCourseAssignmentPopup();
                        showCustomAlert('Error', 'An unexpected error occurred. Check console for details.');
                        console.error('Error in approval/assignment:', error);
                    });
                }
            });
        };
        
        // --- Rejection Modal ---
        const rejectionModal = document.getElementById('rejectionModal');
        const rejectMentorForm = document.getElementById('rejectMentorForm');
        
        function openRejectionModal(mentorId, mentorName) {
            currentMentorId = mentorId;
            document.getElementById('mentorIdReject').value = mentorId;
            document.getElementById('mentorNameReject').textContent = mentorName;
            document.getElementById('rejectionReason').value = ''; // Clear previous reason
            rejectionModal.style.display = 'flex';
        }

        function closeRejectionModal() {
            rejectionModal.style.display = 'none';
        }
        
        rejectMentorForm.onsubmit = function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('action', 'reject_mentor');
            
            showCustomConfirm('Confirm Rejection', 'Are you sure you want to **REJECT** this mentor application? An email notification will be sent.', (confirmed) => {
                 if (confirmed) {
                    fetch('manage_mentors.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        closeRejectionModal();
                        if (data.success) {
                            showCustomAlert('Success', data.message);
                            setTimeout(() => window.location.reload(), 2000); // Reload page to update data tables
                        } else {
                            showCustomAlert('Error', data.message);
                        }
                    })
                    .catch(error => {
                        closeRejectionModal();
                        showCustomAlert('Error', 'An unexpected error occurred. Check console for details.');
                        console.error('Error in rejection:', error);
                    });
                }
            });
        };

        // --- Course Update/Change ---
        const updateCoursePopup = document.getElementById('updateCoursePopup');
        const changeCourseForm = document.getElementById('changeCourseForm');
        const btnRemoveAssignment = document.getElementById('btnRemoveAssignment');

        function openUpdateCoursePopup(mentorId, mentorName, oldCourseId, oldCourseTitle) {
            currentMentorId = mentorId;
            document.getElementById('mentorIdChange').value = mentorId;
            document.getElementById('oldCourseId').value = oldCourseId;
            document.getElementById('mentorNameChange').textContent = mentorName;
            document.getElementById('currentCourseTitle').textContent = oldCourseTitle && oldCourseTitle !== 'Unassigned' ? oldCourseTitle : 'None Assigned';
            
            // Re-fetch courses for reassignment, it should include the old course (since it's assigned to this mentor)
            // and all unassigned courses. But the current implementation of `get_available_courses` only fetches NULL/'' assignments.
            // For reassignment, the old course needs to be temporarily unassigned, then the new one assigned. 
            // We'll keep the current approach of only showing unassigned courses for simplicity.
            fetchAvailableCourses('availableCoursesChange', mentorId); 
            
            // Handle visibility of the change/remove sections
            const courseChangePopup = document.getElementById('courseChangePopup');
            
            // If there's no course, they can only *assign* a new one, not change or remove the old one
            if (oldCourseId && oldCourseTitle !== 'Unassigned') {
                btnRemoveAssignment.style.display = 'inline-block';
                courseChangePopup.style.display = 'block';
            } else {
                // If unassigned, they can only assign a new one, so the "Remove" button is hidden.
                btnRemoveAssignment.style.display = 'none';
            }

            updateCoursePopup.style.display = 'flex';
        }

        function closeUpdateCoursePopup() {
            updateCoursePopup.style.display = 'none';
        }
        
        changeCourseForm.onsubmit = function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('action', 'change_course');
            
            const newCourseSelect = document.getElementById('availableCoursesChange');
            const newCourseTitle = newCourseSelect.options[newCourseSelect.selectedIndex].textContent;
            
            showCustomConfirm('Confirm Course Change', `Are you sure you want to change the course assignment to **${newCourseTitle}**? An email notification will be sent.`, (confirmed) => {
                 if (confirmed) {
                    fetch('manage_mentors.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        closeUpdateCoursePopup();
                        if (data.success) {
                            showCustomAlert('Success', data.message);
                            setTimeout(() => window.location.reload(), 2000); // Reload page to update data tables
                        } else {
                            showCustomAlert('Error', data.message);
                        }
                    })
                    .catch(error => {
                        closeUpdateCoursePopup();
                        showCustomAlert('Error', 'An unexpected error occurred. Check console for details.');
                        console.error('Error in course change:', error);
                    });
                }
            });
        };

        btnRemoveAssignment.onclick = function() {
            const oldCourseId = document.getElementById('oldCourseId').value;
            const mentorName = document.getElementById('mentorNameChange').textContent;
            
            if (!oldCourseId) {
                showCustomAlert('Error', 'No course is currently assigned to remove.');
                return;
            }
            
            showCustomConfirm('Confirm Removal', `Are you sure you want to **REMOVE** the course assignment for **${mentorName}**? An email notification will be sent.`, (confirmed) => {
                 if (confirmed) {
                    const formData = new FormData();
                    formData.append('action', 'remove_assigned_course');
                    formData.append('course_id', oldCourseId);
                    formData.append('mentor_id', currentMentorId); // Pass mentor ID for email info
                    
                    fetch('manage_mentors.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        closeUpdateCoursePopup();
                        if (data.success) {
                            showCustomAlert('Success', data.message);
                            setTimeout(() => window.location.reload(), 2000); // Reload page
                        } else {
                            showCustomAlert('Error', data.message);
                        }
                    })
                    .catch(error => {
                        closeUpdateCoursePopup();
                        showCustomAlert('Error', 'An unexpected error occurred. Check console for details.');
                        console.error('Error in course removal:', error);
                    });
                }
            });
        };
        
        // ================================== Event Listeners & Initial Load ==================================
        document.addEventListener('DOMContentLoaded', () => {
            // Initial table load logic: show applicants if any, otherwise show approved
            if (applicants.length > 0) {
                showTable(applicants, true);
            } else {
                showTable(approved, false);
            }
        });

        const btnApproved = document.getElementById('btnApproved');
        const btnApplicants = document.getElementById('btnApplicants');
        const btnRejected = document.getElementById('btnRejected');

        if (btnApproved) {
            btnApproved.onclick = () => {
                showTable(approved, false);
                searchInput.value = '';
            };
        }

        if (btnApplicants) {
            btnApplicants.onclick = () => {
                showTable(applicants, true);
                searchInput.value = '';
            };
        }

        if (btnRejected) {
            btnRejected.onclick = () => {
                showTable(rejected, false);
                searchInput.value = '';
            };
        }

        // Sidebar toggle logic
        const navBar = document.querySelector("nav");
        const navToggle = document.querySelector(".navToggle");
        if (navToggle) {
            navToggle.addEventListener('click', () => {
                navBar.classList.toggle('close');
            });
        }

        // Logout logic
        const logoutDialog = document.getElementById('logoutDialog');
        const logoutLink = document.getElementById('logoutLink');
        const cancelLogout = document.getElementById('cancelLogout');
        const confirmLogoutBtn = document.getElementById('confirmLogoutBtn');

        if (logoutLink) {
            logoutLink.addEventListener('click', (e) => {
                e.preventDefault();
                logoutDialog.style.display = 'flex';
            });
        }

        if (cancelLogout) {
            cancelLogout.addEventListener('click', () => {
                logoutDialog.style.display = 'none';
            });
        }

        if (confirmLogoutBtn) {
            confirmLogoutBtn.addEventListener('click', () => {
                window.location.href = '../logout.php';
            });
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            if (event.target === courseAssignmentPopup) {
                closeCourseAssignmentPopup();
            }
            if (event.target === updateCoursePopup) {
                closeUpdateCoursePopup();
            }
            if (event.target === rejectionModal) {
                closeRejectionModal();
            }
            if (event.target === logoutDialog) {
                logoutDialog.style.display = 'none';
            }
            if (event.target === customAlertModal) {
                closeCustomAlert();
            }
            if (event.target === customConfirmModal) {
                closeCustomConfirm();
            }
        }
        
    </script>
    <script type="module" src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.esm.js"></script>
    <script nomodule src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.js"></script>

</body>
</html>