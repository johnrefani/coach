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
$sql = "SELECT user_id, first_name, last_name, dob, gender, email, contact_number, username, mentored_before, mentoring_experience, area_of_expertise, resume, certificates, credentials, status, reason FROM users WHERE user_type = 'Mentor'";
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
        .course-assignment-popup {
            display: none; 
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.6);
        }
        .popup-content {
            background-color: #fefefe;
            margin: 10% auto;
            padding: 30px;
            border: 1px solid #888;
            width: 90%;
            max-width: 450px;
            border-radius: 10px;
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.3);
            animation-name: animatetop;
            animation-duration: 0.4s;
        }
        @keyframes animatetop {
            from {top:-300px; opacity:0} 
            to {top:10%; opacity:1}
        }
        .popup-content h3 {
            color: #562b63;
            margin-top: 0;
            border-bottom: 2px solid #ccc;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        .popup-content select, .popup-content input[type="text"] {
            width: 100%;
            padding: 12px;
            margin: 10px 0 20px 0;
            display: inline-block;
            border: 1px solid #ddd;
            border-radius: 6px;
            box-sizing: border-box;
            font-size: 16px;
        }
        .popup-buttons {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 20px;
        }
        .popup-buttons button {
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
        .btn-cancel:hover { background-color: #5a6268; }
        .btn-confirm:hover { background-color: #218838; }

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
          <span class="admin-name"><?php echo htmlspecialchars($_SESSION['superadmin_name']); ?></span>
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
            <a href="manage_mentees.php"> <ion-icon name="person-outline"></ion-icon>
              <span class="links">Mentees</span>
            </a>
        </li>
        <li class="navList active">
            <a href="manage_mentors.php"> <ion-icon name="people-outline"></ion-icon>
              <span class="links">Mentors</span>
            </a>
        </li>
        <li class="navList">
            <a href="courses.php"> <ion-icon name="book-outline"></ion-icon>
                <span class="links">Courses</span>
            </a>
        </li>
        <li class="navList">
            <a href="manage_session.php"> <ion-icon name="calendar-outline"></ion-icon>
              <span class="links">Sessions</span>
            </a>
        </li>
        <li class="navList"> 
            <a href="feedbacks.php"> <ion-icon name="star-outline"></ion-icon>
              <span class="links">Feedback</span>
            </a>
        </li>
        <li class="navList">
            <a href="channels.php"> <ion-icon name="chatbubbles-outline"></ion-icon>
              <span class="links">Channels</span>
            </a>
        </li>
        <li class="navList">
           <a href="activities.php"> <ion-icon name="clipboard"></ion-icon>
              <span class="links">Activities</span>
            </a>
        </li>
        <li class="navList">
            <a href="resource.php"> <ion-icon name="library-outline"></ion-icon>
              <span class="links">Resource Library</span>
            </a>
        </li>
        <li class="navList">
            <a href="reports.php"><ion-icon name="folder-outline"></ion-icon>
              <span class="links">Reported Posts</span>
            </a>
        </li>
        <li class="navList">
            <a href="banned-users.php"><ion-icon name="person-remove-outline"></ion-icon>
              <span class="links">Banned Users</span>
            </a>
        </li>
      </ul>

   <ul class="bottom-link">
  <li class="navList logout-link">
    <a href="#" onclick="confirmLogout(event)">
      <ion-icon name="log-out-outline"></ion-icon>
      <span class="links">Logout</span>
    </a>
  </li>
</ul>
    </div>
  </nav>

  <section class="dashboard">
    <div class="top">
      <ion-icon class="navToggle" name="menu-outline"></ion-icon>
      <img src="../uploads/img/logo.png" alt="Logo">
    </div>

<div class="main-content">
    <header>
        <h1>Manage Mentors</h1>
    </header>

    <div class="tab-buttons">
        <button id="btnApplicants"><i class="fas fa-user-clock"></i> New Applicants</button>
        <button id="btnMentors"><i class="fas fa-user-check"></i> Approved Mentors</button>
        <button id="btnRejected"><i class="fas fa-user-slash"></i> Rejected Mentors</button>
    </div>

    <section>
        <div id="tableContainer" class="table-container"></div>
        <div id="detailView" class="hidden"></div>
    </section>
</div>

<div id="courseAssignmentPopup" class="course-assignment-popup">
    <div class="popup-content">
        <h3>Assign Course to Mentor</h3>
        <div id="popupBody">
            <div class="loading">Loading available courses...</div>
        </div>
    </div>
</div>

<div id="updateCoursePopup" class="course-assignment-popup">
    <div class="popup-content">
        <h3>Update Assigned Course</h3>
        <div id="updatePopupBody">
            <div class="loading">Loading course details...</div>
        </div>
    </div>
</div>

<div id="courseChangePopup" class="course-assignment-popup">
    <div class="popup-content">
        <h3>Change Assigned Course</h3>
        <div id="changePopupBody">
            <div class="loading">Loading available courses...</div>
        </div>
    </div>
</div>

</section>
<script src="js/navigation.js"></script>
<script>
    const mentorData = <?php echo json_encode($mentor_data); ?>;
    const tableContainer = document.getElementById('tableContainer');
    const detailView = document.getElementById('detailView');
    const courseAssignmentPopup = document.getElementById('courseAssignmentPopup');
    const btnApplicants = document.getElementById('btnApplicants');
    const btnMentors = document.getElementById('btnMentors');
    const btnRejected = document.getElementById('btnRejected');

    const applicants = mentorData.filter(m => m.status === 'Under Review');
    const approved = mentorData.filter(m => m.status === 'Approved');
    const rejected = mentorData.filter(m => m.status === 'Rejected');

    const updateCoursePopup = document.getElementById('updateCoursePopup');
    const courseChangePopup = document.getElementById('courseChangePopup');

    function showTable(data, isApplicantView) {
        detailView.classList.add('hidden');
        tableContainer.classList.remove('hidden');

        btnApplicants.classList.remove('active');
        btnMentors.classList.remove('active');
        btnRejected.classList.remove('active');
        
        if (data === applicants) {
            btnApplicants.classList.add('active');
        } else if (data === approved) {
            btnMentors.classList.add('active');
        } else if (data === rejected) {
            btnRejected.classList.add('active');
        }

        let html = '<table><thead><tr><th>Name</th><th>Email</th><th>Status</th><th>Action</th></tr></thead><tbody>';
        
        if (data.length === 0) {
            html += `<tr><td colspan="4" style="text-align: center; padding: 20px;">No mentors found in this category.</td></tr>`;
        } else {
            data.forEach(mentor => {
                html += `
                    <tr>
                        <td>${mentor.first_name} ${mentor.last_name}</td>
                        <td>${mentor.email}</td>
                        <td>${mentor.status}</td>
                        <td><button class="action-button" onclick="viewDetails(${mentor.user_id}, ${isApplicantView})">View Details</button></td>
                    </tr>
                `;
            });
        }
        
        html += '</tbody></table>';
        tableContainer.innerHTML = html;
    }

    function viewDetails(id, isApplicant) {
        const row = mentorData.find(m => m.user_id == id);
        if (!row) return;

        let resumeLink = row.resume ? `<a href="view_application.php?file=${encodeURIComponent(row.resume)}&type=resume" target="_blank"><i class="fas fa-file-alt"></i> View Resume</a>` : "N/A";
        let certLink = row.certificates ? `<a href="view_application.php?file=${encodeURIComponent(row.certificates)}&type=certificate" target="_blank"><i class="fas fa-certificate"></i> View Certificate</a>` : "N/A";
        let credentialsLink = row.credentials ? `<a href="view_application.php?file=${encodeURIComponent(row.credentials)}&type=credentials" target="_blank"><i class="fas fa-credentials"></i> View Credentials</a>` : "N/A";  
        let html = `<div class="details">
            <div class="details-buttons-top">
                <button onclick="backToTable()" class="back-btn"><i class="fas fa-arrow-left"></i> Back</button>`;
            
        if (row.status === 'Approved') {
            html += `<button onclick="showUpdateCoursePopup(${id})" class="update-course-btn"><i class="fas fa-exchange-alt"></i> Update Assigned Course</button>`;
        }
            
        html += `</div>
            <h3>Applicant Details: ${row.first_name} ${row.last_name}</h3>
            <div class="details-grid">
                <p><strong>Status:</strong> <input type="text" readonly value="${row.status || ''}"></p>
                <p><strong>Reason for Rejection:</strong> <input type="text" readonly value="${row.reason || ''}"></p>
                <p><strong>First Name:</strong> <input type="text" readonly value="${row.first_name || ''}"></p>
                <p><strong>Last Name:</strong> <input type="text" readonly value="${row.last_name || ''}"></p>
                <p><strong>Email:</strong> <input type="text" readonly value="${row.email || ''}"></p>
                <p><strong>Contact:</strong> <input type="text" readonly value="${row.contact_number || ''}"></p>
                <p><strong>Username:</strong> <input type="text" readonly value="${row.username || ''}"></p>
                <p><strong>DOB:</strong> <input type="text" readonly value="${row.dob || ''}"></p>
                <p><strong>Gender:</strong> <input type="text" readonly value="${row.gender || ''}"></p>
                <p><strong>Mentored Before:</strong> <input type="text" readonly value="${row.mentored_before || ''}"></p>
                <p><strong>Experience (Years):</strong> <input type="text" readonly value="${row.mentoring_experience || ''}"></p>
                <p><strong>Expertise:</strong> <input type="text" readonly value="${row.area_of_expertise || ''}"></p>
            </div>
            <p style="grid-column: 1 / -1; margin-top: 20px;"><strong>Application Files:</strong> ${resumeLink} | ${certLink}</p>`;

        if (isApplicant) {
            html += `<div class="action-buttons">
                 <button onclick="showCourseAssignmentPopup(${id})"><i class="fas fa-check-circle"></i> Approve & Assign Course</button>
                <button onclick="showRejectionDialog(${id})"><i class="fas fa-times-circle"></i> Reject</button>
            </div>`;
        }

        html += '</div>';
        detailView.innerHTML = html;
        detailView.classList.remove('hidden');
        tableContainer.classList.add('hidden');
    }

    function backToTable() {
        detailView.classList.add('hidden');
        tableContainer.classList.remove('hidden');
        if (btnApplicants.classList.contains('active')) {
            showTable(applicants, true);
        } else if (btnMentors.classList.contains('active')) {
            showTable(approved, false);
        } else if (btnRejected.classList.contains('active')) {
            showTable(rejected, false);
        }
    }

    function showCourseAssignmentPopup(mentorId) {
        const mentor = mentorData.find(m => m.user_id == mentorId);
        if (!mentor) return;
        
        closeUpdateCoursePopup();
        
        document.getElementById('popupBody').innerHTML = `<div class="loading"><i class="fas fa-sync fa-spin"></i> Loading available courses...</div>`;
        courseAssignmentPopup.style.display = 'block';

        fetch('?action=get_available_courses')
            .then(response => response.json())
            .then(courses => {
                let popupContent = '';
                
                if (courses.length === 0) {
                    popupContent = `
                        <p>No available courses found to assign to <strong>${mentor.first_name} ${mentor.last_name}</strong>. All courses are currently assigned.</p>
                        <div class="popup-buttons">
                            <button type="button" class="btn-cancel" onclick="closeCourseAssignmentPopup()"><i class="fas fa-times"></i> Close</button>
                        </div>
                    `;
                } else {
                    popupContent = `
                        <p>Assign <strong>${mentor.first_name} ${mentor.last_name}</strong> to the following course:</p>
                        <form id="courseAssignmentForm">
                            <div class="form-group">
                                <label for="courseSelect">Available Courses:</label>
                                <select id="courseSelect" name="course_id" required>
                                    <option value="">-- Select a Course --</option>
                    `;
                    
                    courses.forEach(course => {
                        popupContent += `<option value="${course.Course_ID}">${course.Course_Title}</option>`;
                    });
                    
                    popupContent += `
                                </select>
                            </div>
                            <div class="popup-buttons">
                                <button type="button" class="btn-cancel" onclick="closeCourseAssignmentPopup()"><i class="fas fa-times"></i> Cancel</button>
                                <button type="button" class="btn-confirm" onclick="confirmCourseAssignment(${mentorId})"><i class="fas fa-check"></i> Approve & Assign</button>
                            </div>
                        </form>
                    `;
                }
                
                document.getElementById('popupBody').innerHTML = popupContent;
            })
            .catch(error => {
                console.error('Error fetching courses:', error);
                document.getElementById('popupBody').innerHTML = `
                    <p>Error loading courses. Please try again.</p>
                    <div class="popup-buttons">
                        <button type="button" class="btn-cancel" onclick="closeCourseAssignmentPopup()"><i class="fas fa-times"></i> Close</button>
                    </div>
                `;
            });
    }

    function closeCourseAssignmentPopup() {
        courseAssignmentPopup.style.display = 'none';
    }

    function confirmCourseAssignment(mentorId) {
        const form = document.getElementById('courseAssignmentForm');
        const courseId = form.course_id.value;
        
        if (!courseId) {
            alert('Please select a course.');
            return;
        }

        const confirmButton = document.querySelector('#courseAssignmentPopup .btn-confirm');
        confirmButton.disabled = true;
        confirmButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';

        const formData = new FormData();
        formData.append('action', 'approve_with_course');
        formData.append('mentor_id', mentorId);
        formData.append('course_id', courseId);

        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert(data.message + ' Refreshing page...');
                location.reload();
            } else {
                alert('Approval failed: ' + data.message);
                confirmButton.disabled = false;
                confirmButton.innerHTML = '<i class="fas fa-check"></i> Approve & Assign';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred during approval. Please try again.');
            confirmButton.disabled = false;
            confirmButton.innerHTML = '<i class="fas fa-check"></i> Approve & Assign';
        });
    }

    function showUpdateCoursePopup(mentorId) {
        const mentor = mentorData.find(m => m.user_id == mentorId);
        if (!mentor) return;
        
        closeCourseAssignmentPopup();
        closeUpdateCoursePopup();
        
        document.getElementById('updatePopupBody').innerHTML = `<div class="loading"><i class="fas fa-sync fa-spin"></i> Loading course details...</div>`;
        updateCoursePopup.style.display = 'block';

        fetch('?action=get_assigned_course&mentor_id=' + mentorId)
            .then(response => response.json())
            .then(course => {
                let popupContent = '';
                
                if (course) {
                    popupContent = `
                        <p>Currently assigned course for <strong>${mentor.first_name} ${mentor.last_name}</strong>:</p>
                        <div class="form-group">
                            <label for="currentCourse">Course Title:</label>
                            <input type="text" id="currentCourse" readonly value="${course.Course_Title}" title="Course ID: ${course.Course_ID}"/>
                        </div>
                        <div class="popup-buttons">
                            <button type="button" class="btn-cancel" onclick="closeUpdateCoursePopup()"><i class="fas fa-times"></i> Close</button>
                            <button type="button" class="btn-confirm change-btn" onclick="showCourseChangePopup(${mentorId}, ${course.Course_ID})"><i class="fas fa-exchange-alt"></i> Change Course</button>
                            <button type="button" class="btn-confirm remove-btn" onclick="confirmRemoveCourse(${mentorId}, ${course.Course_ID}, '${course.Course_Title}')"><i class="fas fa-trash-alt"></i> Remove</button>
                        </div>
                    `;
                } else {
                    popupContent = `
                        <p><strong>${mentor.first_name} ${mentor.last_name}</strong> is currently <strong>Approved</strong> but is <strong>not assigned</strong> to any course.</p>
                        <div class="popup-buttons">
                            <button type="button" class="btn-cancel" onclick="closeUpdateCoursePopup()"><i class="fas fa-times"></i> Close</button>
                            <button type="button" class="btn-confirm" onclick="showCourseChangePopup(${mentorId}, null)"><i class="fas fa-plus"></i> Assign Course</button>
                        </div>
                    `;
                }
                
                document.getElementById('updatePopupBody').innerHTML = popupContent;
            })
            .catch(error => {
                console.error('Error fetching assigned course:', error);
                document.getElementById('updatePopupBody').innerHTML = `
                    <p>Error loading assigned course. Please try again.</p>
                    <div class="popup-buttons">
                        <button type="button" class="btn-cancel" onclick="closeUpdateCoursePopup()"><i class="fas fa-times"></i> Close</button>
                    </div>
                `;
            });
    }

    function closeUpdateCoursePopup() {
        updateCoursePopup.style.display = 'none';
        courseChangePopup.style.display = 'none';
    }
    
    function showCourseChangePopup(mentorId, currentCourseId) {
        closeUpdateCoursePopup();
        const mentor = mentorData.find(m => m.user_id == mentorId);
        
        courseChangePopup.style.display = 'block';
        document.getElementById('changePopupBody').innerHTML = `<div class="loading"><i class="fas fa-sync fa-spin"></i> Loading available courses...</div>`;
        
        fetch('?action=get_available_courses')
            .then(response => response.json())
            .then(courses => {
                let popupContent = '';
                
                if (courses.length === 0) {
                    popupContent = `
                        <p>No available courses found to assign. All courses are currently assigned.</p>
                        <div class="popup-buttons">
                            <button type="button" class="btn-cancel" onclick="showUpdateCoursePopup(${mentorId})"><i class="fas fa-arrow-left"></i> Back</button>
                        </div>
                    `;
                } else {
                    const actionText = currentCourseId ? 'NEW' : '';
                    popupContent = `
                        <p>Select a ${actionText} course to assign to <strong>${mentor.first_name} ${mentor.last_name}</strong>:</p>
                        <form id="courseChangeForm">
                            <div class="form-group">
                                <label for="courseChangeSelect">Available Courses:</label>
                                <select id="courseChangeSelect" name="course_id" required>
                                    <option value="">-- Select a Course --</option>
                    `;
                    
                    courses.forEach(course => {
                        popupContent += `<option value="${course.Course_ID}">${course.Course_Title}</option>`;
                    });
                    
                    popupContent += `
                                </select>
                            </div>
                            <div class="popup-buttons">
                                <button type="button" class="btn-cancel" onclick="showUpdateCoursePopup(${mentorId})"><i class="fas fa-times"></i> Cancel</button>
                                <button type="button" class="btn-confirm" onclick="confirmCourseChange(${mentorId}, ${currentCourseId})"><i class="fas fa-check"></i> Confirm Assignment</button>
                            </div>
                        </form>
                    `;
                }
                
                document.getElementById('changePopupBody').innerHTML = popupContent;
            })
            .catch(error => {
                console.error('Error fetching courses:', error);
                document.getElementById('changePopupBody').innerHTML = `
                    <p>Error loading courses. Please try again.</p>
                    <div class="popup-buttons">
                        <button type="button" class="btn-cancel" onclick="showUpdateCoursePopup(${mentorId})"><i class="fas fa-arrow-left"></i> Back</button>
                    </div>
                `;
            });
    }
    
    function confirmCourseChange(mentorId, oldCourseId) {
        const courseSelect = document.getElementById('courseChangeSelect');
        const newCourseId = courseSelect.value;
        
        if (!newCourseId) {
            alert('Please select a course.');
            return;
        }

        const confirmButton = document.querySelector('#courseChangePopup .btn-confirm');
        confirmButton.disabled = true;
        confirmButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';

        const formData = new FormData();
        formData.append('action', 'change_course');
        formData.append('mentor_id', mentorId);
        formData.append('old_course_id', oldCourseId);
        formData.append('new_course_id', newCourseId);
        
        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Course assignment successfully updated! Refreshing page...');
                location.reload();
            } else {
                alert('Error: ' + data.message);
                confirmButton.disabled = false;
                confirmButton.innerHTML = '<i class="fas fa-check"></i> Confirm Assignment';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred during course change. Please try again.');
            confirmButton.disabled = false;
            confirmButton.innerHTML = '<i class="fas fa-check"></i> Confirm Assignment';
        });
    }

    function confirmRemoveCourse(mentorId, courseId, courseTitle) {
        if (confirm(`Are you sure you want to REMOVE ${mentorData.find(m => m.user_id == mentorId).first_name}'s assignment from the course: "${courseTitle}"? \n\nThe course will become available for assignment.`)) {
            
            const removeButton = document.querySelector('#updateCoursePopup .btn-confirm.remove-btn');
            removeButton.disabled = true;
            removeButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Removing...';
            
            const formData = new FormData();
            formData.append('action', 'remove_assigned_course');
            formData.append('course_id', courseId);
            formData.append('mentor_id', mentorId);
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message + ' Refreshing page...');
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                    removeButton.disabled = false;
                    removeButton.innerHTML = '<i class="fas fa-trash-alt"></i> Remove';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred during removal. Please try again.');
                removeButton.disabled = false;
                removeButton.innerHTML = '<i class="fas fa-trash-alt"></i> Remove';
            });
        }
    }

    function showRejectionDialog(mentorId) {
        let reason = prompt("Enter reason for rejection:");
        if (reason !== null && reason.trim() !== "") {
            confirmRejection(mentorId, reason.trim());
        } else if (reason !== null) {
            alert("Rejection reason cannot be empty.");
        }
    }

    function confirmRejection(mentorId, reason) {
        const formData = new FormData();
        formData.append('action', 'reject_mentor');
        formData.append('mentor_id', mentorId);
        formData.append('reason', reason);

        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert(data.message + ' Refreshing page...');
                location.reload();
            } else {
                alert('Rejection failed: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred during rejection. Please try again.');
        });
    }

    btnMentors.onclick = () => {
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
        if (event.target === updateCoursePopup || event.target === courseChangePopup) {
            closeUpdateCoursePopup();
        }
    }

</script>
<script type="module" src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.esm.js"></script>
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
</body>
</html>