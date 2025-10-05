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
$sql = "SELECT user_id, first_name, last_name, dob, gender, email, contact_number, user_icon, status, reason FROM users WHERE user_type = 'Mentor'";
$result = $conn->query($sql);

$approved_mentors = [];
$applicant_mentors = [];
$rejected_mentors = [];

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $mentor_full_name = $row['first_name'] . ' ' . $row['last_name'];
        
        // Fetch assigned course for approved mentors
        if ($row['status'] === 'Approved') {
            $course_sql = "SELECT Course_ID, Course_Title FROM courses WHERE Assigned_Mentor = ?";
            $course_stmt = $conn->prepare($course_sql);
            $course_stmt->bind_param("s", $mentor_full_name);
            $course_stmt->execute();
            $course_result = $course_stmt->get_result();
            $course_data = $course_result->fetch_assoc();
            $course_stmt->close();

            $row['assigned_course'] = $course_data;
            $approved_mentors[] = $row;
        } elseif ($row['status'] === 'Under Review') {
            $applicant_mentors[] = $row;
        } elseif ($row['status'] === 'Rejected') {
            $rejected_mentors[] = $row;
        }
    }
}

$mentors_json = json_encode([
    'approved' => $approved_mentors,
    'applicants' => $applicant_mentors,
    'rejected' => $rejected_mentors
]);
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Mentors - COACH</title>
    <link rel="icon" type="image/x-icon" href="../uploads/img/logo.png">
    <link rel="stylesheet" href="../css/superadmin-dashboard.css">
    <link rel="stylesheet" href="../css/manage-mentors.css">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <style>
        /* START: Custom Pop-up Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.4);
            backdrop-filter: blur(2px);
        }

        .modal-content {
            background-color: #fefefe;
            margin: 10% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 80%;
            max-width: 400px;
            border-radius: 8px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
            text-align: center;
            animation: fadeIn 0.3s;
        }
        
        .modal-content h3 {
            color: #562b63;
            margin-top: 0;
            margin-bottom: 15px;
        }

        .modal-content p {
            margin-bottom: 20px;
            color: #333;
        }

        .dialog-buttons, .modal-buttons {
            display: flex;
            justify-content: center;
            gap: 10px;
        }

        .modal-buttons button, .dialog-buttons button {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
            transition: background-color 0.2s;
        }

        .modal-buttons #alertOk, .dialog-buttons #confirmLogoutBtn {
            background-color: #562b63;
            color: white;
        }

        .modal-buttons #alertOk:hover, .dialog-buttons #confirmLogoutBtn:hover {
            background-color: #431f4c;
        }

        .modal-buttons #confirmCancel, .dialog-buttons #cancelLogout {
            background-color: #ccc;
            color: #333;
        }

        .modal-buttons #confirmCancel:hover, .dialog-buttons #cancelLogout:hover {
            background-color: #bbb;
        }
        
        #rejectionModal .modal-content {
            max-width: 500px;
            text-align: left;
        }

        #rejectionModal textarea {
            width: 100%;
            padding: 10px;
            margin: 10px 0;
            box-sizing: border-box;
            border: 1px solid #ccc;
            border-radius: 4px;
            resize: vertical;
        }

        #rejectionModal .modal-buttons {
            justify-content: flex-end;
        }
        
        /* Styles for Update Course Pop-up buttons */
        #updateCoursePopup .modal-content {
            max-width: 450px;
        }
        #updateCoursePopup .btn-remove {
            background-color: #c0392b;
            color: white;
        }
        #updateCoursePopup .btn-remove:hover {
            background-color: #a63226;
        }
        #updateCoursePopup .btn-change {
            background-color: #2980b9;
            color: white;
        }
        #updateCoursePopup .btn-change:hover {
            background-color: #2471a3;
        }
        #updateCoursePopup .btn-cancel {
            background-color: #ccc;
            color: #333;
        }
        
        #courseAssignmentPopup select,
        #courseChangePopup select {
            width: 100%;
            padding: 10px;
            margin-bottom: 15px;
            border-radius: 5px;
            border: 1px solid #ccc;
        }


        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        /* END: Custom Pop-up Modal Styles */
    </style>
