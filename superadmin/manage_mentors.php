<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

// 1. UPDATED: Ensure only 'Super Admin' can access this page
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

// 2. UPDATED: Set $admin_name to default to 'Super Admin' if first_name is not set
$admin_icon = !empty($_SESSION['superadmin_icon']) ? $_SESSION['superadmin_icon'] : '../uploads/img/default_pfp.png';
$admin_name = !empty($_SESSION['first_name']) ? $_SESSION['first_name'] : 'Super Admin';

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
        if ($old_course_id && $old_course_id !== 'null') { // Handle 'null' string from JS
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
        
        $get_mentor = "SELECT email, CONCAT(first_name, ' ', last_name) AS full_name FROM users WHERE user_id = ?";
        $stmt = $conn->prepare($get_mentor);
        $stmt->bind_param("i", $mentor_id);
        $stmt->execute();
        $stmt->bind_result($mentor_email, $mentor_full_name);
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
                $email->setSubject("Update Regarding Your Mentor Application");
                $email->addTo($mentor_email, $mentor_full_name);
                
                $safe_reason = htmlspecialchars($reason);

                $html_body = "
                <html>
                <head>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 5px; background-color:rgb(241, 223, 252); }
                    .header { background-color: #562b63; padding: 15px; color: white; text-align: center; border-radius: 5px 5px 0 0; }
                    .content { padding: 20px; background-color: #f9f9f9; }
                    .reason-box { background-color: #fff; border: 1px solid #ddd; padding: 15px; margin: 15px 0; border-radius: 5px; }
                    .footer { text-align: center; padding: 10px; font-size: 12px; color: #777; }
                </style>
                </head>
                <body>
                <div class='container'>
                    <div class='header'>
                    <h2>Update Regarding Your Mentor Application</h2>
                    </div>
                    <div class='content'>
                    <p>Dear $mentor_full_name,</p>
                    <p>Thank you for your interest in the COACH program. We have reviewed your application to become a Mentor.</p>
                    <p>After careful consideration, we regret to inform you that your application has been rejected for the following reason:</p>
                    
                    <div class='reason-box'>
                        <p><strong>Reason:</strong> $safe_reason</p>
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

// Fetch all mentor data
$sql = "SELECT user_id, first_name, last_name, dob, gender, email, contact_number, username, mentored_before, mentoring_experience, area_of_expertise, resume, certificates, status, reason FROM users WHERE user_type = 'Mentor'";
$result = $conn->query($sql);

$mentor_data = [];
if ($result && $result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $mentor_data[] = $row;
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/dashboard.css"/>
    <link rel="stylesheet" href="css/navigation.css"/>
    <link rel="icon" href="../uploads/img/coachicon.svg" type="image/svg+xml">
    <title>Manage Mentors | SuperAdmin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* General Layout */
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f4f4f4;
            display: flex;
            min-height: 100vh;
        }

        /* Main Content Area */
        .main-content {
            flex-grow: 1;
            padding: 20px 30px;
        }
        header {
            padding: 10px 0;
            border-bottom: 2px solid #562b63;
            margin-bottom: 20px;
        }
        header h1 {
            color: #562b63;
            margin: 0;
            font-size: 28px;
            margin-top: 30px;
        }
        
        /* Tab Buttons */
        .tab-buttons {
            margin-bottom: 15px;
        }
        .tab-buttons button {
            background-color: #6c757d;
            color: white;
            border: none;
            padding: 10px 20px;
            margin-right: 5px;
            border-radius: 5px;
            cursor: pointer;
            transition: background-color 0.3s, transform 0.1s;
            font-weight: 600;
        }
        .tab-buttons button.active {
            background-color: #562b63;
            transform: translateY(-2px);
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .tab-buttons button:not(.active):hover {
            background-color: #5a6268;
        }
        
        /* Table Styles */
        .table-container {
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.05);
            background-color: #fff;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            border: none;
            padding: 15px;
            text-align: left;
        }
        th {
            background-color: #562b63;
            color: white;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 14px;
        }
        tr:nth-child(even) {
            background-color: #f8f8f8;
        }
        tr:hover {
            background-color: #f1f1f1;
        }
        .action-button {
            background-color: #28a745;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 5px;
            cursor: pointer;
            transition: background-color 0.3s;
            font-weight: 600;
        }
        .action-button:hover {
            background-color: #218838;
        }
        
        /* Details View */
        .details {
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 8px;
            background-color: #fff;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }
        .details h3 {
            color: #562b63;
            border-bottom: 1px solid #ccc;
            padding-bottom: 10px;
            margin-top: 5px;
            margin-bottom: 15px;
        }
        .details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        .details p {
            margin: 5px 0;
            display: flex;
            align-items: center;
        }
        .details strong {
            display: inline-block;
            min-width: 180px;
            color: #333;
            font-weight: 600;
        }
        .details input[type="text"] {
            flex-grow: 1;
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 4px;
            margin-left: 10px;
            background-color: #f9f9f9;
            cursor: default;
        }
        .details a {
            color: #007bff;
            text-decoration: none;
            margin-left: 10px;
            transition: color 0.3s;
        }
        .details a:hover {
            color: #0056b3;
            text-decoration: underline;
        }
        .details-buttons-top {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
        }
        .details-buttons-top button {
            padding: 10px 15px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
            transition: background-color 0.3s;
        }
        .details .back-btn {
            background-color: #6c757d;
            color: white;
        }
        .details .back-btn:hover {
            background-color: #5a6268;
        }
        .details .update-course-btn {
            background-color: #562b63;
            color: white;
        }
        .details .update-course-btn:hover {
            background-color: #43214d;
        }
        .details .action-buttons {
            margin-top: 30px;
            text-align: right;
            border-top: 1px solid #eee;
            padding-top: 15px;
        }
        .details .action-buttons button {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
            transition: background-color 0.3s;
            margin-left: 10px;
        }
        .details .action-buttons button:first-child {
            background-color: #28a745;
            color: white;
        }
        .details .action-buttons button:last-child {
            background-color: #dc3545;
            color: white;
        }
        .hidden {
            display: none;
        }
        
        /* Popup Styles */
        .course-assignment-popup, .custom-alert-popup, .rejection-dialog, #confirmDialog { /* Added #confirmDialog here */
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.6);
            /* Center the content */
            justify-content: center;
            align-items: center;
        }

        .popup-content, .alert-content, .rejection-content, .confirm-content { /* Added .confirm-content here */
            background-color: #fefefe;
            margin: 0 auto; /* Removed margin-top and relying on flex/align-items for vertical centering */
            padding: 30px;
            border: 1px solid #888;
            width: 90%;
            max-width: 450px;
            border-radius: 10px;
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.3);
            animation-name: animatetop;
            animation-duration: 0.4s;
        }
        
        /* Specific styles for the new Confirmation Dialog */
        .confirm-content h3 {
            color: #562b63;
            margin-top: 0;
            border-bottom: 2px solid #ccc;
            padding-bottom: 10px;
            margin-bottom: 20px;
            text-align: center;
        }
        
        .confirm-content #confirmMessage {
            text-align: center;
            font-size: 1.1rem;
            line-height: 1.5;
            margin-bottom: 25px;
        }

        @keyframes animatetop { from {top:-300px; opacity:0} to {top:10%; opacity:1} }
        
        .popup-content h3, .alert-content h3, .rejection-content h3 {
            color: #562b63;
            margin-top: 0;
            border-bottom: 2px solid #ccc;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        .popup-content select, .popup-content input[type="text"], .rejection-content textarea {
            width: 100%;
            padding: 12px;
            margin: 10px 0 20px 0;
            display: inline-block;
            border: 1px solid #ddd;
            border-radius: 6px;
            box-sizing: border-box;
            font-size: 16px;
        }
        .rejection-content textarea {
            min-height: 100px;
            resize: vertical;
        }
        .popup-buttons, .alert-buttons, .rejection-buttons, .confirm-buttons { /* Added .confirm-buttons here */
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 20px;
        }
        .popup-buttons button, .alert-buttons button, .rejection-buttons button, .confirm-buttons button { /* Added .confirm-buttons button here */
            padding: 10px 15px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
            transition: background-color 0.3s;
        }
        .btn-cancel {
            background-color: #6c757d;
            color: white;
        }
        .btn-confirm {
            background-color: #28a745;
            color: white;
        }
        /* Specific confirmation buttons styling */
        .confirm-buttons #cancelConfirmBtn {
            background-color: #6c757d;
            color: white;
        }
        .confirm-buttons #confirmActionBtn {
            background-color: #dc3545; /* Red for removal confirmation */
            color: white;
        }
        .btn-cancel:hover {
            background-color: #5a6268;
        }
        .btn-confirm:hover {
            background-color: #218838;
        }
        .loading {
            text-align: center;
            padding: 20px;
            color: #562b63;
            font-style: italic;
        }
        #updatePopupBody .popup-buttons {
            justify-content: space-between;
        }
        #updatePopupBody .btn-confirm.change-btn {
            background-color: #ffc107;
            color: #333;
        }
        #updatePopupBody .btn-confirm.change-btn:hover {
            background-color: #e0a800;
        }
        #updatePopupBody .btn-confirm.remove-btn {
            background-color: #dc3545;
        }
        #updatePopupBody .btn-confirm.remove-btn:hover {
            background-color: #c82333;
        }
        /* Custom Alert specific styles */
        #customAlertContent h3.success {
            color: #28a745;
        }
        #customAlertContent h3.error {
            color: #dc3545;
        }
        .alert-buttons .btn-ok {
            background-color: #562b63;
            color: white;
        }
        .alert-buttons .btn-ok:hover {
            background-color: #43214d;
        }
    </style>
</head>
<body>
    <nav>
        <div class="nav-top">
            <div class="logo">
                <div class="logo-image"><img src="../uploads/img/logo.png" alt="Logo"></div>
                <div class="logo-name">COACH</div>
            </div>
            <div class="admin-profile">
                <img src="<?php echo htmlspecialchars($admin_icon); ?>" alt="SuperAdmin Profile Picture" />
                <div class="admin-text">
                    <span class="admin-name"><?php echo htmlspecialchars($admin_name); ?></span> 
                    <span class="admin-role">SuperAdmin</span>
                </div>
                <a href="profile.php?username=<?= urlencode($_SESSION['username']) ?>" class="edit-profile-link" title="Edit Profile">
                    <ion-icon name="create-outline" class="verified-icon"></ion-icon>
                </a>
            </div>
        </div>
        <div class="menu-items">
            <ul class="navLinks">
                <li class="navList">
                    <a href="dashboard.php">
                        <ion-icon name="home-outline"></ion-icon>
                        <span class="links">Home</span>
                    </a>
                </li>
                <li class="navList">
                    <a href="moderators.php">
                        <ion-icon name="lock-closed-outline"></ion-icon>
                        <span class="links">Moderators</span>
                    </a>
                </li>
                <li class="navList">
                    <a href="manage_mentees.php">
                        <ion-icon name="person-outline"></ion-icon>
                        <span class="links">Mentees</span>
                    </a>
                </li>
                <li class="navList active">
                    <a href="manage_mentors.php">
                        <ion-icon name="people-outline"></ion-icon>
                        <span class="links">Mentors</span>
                    </a>
                </li>
                <li class="navList">
                    <a href="courses.php">
                        <ion-icon name="book-outline"></ion-icon>
                        <span class="links">Courses</span>
                    </a>
                </li>
                <li class="navList">
                    <a href="manage_session.php">
                        <ion-icon name="calendar-outline"></ion-icon>
                        <span class="links">Sessions</span>
                    </a>
                </li>
                <li class="navList">
                    <a href="feedbacks.php">
                        <ion-icon name="star-outline"></ion-icon>
                        <span class="links">Feedback</span>
                    </a>
                </li>
                <li class="navList">
                    <a href="channels.php">
                        <ion-icon name="chatbox-outline"></ion-icon>
                        <span class="links">Channels</span>
                    </a>
                </li>
            </ul>
        </div>
        <div class="nav-bottom">
            <ul class="navLinks">
                <li class="navList">
                    <a href="settings.php">
                        <ion-icon name="settings-outline"></ion-icon>
                        <span class="links">Settings</span>
                    </a>
                </li>
                <li class="navList">
                    <a href="#" id="logoutBtn">
                        <ion-icon name="log-out-outline"></ion-icon>
                        <span class="links">Logout</span>
                    </a>
                </li>
            </ul>
        </div>
    </nav>
    
    <div class="main-content">
        <header>
            <h1>Manage Mentors</h1>
        </header>

        <div class="tab-buttons">
            <button id="btnApproved">Approved Mentors</button>
            <button id="btnApplicants">New Applicants (<span id="applicantCount">0</span>)</button>
            <button id="btnRejected">Rejected Mentors</button>
        </div>

        <div class="table-container" id="mentorsTableContainer">
            </div>

        <div class="details hidden" id="mentorDetails">
            </div>
    </div>
    
    <div id="updateCoursePopup" class="course-assignment-popup" style="display: none;">
        <div class="popup-content">
            <h3 id="updatePopupTitle">Update Course Assignment</h3>
            <div id="updatePopupBody">
                <div id="currentAssignment">
                    <p><strong>Current Assigned Course:</strong> <span id="currentCourseTitle"></span></p>
                </div>
                <div id="noAssignment" style="display: none;">
                    <p><strong>Current Status:</strong> <span style="color: red;">No Course Assigned</span></p>
                </div>

                <h4>Change Assignment to:</h4>
                <div class="loading" id="courseLoading">Fetching courses...</div>
                <select id="newCourseSelect" disabled>
                    <option value="">Select a new course</option>
                </select>
                <div id="noAvailableCourses" style="color: red; margin-top: 10px; display: none;">No other courses are currently available for assignment.</div>
                
                <div class="popup-buttons">
                    <button id="cancelUpdateBtn" type="button" class="btn-cancel">Cancel</button>
                    <button id="performChangeBtn" type="button" class="btn-confirm change-btn" disabled>Change Assignment</button>
                </div>
            </div>
        </div>
    </div>

    <div id="courseAssignmentPopup" class="course-assignment-popup" style="display: none;">
        <div class="popup-content">
            <h3>Assign Course & Approve Mentor</h3>
            <div class="loading" id="initialCourseLoading">Fetching available courses...</div>
            <select id="assignCourseSelect" required disabled>
                <option value="">Select a course to assign</option>
            </select>
            <div id="noInitialAvailableCourses" style="color: red; margin-top: 10px; display: none;">No courses are currently available for assignment.</div>
            
            <div class="popup-buttons">
                <button id="cancelAssignmentBtn" type="button" class="btn-cancel">Cancel</button>
                <button id="confirmAssignmentBtn" type="button" class="btn-confirm" disabled>Approve & Assign</button>
            </div>
        </div>
    </div>
    
    <div id="rejectionDialog" class="rejection-dialog" style="display: none;">
        <div class="rejection-content">
            <h3>Reject Mentor Application</h3>
            <p>Please provide a reason for rejecting the mentor application. This reason will be emailed to the applicant.</p>
            <textarea id="rejectionReason" placeholder="Enter rejection reason..." required></textarea>
            <div class="rejection-buttons">
                <button id="cancelRejectionBtn" type="button" class="btn-cancel">Cancel</button>
                <button id="confirmRejectionBtn" type="button" class="btn-confirm" disabled>Confirm Rejection</button>
            </div>
        </div>
    </div>

    <div id="confirmDialog" class="custom-alert-popup" style="display: none;">
        <div class="confirm-content">
            <h3>Confirm Action</h3>
            <p id="confirmMessage"></p>
            <div class="confirm-buttons">
                <button id="cancelConfirmBtn" type="button" class="btn-cancel">Cancel</button>
                <button id="confirmActionBtn" type="button" class="btn-confirm">Confirm</button>
            </div>
        </div>
    </div>
    
    <div id="customAlertPopup" class="custom-alert-popup" style="display: none;">
        <div class="alert-content"> 
            <h3 id="alertTitle"></h3>
            <p id="alertMessage"></p>
            <div class="alert-buttons">
                <button id="alertOkBtn" type="button" class="btn-ok">OK</button>
                <button id="alertConfirmBtn" type="button" class="btn-confirm" style="display: none;">Confirm</button>
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
    // PHP data injection
    const mentorData = <?php echo json_encode($mentor_data); ?>;

    // Filter mentor data
    const approved = mentorData.filter(m => m.status === 'Approved');
    const applicants = mentorData.filter(m => m.status === 'Under Review');
    const rejected = mentorData.filter(m => m.status === 'Rejected');

    // DOM Elements
    const mentorsTableContainer = document.getElementById('mentorsTableContainer');
    const mentorDetailsDiv = document.getElementById('mentorDetails');
    const btnApproved = document.getElementById('btnApproved');
    const btnApplicants = document.getElementById('btnApplicants');
    const btnRejected = document.getElementById('btnRejected');
    const applicantCountSpan = document.getElementById('applicantCount');
    
    // Popups
    const courseAssignmentPopup = document.getElementById('courseAssignmentPopup');
    const updateCoursePopup = document.getElementById('updateCoursePopup');
    const rejectionDialog = document.getElementById('rejectionDialog');
    const customAlertPopup = document.getElementById('customAlertPopup');
    const confirmDialog = document.getElementById('confirmDialog'); // The new confirmation dialog
    
    // Global state for popups
    let currentMentorId = null;
    let currentCourseId = null;
    let confirmCallback = null;
    let reloadAfterAlert = false;
    
    // Set initial applicant count
    applicantCountSpan.textContent = applicants.length;

    // --- Helper Functions for Custom Popups ---

    /**
     * Shows the generic success dialog.
     * @param {string} message 
     * @param {boolean} reload Optional: If true, reloads page on OK click. Default is true.
     */
    function showSuccessDialog(message, reload = true) {
        document.getElementById('alertTitle').textContent = 'Success!';
        document.getElementById('alertTitle').className = 'success';
        document.getElementById('alertMessage').innerHTML = message;
        document.getElementById('alertConfirmBtn').style.display = 'none'; // Hide confirm button
        document.getElementById('alertOkBtn').style.display = 'block';
        customAlertPopup.style.display = 'flex';
        reloadAfterAlert = reload; // Set reload flag
    }

    /**
     * Shows the generic error dialog.
     * @param {string} message 
     * @param {boolean} reload Optional: If true, reloads page on OK click. Default is false.
     */
    function showErrorDialog(message, reload = false) {
        document.getElementById('alertTitle').textContent = 'Error!';
        document.getElementById('alertTitle').className = 'error';
        document.getElementById('alertMessage').innerHTML = message;
        document.getElementById('alertConfirmBtn').style.display = 'none'; // Hide confirm button
        document.getElementById('alertOkBtn').style.display = 'block';
        customAlertPopup.style.display = 'flex';
        reloadAfterAlert = reload; // Set reload flag
    }

    // New custom confirmation dialog function
    function showConfirmDialog(message, callback) {
        document.getElementById('confirmTitle').textContent = 'Confirm Action';
        document.getElementById('confirmMessage').innerHTML = message;
        confirmDialog.style.display = 'flex';
        
        confirmCallback = callback;
        
        document.getElementById('cancelConfirmBtn').onclick = () => {
            confirmDialog.style.display = 'none';
        };
        
        document.getElementById('confirmActionBtn').onclick = () => {
            confirmDialog.style.display = 'none';
            if (confirmCallback) {
                confirmCallback();
            }
        };
    }


    document.getElementById('alertOkBtn').onclick = () => {
        closeCustomAlert();
        if (reloadAfterAlert) {
            window.location.reload();
        }
    };

    function closeCustomAlert() {
        customAlertPopup.style.display = 'none';
        reloadAfterAlert = false;
    }
    
    function closeCourseAssignmentPopup() {
        courseAssignmentPopup.style.display = 'none';
        document.getElementById('assignCourseSelect').value = '';
    }
    
    function closeUpdateCoursePopup() {
        updateCoursePopup.style.display = 'none';
        document.getElementById('newCourseSelect').value = '';
    }

    function showRejectionDialog(mentorId) {
        currentMentorId = mentorId;
        rejectionDialog.style.display = 'flex';
        document.getElementById('rejectionReason').value = '';
        document.getElementById('confirmRejectionBtn').disabled = true;
    }
    
    function closeRejectionDialog() {
        rejectionDialog.style.display = 'none';
        currentMentorId = null;
    }

    // --- Main Logic Functions ---

    function showTable(data, isApplicant) {
        // ... (Show table logic - unchanged) ...
        const tableHtml = `
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Email</th>
                        ${isApplicant ? '<th>Status</th>' : '<th>Assigned Course</th>'}
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    ${data.map(mentor => `
                        <tr>
                            <td>${mentor.user_id}</td>
                            <td>${mentor.first_name} ${mentor.last_name}</td>
                            <td>${mentor.email}</td>
                            ${isApplicant 
                                ? `<td class="status-cell">${mentor.status}</td>` 
                                : `<td id="course-${mentor.user_id}">Loading...</td>`
                            }
                            <td>
                                <button class="action-button" onclick="viewDetails(${mentor.user_id}, ${isApplicant})">View Details</button>
                            </td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
        `;

        mentorsTableContainer.innerHTML = tableHtml;
        mentorDetailsDiv.classList.add('hidden');
        mentorsTableContainer.classList.remove('hidden');
        
        // Fetch course assignments for approved mentors
        if (!isApplicant) {
            data.forEach(mentor => fetchAssignedCourse(mentor.user_id));
        }
        
        // Update active button
        btnApproved.classList.remove('active');
        btnApplicants.classList.remove('active');
        btnRejected.classList.remove('active');
        
        if (isApplicant === true && data === applicants) {
            btnApplicants.classList.add('active');
        } else if (isApplicant === false && data === rejected) {
            btnRejected.classList.add('active');
        } else {
            btnApproved.classList.add('active');
        }
    }

    function fetchAssignedCourse(mentorId) {
        // ... (fetchAssignedCourse logic - unchanged) ...
        const courseCell = document.getElementById(`course-${mentorId}`);
        if (!courseCell) return;
        
        fetch(`manage_mentors.php?action=get_assigned_course&mentor_id=${mentorId}`)
            .then(response => response.json())
            .then(data => {
                const mentor = approved.find(m => m.user_id == mentorId);
                const assignedCourseId = data ? data.Course_ID : null;
                const assignedCourseTitle = data ? data.Course_Title : 'None Assigned';
                
                courseCell.innerHTML = assignedCourseTitle;
                
                // Update the mentor object with course info for easy access in viewDetails
                if (mentor) {
                    mentor.assigned_course_id = assignedCourseId;
                    mentor.assigned_course_title = assignedCourseTitle;
                }

            })
            .catch(error => {
                courseCell.textContent = 'Error fetching course';
                console.error('Error fetching course:', error);
            });
    }

    function viewDetails(mentorId, isApplicant) {
        // ... (viewDetails logic - unchanged) ...
        const mentor = mentorData.find(m => m.user_id == mentorId);
        if (!mentor) return;
        
        currentMentorId = mentorId;
        
        // Format resume/certificates links
        const formatFileLink = (filename) => {
            if (!filename || filename === 'NULL') return 'None Provided';
            const basename = filename.split('/').pop();
            return `<a href="${filename}" target="_blank" title="${basename}">Download File</a>`;
        };

        const detailsHtml = `
            <div class="details-buttons-top">
                <button class="back-btn" onclick="hideDetails()">&#8592; Back to List</button>
                ${mentor.status === 'Approved' ? `
                    <button class="update-course-btn" onclick="openUpdateCoursePopup(${mentorId})">Update Course Assignment</button>
                ` : ''}
            </div>
            
            <h3>Mentor Details</h3>
            
            <div class="details-grid">
                <p><strong>Name:</strong> ${mentor.first_name} ${mentor.last_name}</p>
                <p><strong>Email:</strong> ${mentor.email}</p>
                <p><strong>Username:</strong> ${mentor.username}</p>
                <p><strong>Contact No.:</strong> ${mentor.contact_number}</p>
                <p><strong>Date of Birth:</strong> ${mentor.dob}</p>
                <p><strong>Gender:</strong> ${mentor.gender}</p>
            </div>

            <h3>Experience & Expertise</h3>
            <div class="details-grid">
                <p><strong>Mentored Before:</strong> ${mentor.mentored_before === '1' ? 'Yes' : 'No'}</p>
                <p><strong>Mentoring Experience:</strong> ${mentor.mentoring_experience || 'N/A'}</p>
                <p><strong>Area of Expertise:</strong> ${mentor.area_of_expertise}</p>
                <p><strong>Resume:</strong> ${formatFileLink(mentor.resume)}</p>
                <p><strong>Certificates:</strong> ${formatFileLink(mentor.certificates)}</p>
                <p><strong>Application Status:</strong> <span style="font-weight: bold; color: ${mentor.status === 'Under Review' ? '#ffc107' : mentor.status === 'Approved' ? '#28a745' : '#dc3545'};">${mentor.status}</span></p>
                ${mentor.status === 'Rejected' ? `<p><strong>Rejection Reason:</strong> ${mentor.reason || 'N/A'}</p>` : ''}
            </div>
            
            ${isApplicant ? `
                <div class="action-buttons">
                    <button type="button" onclick="openAssignmentPopup(${mentorId})">Approve & Assign Course</button>
                    <button type="button" onclick="showRejectionDialog(${mentorId})">Reject Application</button>
                </div>
            ` : ''}
        `;

        mentorDetailsDiv.innerHTML = detailsHtml;
        mentorsTableContainer.classList.add('hidden');
        mentorDetailsDiv.classList.remove('hidden');
    }

    function hideDetails() {
        mentorDetailsDiv.classList.add('hidden');
        mentorsTableContainer.classList.remove('hidden');
        currentMentorId = null;
    }
    
    // --- Course Management Popups ---

    function openAssignmentPopup(mentorId) {
        // ... (openAssignmentPopup logic - unchanged) ...
        currentMentorId = mentorId;
        closeUpdateCoursePopup();
        
        document.getElementById('assignCourseSelect').innerHTML = '<option value="">Select a course to assign</option>';
        document.getElementById('noInitialAvailableCourses').style.display = 'none';
        document.getElementById('initialCourseLoading').style.display = 'block';
        document.getElementById('assignCourseSelect').disabled = true;
        document.getElementById('confirmAssignmentBtn').disabled = true;
        courseAssignmentPopup.style.display = 'flex';

        fetch('manage_mentors.php?action=get_available_courses')
            .then(response => response.json())
            .then(data => {
                document.getElementById('initialCourseLoading').style.display = 'none';
                
                if (data.length > 0) {
                    data.forEach(course => {
                        const option = document.createElement('option');
                        option.value = course.Course_ID;
                        option.textContent = course.Course_Title;
                        document.getElementById('assignCourseSelect').appendChild(option);
                    });
                    document.getElementById('assignCourseSelect').disabled = false;
                } else {
                    document.getElementById('noInitialAvailableCourses').style.display = 'block';
                }
            })
            .catch(error => {
                showErrorDialog('Error fetching available courses: ' + error, false);
                document.getElementById('initialCourseLoading').style.display = 'none';
            });
    }

    document.getElementById('assignCourseSelect').onchange = function() {
        document.getElementById('confirmAssignmentBtn').disabled = !this.value;
    };

    document.getElementById('confirmAssignmentBtn').onclick = function() {
        // ... (confirmAssignmentBtn logic - unchanged) ...
        const courseId = document.getElementById('assignCourseSelect').value;
        if (!currentMentorId || !courseId) return;

        closeCourseAssignmentPopup();

        fetch('manage_mentors.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=approve_with_course&mentor_id=${currentMentorId}&course_id=${courseId}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showSuccessDialog(data.message);
            } else {
                showErrorDialog(data.message);
            }
        })
        .catch(error => {
            showErrorDialog('Network error during approval: ' + error);
        });
    };

    // Rejection confirmation logic
    document.getElementById('rejectionReason').oninput = function() {
        document.getElementById('confirmRejectionBtn').disabled = this.value.trim().length === 0;
    };
    
    document.getElementById('cancelRejectionBtn').onclick = closeRejectionDialog;

    document.getElementById('confirmRejectionBtn').onclick = function() {
        // ... (confirmRejectionBtn logic - unchanged) ...
        const reason = document.getElementById('rejectionReason').value.trim();
        if (!currentMentorId || reason.length === 0) return;

        closeRejectionDialog();

        fetch('manage_mentors.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=reject_mentor&mentor_id=${currentMentorId}&reason=${encodeURIComponent(reason)}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showSuccessDialog(data.message);
            } else {
                showErrorDialog(data.message);
            }
        })
        .catch(error => {
            showErrorDialog('Network error during rejection: ' + error);
        });
    };
    
    function openUpdateCoursePopup(mentorId) {
        // ... (openUpdateCoursePopup logic - unchanged) ...
        currentMentorId = mentorId;
        const mentor = approved.find(m => m.user_id == mentorId);
        
        if (!mentor) return;
        
        currentCourseId = mentor.assigned_course_id;
        
        document.getElementById('updatePopupTitle').textContent = `Update Assignment for ${mentor.first_name} ${mentor.last_name}`;
        
        document.getElementById('newCourseSelect').innerHTML = '<option value="">Select a new course</option>';
        document.getElementById('noAvailableCourses').style.display = 'none';
        document.getElementById('courseLoading').style.display = 'block';
        document.getElementById('newCourseSelect').disabled = true;
        document.getElementById('performChangeBtn').disabled = true;
        
        const currentCourseTitleEl = document.getElementById('currentCourseTitle');
        const removeBtnEl = document.getElementById('performChangeBtn');
        const currentAssignmentDiv = document.getElementById('currentAssignment');
        const noAssignmentDiv = document.getElementById('noAssignment');
        
        if (currentCourseId) {
            currentCourseTitleEl.textContent = mentor.assigned_course_title;
            currentAssignmentDiv.style.display = 'block';
            noAssignmentDiv.style.display = 'none';
            removeBtnEl.textContent = 'Remove Assignment';
            removeBtnEl.classList.add('remove-btn');
            removeBtnEl.classList.remove('change-btn');
            removeBtnEl.onclick = () => initiateConfirmRemoveCourse(mentorId, currentCourseId, mentor.assigned_course_title); // Uses new confirmation dialog
        } else {
            currentAssignmentDiv.style.display = 'none';
            noAssignmentDiv.style.display = 'block';
            removeBtnEl.textContent = 'Change Assignment';
            removeBtnEl.classList.add('change-btn');
            removeBtnEl.classList.remove('remove-btn');
            removeBtnEl.onclick = performChangeCourse;
        }

        updateCoursePopup.style.display = 'flex';
        
        // Fetch only UNASSIGNED courses (excluding the mentor's currently assigned course if any)
        fetch('manage_mentors.php?action=get_available_courses')
            .then(response => response.json())
            .then(data => {
                document.getElementById('courseLoading').style.display = 'none';
                
                if (data.length > 0) {
                    data.forEach(course => {
                        const option = document.createElement('option');
                        option.value = course.Course_ID;
                        option.textContent = course.Course_Title;
                        document.getElementById('newCourseSelect').appendChild(option);
                    });
                    document.getElementById('newCourseSelect').disabled = false;
                } else {
                    document.getElementById('noAvailableCourses').style.display = 'block';
                }
            })
            .catch(error => {
                showErrorDialog('Error fetching available courses: ' + error, false);
                document.getElementById('courseLoading').style.display = 'none';
            });
    }

    document.getElementById('newCourseSelect').onchange = function() {
        const removeBtnEl = document.getElementById('performChangeBtn');
        if (this.value) {
            // New course selected: action is always 'Change Assignment'
            removeBtnEl.textContent = 'Change Assignment';
            removeBtnEl.classList.add('change-btn');
            removeBtnEl.classList.remove('remove-btn');
            removeBtnEl.disabled = false;
            removeBtnEl.onclick = performChangeCourse;
        } else if (currentCourseId) {
            // No new course selected, but there is a current course: action is 'Remove Assignment'
            removeBtnEl.textContent = 'Remove Assignment';
            removeBtnEl.classList.remove('change-btn');
            removeBtnEl.classList.add('remove-btn');
            removeBtnEl.disabled = false;
            const mentor = approved.find(m => m.user_id == currentMentorId);
            removeBtnEl.onclick = () => initiateConfirmRemoveCourse(currentMentorId, currentCourseId, mentor.assigned_course_title); // Uses new confirmation dialog
        } else {
            // No new course selected, and no current course: disable button
            removeBtnEl.textContent = 'Change Assignment';
            removeBtnEl.classList.add('change-btn');
            removeBtnEl.classList.remove('remove-btn');
            removeBtnEl.disabled = true;
        }
    };
    
    // Function to initiate the custom confirmation for course removal
    function initiateConfirmRemoveCourse(mentorId, courseId, courseTitle) {
        closeUpdateCoursePopup();
        const mentor = mentorData.find(m => m.user_id == mentorId);
        // Custom message for the new dialog, including HTML for styling (bold, line breaks)
        const message = `Are you sure you want to **REMOVE** ${mentor.first_name}'s assignment from the course: <br><strong>"${courseTitle}"</strong>? <br><br>The course will become available for assignment.`;
        
        // Calls the new custom confirmation dialog
        showConfirmDialog(message, () => {
             // This is the callback for when 'Confirm' is pressed in the custom dialog
             performRemoveCourse(mentorId, courseId);
        });
    }

    function performRemoveCourse(mentorId, courseId) {
        // ... (performRemoveCourse logic - AJAX call) ...
        fetch('manage_mentors.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=remove_assigned_course&mentor_id=${mentorId}&course_id=${courseId}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showSuccessDialog(data.message);
            } else {
                showErrorDialog(data.message);
            }
        })
        .catch(error => {
            showErrorDialog('Network error during removal: ' + error);
        });
    }
    
    function performChangeCourse() {
        // ... (performChangeCourse logic - unchanged) ...
        const newCourseId = document.getElementById('newCourseSelect').value;
        if (!currentMentorId || !newCourseId) return;

        closeUpdateCoursePopup();

        fetch('manage_mentors.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=change_course&mentor_id=${currentMentorId}&old_course_id=${currentCourseId || 'null'}&new_course_id=${newCourseId}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showSuccessDialog(data.message);
            } else {
                showErrorDialog(data.message);
            }
        })
        .catch(error => {
            showErrorDialog('Network error during course change: ' + error);
        });
    }

    // --- Event Listeners & Initialization ---

    btnApproved.onclick = () => {
        showTable(approved, false);
    };

    btnApplicants.onclick = () => {
        showTable(applicants, true);
    };

    btnRejected.onclick = () => {
        showTable(rejected, false);
    };

    document.addEventListener('DOMContentLoaded', () => {
        if (applicants.length > 0) {
            showTable(applicants, true);
        } else {
            showTable(approved, false);
        }
        
        // Ensure the Rejection Dialog's title/message elements are correctly assigned for the new confirmation logic
        // This is necessary because it shares the same general styles as rejection/alert dialogs.
        document.getElementById('alertTitle').id = 'customAlertTitle'; // Rename to avoid conflict
        document.getElementById('alertMessage').id = 'customAlertMessage'; // Rename to avoid conflict
    });

    const navBar = document.querySelector("nav");
    const navToggle = document.querySelector(".navToggle");
    if (navToggle) {
        navToggle.addEventListener('click', () => {
            navBar.classList.toggle('close');
        });
    }

    window.onclick = function(event) {
        if (event.target === courseAssignmentPopup) {
            closeCourseAssignmentPopup();
        }
        if (event.target === updateCoursePopup) {
            closeUpdateCoursePopup();
        }
        if (event.target === rejectionDialog) {
            closeRejectionDialog();
        }
        if (event.target === confirmDialog) {
            // Allow closing the custom confirmation dialog by clicking outside
            confirmDialog.style.display = 'none';
        }
        if (event.target === customAlertPopup) {
            // Only allow clicking outside if not pending a reload (i.e., simple error/success)
            if (!reloadAfterAlert) { 
                closeCustomAlert();
            }
        }
    }

</script>
<script type="module" src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.esm.js"></script>
<script nomodule src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.js"></script>
</body>
</html>