</head>
<body>
    <nav class="sidebar close">
        <header>
            <div class="image-text">
                <span class="image">
                    <img src="../uploads/img/logo.png" alt="logo">
                </span>
                <div class="text header-text">
                    <span class="name">COACH</span>
                    <span class="profession">Super Admin</span>
                </div>
            </div>
            <i class='bx bx-chevron-right toggle'></i>
        </header>

        <div class="menu-bar">
            <div class="menu">
                <li class="search-box">
                    <i class='bx bx-search-alt icon'></i>
                    <input type="search" placeholder="Search...">
                </li>
                <ul class="menu-links">
                    <li class="navList">
                        <a href="superadmin-dashboard.php">
                            <i class='bx bx-home-alt icon'></i>
                            <span class="text nav-text">Dashboard</span>
                        </a>
                    </li>
                    <li class="navList active">
                        <a href="manage_mentors.php">
                            <i class='bx bxs-user-detail icon'></i>
                            <span class="text nav-text">Manage Mentors</span>
                        </a>
                    </li>
                    <li class="navList">
                        <a href="manage_mentees.php">
                            <i class='bx bx-group icon'></i>
                            <span class="text nav-text">Manage Mentees</span>
                        </a>
                    </li>
                    <li class="navList">
                        <a href="moderators.php">
                            <i class='bx bx-male-female icon'></i>
                            <span class="text nav-text">Moderators</span>
                        </a>
                    </li>
                    <li class="navList">
                        <a href="courses.php">
                            <i class='bx bx-book-open icon'></i>
                            <span class="text nav-text">Manage Courses</span>
                        </a>
                    </li>
                    <li class="navList">
                        <a href="resource.php">
                            <i class='bx bx-folder-plus icon'></i>
                            <span class="text nav-text">Resource Library</span>
                        </a>
                    </li>
                </ul>
            </div>
            <div class="bottom-content">
                <li class="mode">
                    <div class="moon-sun">
                        <i class='bx bx-moon icon moon'></i>
                        <i class='bx bx-sun icon sun'></i>
                    </div>
                    <span class="mode-text text">Dark Mode</span>
                    <div class="toggle-switch">
                        <span class="switch"></span>
                    </div>
                </li>
                <li class="navList" id="logoutBtn">
                    <a href="#">
                        <i class='bx bx-log-out icon'></i>
                        <span class="text nav-text">Logout</span>
                    </a>
                </li>
            </div>
        </div>
    </nav>
    <section class="home">
        <div class="header-container">
            <div class="user-info">
                <img src="<?php echo htmlspecialchars($admin_icon); ?>" alt="profile" class="profile-icon">
                <div class="text-info">
                    <span class="name-admin"><?php echo htmlspecialchars($admin_name); ?></span>
                    <span class="user-role">Super Admin</span>
                </div>
            </div>
        </div>
        <div class="main-content">
            <div class="tab-buttons">
                <button id="btnApproved" class="tab-button active">Approved Mentors</button>
                <button id="btnApplicants" class="tab-button">Applicants (Under Review)</button>
                <button id="btnRejected" class="tab-button">Rejected Mentors</button>
            </div>
            
            <div id="tableContainer" class="table-container">
                </div>
            
        </div>
        
    </section>

    <div id="customAlert" class="modal">
        <div class="modal-content">
            <h3 id="alertTitle">Alert</h3>
            <p id="alertMessage"></p>
            <div class="modal-buttons">
                <button id="alertOk">OK</button>
            </div>
        </div>
    </div>
    
    <div id="customConfirm" class="modal">
        <div class="modal-content">
            <h3 id="confirmTitle">Confirm</h3>
            <p id="confirmMessage"></p>
            <div class="modal-buttons">
                <button id="confirmCancel">Cancel</button>
                <button id="confirmOk">Confirm</button>
            </div>
        </div>
    </div>
    
    <div id="logoutDialog" class="logout-dialog modal" style="display: none;">
        <div class="logout-content modal-content">
            <h3>Confirm Logout</h3>
            <p>Are you sure you want to log out?</p>
            <div class="dialog-buttons">
                <button id="cancelLogout" type="button">Cancel</button>
                <button id="confirmLogoutBtn" type="button">Logout</button>
            </div>
        </div>
    </div>

    <div id="rejectionModal" class="modal">
        <div class="modal-content">
            <h3>Reject Mentor Application</h3>
            <p>Please provide a reason for rejecting this mentor:</p>
            <textarea id="rejectionReason" placeholder="Enter reason here..." rows="4"></textarea>
            <div class="modal-buttons">
                <button id="rejectCancel">Cancel</button>
                <button id="rejectConfirm">Reject</button>
            </div>
        </div>
    </div>

    <div id="courseAssignmentPopup" class="modal">
        <div class="modal-content">
            <h3>Assign Course & Approve</h3>
            <p>Select a course to assign to <strong id="mentorNameAssign"></strong>:</p>
            <select id="availableCoursesSelect">
                <option value="">Loading courses...</option>
            </select>
            <p id="assignmentStatus" style="color: red;"></p>
            <div class="modal-buttons">
                <button id="assignCancel">Cancel</button>
                <button id="assignConfirm">Approve & Assign</button>
            </div>
        </div>
    </div>

    <div id="updateCoursePopup" class="modal">
        <div class="modal-content">
            <h3>Update Course Assignment</h3>
            <p>Current course for <strong id="mentorNameUpdate"></strong>:</p>
            <div id="currentCourseInfo" style="margin-bottom: 15px;">
                <p>Course: <strong id="currentCourseTitle"></strong></p>
            </div>
            <div class="modal-buttons" style="flex-direction: column; gap: 10px;">
                <button id="removeCourseBtn" class="btn-remove">Remove Assignment</button>
                <button id="changeCourseBtn" class="btn-change">Change Course</button>
                <button id="updateCancel" class="btn-cancel">Cancel</button>
            </div>
        </div>
    </div>
    
    <div id="courseChangePopup" class="modal">
        <div class="modal-content">
            <h3>Reassign Course</h3>
            <p>Select a **new** course for <strong id="mentorNameReassign"></strong>:</p>
            <select id="reassignCoursesSelect">
                <option value="">Loading courses...</option>
            </select>
            <p id="reassignStatus" style="color: red;"></p>
            <div class="modal-buttons">
                <button id="reassignBack">Back</button>
                <button id="reassignConfirm">Reassign</button>
            </div>
        </div>
    </div>
    
    <script>
        const mentorData = <?php echo $mentors_json; ?>;
        const approved = mentorData.approved;
        const applicants = mentorData.applicants;
        const rejected = mentorData.rejected;

        const tableContainer = document.getElementById('tableContainer');
        const btnApproved = document.getElementById('btnApproved');
        const btnApplicants = document.getElementById('btnApplicants');
        const btnRejected = document.getElementById('btnRejected');
        const tabButtons = document.querySelectorAll('.tab-button');
        
        // Modal elements for Assignment
        const courseAssignmentPopup = document.getElementById('courseAssignmentPopup');
        const mentorNameAssign = document.getElementById('mentorNameAssign');
        const availableCoursesSelect = document.getElementById('availableCoursesSelect');
        const assignConfirm = document.getElementById('assignConfirm');
        const assignCancel = document.getElementById('assignCancel');
        
        // Modal elements for Update/Remove
        const updateCoursePopup = document.getElementById('updateCoursePopup');
        const mentorNameUpdate = document.getElementById('mentorNameUpdate');
        const currentCourseTitle = document.getElementById('currentCourseTitle');
        const removeCourseBtn = document.getElementById('removeCourseBtn');
        const changeCourseBtn = document.getElementById('changeCourseBtn');
        const updateCancel = document.getElementById('updateCancel');

        // Modal elements for Reassignment
        const courseChangePopup = document.getElementById('courseChangePopup');
        const mentorNameReassign = document.getElementById('mentorNameReassign');
        const reassignCoursesSelect = document.getElementById('reassignCoursesSelect');
        const reassignConfirm = document.getElementById('reassignConfirm');
        const reassignBack = document.getElementById('reassignBack');
        
        // Rejection Modal elements
        const rejectionModal = document.getElementById('rejectionModal');
        const rejectionReason = document.getElementById('rejectionReason');
        const rejectConfirm = document.getElementById('rejectConfirm');
        const rejectCancel = document.getElementById('rejectCancel');
        let currentRejectionMentorId = null;

        let currentMentorId = null; // Used for assignment and update/remove
        let currentCourseId = null; // Used for update/remove

        // --- Custom Alert/Confirm Logic ---

        const customAlertModal = document.getElementById('customAlert');
        const customAlertMessage = document.getElementById('alertMessage');
        const customAlertOk = document.getElementById('alertOk');
        const customAlertTitle = document.getElementById('alertTitle');

        /**
         * Shows a custom alert modal.
         * @param {string} message - The message to display.
         * @param {string} [title='Notification'] - The title of the alert.
         * @returns {Promise<boolean>} Resolves to true when the user clicks OK.
         */
        function customAlert(message, title = 'Notification') {
            return new Promise(resolve => {
                customAlertTitle.textContent = title;
                customAlertMessage.textContent = message;
                customAlertModal.style.display = 'block';

                customAlertOk.onclick = () => {
                    customAlertModal.style.display = 'none';
                    resolve(true);
                };
            });
        }

        const customConfirmModal = document.getElementById('customConfirm');
        const customConfirmMessage = document.getElementById('confirmMessage');
        const customConfirmOk = document.getElementById('confirmOk');
        const customConfirmCancel = document.getElementById('confirmCancel');
        const customConfirmTitle = document.getElementById('confirmTitle');

        /**
         * Shows a custom confirmation modal.
         * @param {string} message - The message to display.
         * @param {string} [title='Confirmation'] - The title of the confirmation.
         * @returns {Promise<boolean>} Resolves to true if the user clicks OK, false otherwise.
         */
        function customConfirm(message, title = 'Confirmation') {
            return new Promise(resolve => {
                customConfirmTitle.textContent = title;
                customConfirmMessage.textContent = message;
                customConfirmModal.style.display = 'block';

                customConfirmOk.onclick = () => {
                    customConfirmModal.style.display = 'none';
                    resolve(true);
                };

                customConfirmCancel.onclick = () => {
                    customConfirmModal.style.display = 'none';
                    resolve(false);
                };
            });
        }
        
        // --- Core Functions ---

        function buildTable(data, isApplicant) {
            let tableHTML = `<table class="mentors-table" id="mentorsTable">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Icon</th>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Contact No.</th>`;
            
            if (!isApplicant) {
                tableHTML += `<th>Assigned Course</th>`;
            } else {
                 tableHTML += `<th>Date of Birth</th>
                               <th>Gender</th>`;
            }
                                
            tableHTML +=         `<th>Status</th>
                                 <th>Action</th>
                                </tr>
                                </thead>
                                <tbody>`;

            if (data.length === 0) {
                let colSpan = isApplicant ? 9 : 8;
                tableHTML += `<tr class="no-data"><td colspan="${colSpan}">No mentors found in this category.</td></tr>`;
            } else {
                data.forEach(mentor => {
                    const iconSrc = mentor.user_icon || '../uploads/img/default_pfp.png';
                    const fullName = `${mentor.first_name} ${mentor.last_name}`;
                    const statusClass = mentor.status.toLowerCase().replace(' ', '-');
                    
                    tableHTML += `<tr class="data-row">
                                    <td>${mentor.user_id}</td>
                                    <td><img src="${iconSrc}" alt="icon" class="table-icon"></td>
                                    <td>${fullName}</td>
                                    <td>${mentor.email}</td>
                                    <td>${mentor.contact_number}</td>`;

                    if (!isApplicant) {
                        const courseTitle = mentor.assigned_course ? mentor.assigned_course.Course_Title : 'N/A';
                        tableHTML += `<td>${courseTitle}</td>`;
                    } else {
                        tableHTML += `<td>${mentor.dob}</td>
                                      <td>${mentor.gender}</td>`;
                    }
                    
                    tableHTML +=    `<td><span class="status ${statusClass}">${mentor.status}</span>`;
                    
                    if (mentor.status === 'Rejected' && mentor.reason) {
                        const reasonText = mentor.reason.length > 50 
                            ? mentor.reason.substring(0, 50) + '...' 
                            : mentor.reason;
                        tableHTML += `<br><small style="color: #c0392b; font-size: 10px;" title="${mentor.reason}">Reason: ${reasonText}</small>`;
                    }
                    
                    tableHTML +=    `</td>
                                    <td>`;

                    if (mentor.status === 'Under Review') {
                        tableHTML += `<button class="action-btn approve-btn" 
                                            data-id="${mentor.user_id}" 
                                            data-name="${fullName}">Approve</button>
                                       <button class="action-btn reject-btn" 
                                            data-id="${mentor.user_id}" 
                                            data-name="${fullName}">Reject</button>`;
                    } else if (mentor.status === 'Approved') {
                        const assignedCourseId = mentor.assigned_course ? mentor.assigned_course.Course_ID : '';
                        const assignedCourseTitle = mentor.assigned_course ? mentor.assigned_course.Course_Title : 'N/A';
                        tableHTML += `<button class="action-btn update-course-btn" 
                                            data-id="${mentor.user_id}" 
                                            data-name="${fullName}"
                                            data-course-id="${assignedCourseId}"
                                            data-course-title="${assignedCourseTitle}">Update Course</button>`;
                    } else if (mentor.status === 'Rejected') {
                        tableHTML += `<span class="action-info">Action Complete</span>`;
                    }
                    
                    tableHTML +=    `</td>
                                </tr>`;
                });
            }

            tableHTML += `</tbody></table>`;
            tableContainer.innerHTML = tableHTML;
            
            // Reattach event listeners to new buttons
            attachEventListeners();
        }

        function attachEventListeners() {
            // Applicants Table Buttons (Approve/Reject)
            document.querySelectorAll('.approve-btn').forEach(button => {
                button.addEventListener('click', (e) => {
                    currentMentorId = e.target.getAttribute('data-id');
                    const mentorName = e.target.getAttribute('data-name');
                    openCourseAssignmentPopup(mentorName);
                });
            });

            document.querySelectorAll('.reject-btn').forEach(button => {
                button.addEventListener('click', (e) => {
                    currentRejectionMentorId = e.target.getAttribute('data-id');
                    const mentorName = e.target.getAttribute('data-name');
                    showRejectionModal(mentorName);
                });
            });

            // Approved Table Button (Update Course)
            document.querySelectorAll('.update-course-btn').forEach(button => {
                button.addEventListener('click', (e) => {
                    currentMentorId = e.target.getAttribute('data-id');
                    currentCourseId = e.target.getAttribute('data-course-id');
                    const mentorName = e.target.getAttribute('data-name');
                    const courseTitle = e.target.getAttribute('data-course-title');
                    openUpdateCoursePopup(mentorName, currentCourseId, courseTitle);
                });
            });
        }
        
        // --- Modal Handlers ---

        function openCourseAssignmentPopup(mentorName) {
            mentorNameAssign.textContent = mentorName;
            availableCoursesSelect.innerHTML = '<option value="">Loading courses...</option>';
            document.getElementById('assignmentStatus').textContent = '';
            
            fetchAvailableCourses(availableCoursesSelect);
            courseAssignmentPopup.style.display = 'block';
        }

        function closeCourseAssignmentPopup() {
            courseAssignmentPopup.style.display = 'none';
        }

        function openUpdateCoursePopup(mentorName, courseId, courseTitle) {
            mentorNameUpdate.textContent = mentorName;
            currentCourseTitle.textContent = courseTitle || 'No Course Assigned';
            
            // Enable/Disable remove button based on current assignment
            if (courseId && courseId !== 'N/A') {
                removeCourseBtn.disabled = false;
                removeCourseBtn.style.opacity = 1;
            } else {
                removeCourseBtn.disabled = true;
                removeCourseBtn.style.opacity = 0.5;
            }

            updateCoursePopup.style.display = 'block';
        }

        function closeUpdateCoursePopup() {
            updateCoursePopup.style.display = 'none';
            courseChangePopup.style.display = 'none';
        }
        
        function openCourseChangePopup(mentorName) {
            // Hide the update modal, show the reassign modal
            updateCoursePopup.style.display = 'none';
            mentorNameReassign.textContent = mentorName;
            reassignCoursesSelect.innerHTML = '<option value="">Loading courses...</option>';
            document.getElementById('reassignStatus').textContent = '';
            
            fetchAvailableCourses(reassignCoursesSelect);
            courseChangePopup.style.display = 'block';
        }
        
        function showRejectionModal(mentorName) {
            document.querySelector('#rejectionModal h3').textContent = `Reject Mentor: ${mentorName}`;
            rejectionReason.value = '';
            rejectionModal.style.display = 'block';
        }
        
        function closeRejectionModal() {
            rejectionModal.style.display = 'none';
            currentRejectionMentorId = null;
        }

        // --- AJAX Fetchers ---

        async function fetchAvailableCourses(selectElement) {
            try {
                const response = await fetch('manage_mentors.php?action=get_available_courses');
                const courses = await response.json();
                
                selectElement.innerHTML = '';
                if (courses.length === 0) {
                    selectElement.innerHTML = '<option value="">No courses available</option>';
                    assignConfirm.disabled = true; // Disable confirm button if no courses
                    reassignConfirm.disabled = true;
                } else {
                    selectElement.innerHTML = '<option value="">-- Select a Course --</option>';
                    courses.forEach(course => {
                        const option = document.createElement('option');
                        option.value = course.Course_ID;
                        option.textContent = course.Course_Title;
                        selectElement.appendChild(option);
                    });
                    assignConfirm.disabled = false;
                    reassignConfirm.disabled = false;
                }
            } catch (error) {
                console.error('Error fetching available courses:', error);
                selectElement.innerHTML = '<option value="">Error loading courses</option>';
                assignConfirm.disabled = true;
                reassignConfirm.disabled = true;
                customAlert('Error loading available courses. Please try again.', 'Error');
            }
        }
        
        // --- Form/Action Submissions ---

        assignConfirm.onclick = async () => {
            const courseId = availableCoursesSelect.value;
            if (!courseId) {
                document.getElementById('assignmentStatus').textContent = 'Please select a course.';
                return;
            }

            const isConfirmed = await customConfirm('Are you sure you want to approve this mentor and assign the selected course?', 'Confirm Approval');
            
            if (isConfirmed) {
                // Perform AJAX approval
                try {
                    const response = await fetch('manage_mentors.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `action=approve_with_course&mentor_id=${currentMentorId}&course_id=${courseId}`
                    });
                    const result = await response.json();
                    
                    closeCourseAssignmentPopup();
                    
                    if (result.success) {
                        await customAlert(result.message, 'Success');
                        // Reload the page to refresh data
                        window.location.reload();
                    } else {
                        customAlert(result.message, 'Error');
                    }
                } catch (error) {
                    closeCourseAssignmentPopup();
                    console.error('Error during approval:', error);
                    customAlert('An unexpected error occurred during the approval process.', 'Error');
                }
            }
        };

        // Handle Rejection
        rejectConfirm.onclick = async () => {
            const reason = rejectionReason.value.trim();
            if (!reason) {
                customAlert('Please provide a reason for rejection.', 'Input Required');
                return;
            }
            
            const isConfirmed = await customConfirm('Are you sure you want to reject this mentor?', 'Confirm Rejection');

            if (isConfirmed) {
                try {
                    const response = await fetch('manage_mentors.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `action=reject_mentor&mentor_id=${currentRejectionMentorId}&reason=${encodeURIComponent(reason)}`
                    });
                    const result = await response.json();

                    closeRejectionModal();

                    if (result.success) {
                        await customAlert(result.message, 'Success');
                        window.location.reload();
                    } else {
                        customAlert(result.message, 'Error');
                    }

                } catch (error) {
                    closeRejectionModal();
                    console.error('Error during rejection:', error);
                    customAlert('An unexpected error occurred during the rejection process.', 'Error');
                }
            }
        };

        // Handle Remove Assignment
        removeCourseBtn.onclick = async () => {
            const isConfirmed = await customConfirm(`Are you sure you want to remove the course assignment from ${mentorNameUpdate.textContent}?`, 'Confirm Removal');
            
            if (isConfirmed) {
                try {
                    const response = await fetch('manage_mentors.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `action=remove_assigned_course&mentor_id=${currentMentorId}&course_id=${currentCourseId}`
                    });
                    const result = await response.json();
                    
                    closeUpdateCoursePopup();
                    
                    if (result.success) {
                        await customAlert(result.message, 'Success');
                        window.location.reload();
                    } else {
                        customAlert(result.message, 'Error');
                    }
                } catch (error) {
                    closeUpdateCoursePopup();
                    console.error('Error during course removal:', error);
                    customAlert('An unexpected error occurred during course removal.', 'Error');
                }
            }
        };
        
        // Handle Change Course (Show Reassignment Modal)
        changeCourseBtn.onclick = () => {
            openCourseChangePopup(mentorNameUpdate.textContent);
        };
        
        // Handle Reassign Course Confirmation
        reassignConfirm.onclick = async () => {
            const newCourseId = reassignCoursesSelect.value;
            if (!newCourseId) {
                document.getElementById('reassignStatus').textContent = 'Please select a new course.';
                return;
            }
            
            const selectedCourseTitle = reassignCoursesSelect.options[reassignCoursesSelect.selectedIndex].text;

            const isConfirmed = await customConfirm(`Are you sure you want to reassign this mentor to **${selectedCourseTitle}**?`, 'Confirm Reassignment');
            
            if (isConfirmed) {
                try {
                    const response = await fetch('manage_mentors.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `action=change_course&mentor_id=${currentMentorId}&old_course_id=${currentCourseId}&new_course_id=${newCourseId}`
                    });
                    const result = await response.json();
                    
                    closeUpdateCoursePopup();
                    
                    if (result.success) {
                        await customAlert(result.message, 'Success');
                        window.location.reload();
                    } else {
                        customAlert(result.message, 'Error');
                    }
                } catch (error) {
                    closeUpdateCoursePopup();
                    console.error('Error during course reassignment:', error);
                    customAlert('An unexpected error occurred during course reassignment.', 'Error');
                }
            }
        };

        // --- Event Listeners and Initial Load ---
        
        reassignBack.onclick = () => {
            courseChangePopup.style.display = 'none';
            updateCoursePopup.style.display = 'block';
        };
        
        assignCancel.onclick = closeCourseAssignmentPopup;
        updateCancel.onclick = closeUpdateCoursePopup;
        rejectCancel.onclick = closeRejectionModal;
        
        btnApproved.onclick = () => {
            showTable(approved, false);
        };

        btnApplicants.onclick = () => {
            showTable(applicants, true);
        };

        btnRejected.onclick = () => {
            showTable(rejected, false);
        };

        function showTable(data, isApplicant) {
            tabButtons.forEach(btn => btn.classList.remove('active'));
            if (isApplicant) {
                btnApplicants.classList.add('active');
            } else if (data === approved) {
                btnApproved.classList.add('active');
            } else {
                btnRejected.classList.add('active');
            }
            buildTable(data, isApplicant);
        }
        
        document.addEventListener('DOMContentLoaded', () => {
            if (applicants.length > 0) {
                showTable(applicants, true);
            } else {
                showTable(approved, false);
            }
            
            // Logout Modal Logic (retained from original file)
            const logoutDialog = document.getElementById('logoutDialog');
            const logoutBtn = document.getElementById('logoutBtn');
            const confirmLogoutBtn = document.getElementById('confirmLogoutBtn');
            const cancelLogout = document.getElementById('cancelLogout');

            logoutBtn.addEventListener('click', (e) => {
                e.preventDefault();
                logoutDialog.style.display = 'block';
            });

            cancelLogout.addEventListener('click', () => {
                logoutDialog.style.display = 'none';
            });

            confirmLogoutBtn.addEventListener('click', () => {
                window.location.href = '../logout.php';
            });
            
            const navBar = document.querySelector("nav");
            const navToggle = document.querySelector(".toggle");
            if (navToggle) {
                navToggle.addEventListener('click', () => {
                    navBar.classList.toggle('close');
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
                    customAlertModal.style.display = 'none';
                }
                if (event.target === customConfirmModal) {
                    customConfirmModal.style.display = 'none';
                }
                // Handle close for the reassign course popup if clicked outside
                if (event.target === courseChangePopup) {
                     courseChangePopup.style.display = 'none';
                }
            }
            
        });

    </script>
    <script type="module" src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.esm.js"></script>
    <script nomodule src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.js"></script>

</body>
</html>