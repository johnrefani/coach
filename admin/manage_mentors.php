<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'Admin') {
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

$admin_icon = !empty($_SESSION['user_icon']) ? $_SESSION['user_icon'] : '../uploads/img/default_pfp.png';
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
    $sql = "SELECT Course_ID, Course_Title FROM courses WHERE (Assigned_Mentor IS NULL OR TRIM(Assigned_Mentor) = '') ORDER BY Course_Title ASC";
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
        if ($old_course_id && $old_course_id !== 'null') {
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

// Handle AJAX request for rejecting course change request from Mentor Management
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reject_course_change_request') {
    header('Content-Type: application/json');
    $request_id = $_POST['request_id'];
    $rejection_reason = $_POST['rejection_reason'] ?? 'No specific reason provided';
    
    try {
        $conn->begin_transaction();
        
        // Get request details first
        $get_request = "SELECT username, current_course_id, wanted_course_id
                       FROM mentor_requests 
                       WHERE request_id = ? AND request_type = 'Course Change'";
        $stmt = $conn->prepare($get_request);
        $stmt->bind_param("i", $request_id);
        $stmt->execute();
        $stmt->bind_result($username, $current_course_id, $wanted_course_id);
        $stmt->fetch();
        $stmt->close();
        
        // Get mentor details from users table using username
        $get_mentor = "SELECT email, CONCAT(first_name, ' ', last_name) AS full_name 
                      FROM users 
                      WHERE username = ? AND user_type = 'Mentor'";
        $stmt = $conn->prepare($get_mentor);
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->bind_result($mentor_email, $mentor_full_name);
        $stmt->fetch();
        $stmt->close();
        
        // Get current course title
        $current_course_title = 'N/A';
        if ($current_course_id) {
            $get_current = "SELECT Course_Title FROM courses WHERE Course_ID = ?";
            $stmt = $conn->prepare($get_current);
            $stmt->bind_param("i", $current_course_id);
            $stmt->execute();
            $stmt->bind_result($current_course_title);
            $stmt->fetch();
            $stmt->close();
        }
        
        // Get wanted course title
        $get_wanted = "SELECT Course_Title FROM courses WHERE Course_ID = ?";
        $stmt = $conn->prepare($get_wanted);
        $stmt->bind_param("i", $wanted_course_id);
        $stmt->execute();
        $stmt->bind_result($wanted_course_title);
        $stmt->fetch();
        $stmt->close();
        
        // Update request status to Rejected with reason
        $update_request = "UPDATE mentor_requests SET status = 'Rejected', admin_response = ? WHERE request_id = ?";
        $stmt = $conn->prepare($update_request);
        $stmt->bind_param("si", $rejection_reason, $request_id);
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
                $email->setSubject("Course Change Request Rejected - COACH Program");
                $email->addTo($mentor_email, $mentor_full_name);
                
                $safe_reason = htmlspecialchars($rejection_reason);
                
                $html_body = "
                <html>
                <head>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 5px; background-color:rgb(241, 223, 252); }
                    .header { background-color: #562b63; padding: 15px; color: white; text-align: center; border-radius: 5px 5px 0 0; }
                    .content { padding: 20px; background-color: #f9f9f9; }
                    .course-box { background-color: #fff; border: 1px solid #ddd; padding: 15px; margin: 15px 0; border-radius: 5px; }
                    .rejection-badge { background-color: #dc3545; color: white; padding: 5px 10px; border-radius: 3px; display: inline-block; margin-bottom: 15px; }
                    .reason-box { background-color: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 15px 0; }
                    .footer { text-align: center; padding: 10px; font-size: 12px; color: #777; }
                </style>
                </head>
                <body>
                <div class='container'>
                    <div class='header'>
                    <h2>Course Change Request Update</h2>
                    </div>
                    <div class='content'>
                    <div class='rejection-badge'>✗ REQUEST REJECTED</div>
                    <p>Dear $mentor_full_name,</p>
                    <p>We have reviewed your course change request. Unfortunately, your request has been <strong>rejected</strong> by the administrator.</p>
                    
                    <div class='course-box'>
                        <p><strong>Current Course:</strong> $current_course_title</p>
                        <p><strong>Requested Course:</strong> $wanted_course_title</p>
                    </div>
                    
                    <div class='reason-box'>
                        <p><strong>Reason for Rejection:</strong></p>
                        <p>$safe_reason</p>
                    </div>
                    
                    <p>You will continue mentoring your current course. If you have any questions or would like to discuss this further, please contact the administrator.</p>
                    <p>Thank you for your understanding.</p>
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
                } else {
                    $email_sent_status = 'Email failed (Status: ' . $response->statusCode() . ')';
                    error_log("SendGrid Rejection Error: Status=" . $response->statusCode());
                }
                
            } catch (\Exception $email_e) {
                error_log("Course Change Rejection Email Exception: " . $email_e->getMessage());
                $email_sent_status = 'Email error: ' . $email_e->getMessage();
            }
        } else {
            if (!$sendgrid_api_key) {
                $email_sent_status = 'Email not sent: Missing SendGrid API key';
            } elseif (!$mentor_email) {
                $email_sent_status = 'Email not sent: Mentor email not found (username: ' . $username . ')';
            }
        }
        
        echo json_encode([
            'success' => true, 
            'message' => "Course change request rejected. $mentor_full_name has been notified. $email_sent_status"
        ]);
        
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Error rejecting course change: ' . $e->getMessage()]);
    }
    exit();
}

    // Handle AJAX request for approving resignation request from Mentor Management
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'approve_resignation_request') {
        header('Content-Type: application/json');
        $request_id = $_POST['request_id'];
        
        try {
            $conn->begin_transaction();
            
            // Get request details first
            $get_request = "SELECT username, current_course_id, reason
                        FROM mentor_requests 
                        WHERE request_id = ? AND request_type = 'Resignation'";
            $stmt = $conn->prepare($get_request);
            $stmt->bind_param("i", $request_id);
            $stmt->execute();
            $stmt->bind_result($username, $current_course_id, $reason);
            $stmt->fetch();
            $stmt->close();
            
            // Get mentor details from users table using username
            $get_mentor = "SELECT email, CONCAT(first_name, ' ', last_name) AS full_name 
                        FROM users 
                        WHERE username = ? AND user_type = 'Mentor'";
            $stmt = $conn->prepare($get_mentor);
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $stmt->bind_result($mentor_email, $mentor_full_name);
            $stmt->fetch();
            $stmt->close();
            
            // Get current course title
            $current_course_title = 'N/A';
            if ($current_course_id) {
                $get_current = "SELECT Course_Title FROM courses WHERE Course_ID = ?";
                $stmt = $conn->prepare($get_current);
                $stmt->bind_param("i", $current_course_id);
                $stmt->execute();
                $stmt->bind_result($current_course_title);
                $stmt->fetch();
                $stmt->close();
                
                // Remove from current course - set Assigned_Mentor to NULL
                $update_current = "UPDATE courses SET Assigned_Mentor = NULL WHERE Course_ID = ?";
                $stmt = $conn->prepare($update_current);
                $stmt->bind_param("i", $current_course_id);
                $stmt->execute();
                $stmt->close();
            }
            
            // Update request status to Approved
            $update_request = "UPDATE mentor_requests SET status = 'Approved' WHERE request_id = ?";
            $stmt = $conn->prepare($update_request);
            $stmt->bind_param("i", $request_id);
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
                    $email->setSubject("Resignation Request Approved - COACH Program");
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
                        .success-badge { background-color: #28a745; color: white; padding: 5px 10px; border-radius: 3px; display: inline-block; margin-bottom: 15px; }
                        .footer { text-align: center; padding: 10px; font-size: 12px; color: #777; }
                    </style>
                    </head>
                    <body>
                    <div class='container'>
                        <div class='header'>
                        <h2>Resignation Request Approved</h2>
                        </div>
                        <div class='content'>
                        <div class='success-badge'>✓ REQUEST APPROVED</div>
                        <p>Dear $mentor_full_name,</p>
                        <p>Your resignation request has been <strong>approved</strong> by the administrator.</p>
                        
                        <div class='course-box'>
                            <p><strong>Course You Were Assigned To:</strong> $current_course_title</p>
                            <p><strong>Status:</strong> You have been removed from this course</p>
                        </div>
                        
                        <p>You are no longer assigned to mentor this course. We appreciate your contributions to the COACH program.</p>
                        <p>If you have any questions, please contact the administrator.</p>
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
                    } else {
                        $email_sent_status = 'Email failed (Status: ' . $response->statusCode() . ')';
                        error_log("SendGrid Resignation Approval Error: Status=" . $response->statusCode());
                    }
                    
                } catch (\Exception $email_e) {
                    error_log("Resignation Approval Email Exception: " . $email_e->getMessage());
                    $email_sent_status = 'Email error: ' . $email_e->getMessage();
                }
            } else {
                if (!$sendgrid_api_key) {
                    $email_sent_status = 'Email not sent: Missing SendGrid API key';
                } elseif (!$mentor_email) {
                    $email_sent_status = 'Email not sent: Mentor email not found';
                }
            }
            
            echo json_encode([
                'success' => true, 
                'message' => "Resignation request approved! $mentor_full_name has been removed from '$current_course_title'. $email_sent_status"
            ]);
            
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => 'Error approving resignation: ' . $e->getMessage()]);
        }
        exit();
    }

    // Handle AJAX request for rejecting resignation request from Mentor Management
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reject_resignation_request') {
        header('Content-Type: application/json');
        $request_id = $_POST['request_id'];
        $rejection_reason = $_POST['rejection_reason'] ?? 'No specific reason provided';
        
        try {
            $conn->begin_transaction();
            
            // Get request details first
            $get_request = "SELECT username, current_course_id
                        FROM mentor_requests 
                        WHERE request_id = ? AND request_type = 'Resignation'";
            $stmt = $conn->prepare($get_request);
            $stmt->bind_param("i", $request_id);
            $stmt->execute();
            $stmt->bind_result($username, $current_course_id);
            $stmt->fetch();
            $stmt->close();
            
            // Get mentor details from users table using username
            $get_mentor = "SELECT email, CONCAT(first_name, ' ', last_name) AS full_name 
                        FROM users 
                        WHERE username = ? AND user_type = 'Mentor'";
            $stmt = $conn->prepare($get_mentor);
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $stmt->bind_result($mentor_email, $mentor_full_name);
            $stmt->fetch();
            $stmt->close();
            
            // Get current course title
            $current_course_title = 'N/A';
            if ($current_course_id) {
                $get_current = "SELECT Course_Title FROM courses WHERE Course_ID = ?";
                $stmt = $conn->prepare($get_current);
                $stmt->bind_param("i", $current_course_id);
                $stmt->execute();
                $stmt->bind_result($current_course_title);
                $stmt->fetch();
                $stmt->close();
            }
            
            // Update request status to Rejected with reason
            $update_request = "UPDATE mentor_requests SET status = 'Rejected', admin_response = ? WHERE request_id = ?";
            $stmt = $conn->prepare($update_request);
            $stmt->bind_param("si", $rejection_reason, $request_id);
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
                    $email->setSubject("Resignation Request Rejected - COACH Program");
                    $email->addTo($mentor_email, $mentor_full_name);
                    
                    $safe_reason = htmlspecialchars($rejection_reason);
                    
                    $html_body = "
                    <html>
                    <head>
                    <style>
                        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                        .container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 5px; background-color:rgb(241, 223, 252); }
                        .header { background-color: #562b63; padding: 15px; color: white; text-align: center; border-radius: 5px 5px 0 0; }
                        .content { padding: 20px; background-color: #f9f9f9; }
                        .course-box { background-color: #fff; border: 1px solid #ddd; padding: 15px; margin: 15px 0; border-radius: 5px; }
                        .rejection-badge { background-color: #dc3545; color: white; padding: 5px 10px; border-radius: 3px; display: inline-block; margin-bottom: 15px; }
                        .reason-box { background-color: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 15px 0; }
                        .footer { text-align: center; padding: 10px; font-size: 12px; color: #777; }
                    </style>
                    </head>
                    <body>
                    <div class='container'>
                        <div class='header'>
                        <h2>Resignation Request Update</h2>
                        </div>
                        <div class='content'>
                        <div class='rejection-badge'>✗ REQUEST REJECTED</div>
                        <p>Dear $mentor_full_name,</p>
                        <p>We have reviewed your resignation request. Your request has been <strong>rejected</strong> by the administrator.</p>
                        
                        <div class='course-box'>
                            <p><strong>Current Course Assignment:</strong> $current_course_title</p>
                            <p><strong>Status:</strong> You will continue mentoring this course</p>
                        </div>
                        
                        <div class='reason-box'>
                            <p><strong>Reason for Rejection:</strong></p>
                            <p>$safe_reason</p>
                        </div>
                        
                        <p>You are expected to continue your mentoring duties. If you have any questions or would like to discuss this further, please contact the administrator.</p>
                        <p>Thank you for your understanding.</p>
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
                    } else {
                        $email_sent_status = 'Email failed (Status: ' . $response->statusCode() . ')';
                        error_log("SendGrid Resignation Rejection Error: Status=" . $response->statusCode());
                    }
                    
                } catch (\Exception $email_e) {
                    error_log("Resignation Rejection Email Exception: " . $email_e->getMessage());
                    $email_sent_status = 'Email error: ' . $email_e->getMessage();
                }
            } else {
                if (!$sendgrid_api_key) {
                    $email_sent_status = 'Email not sent: Missing SendGrid API key';
                } elseif (!$mentor_email) {
                    $email_sent_status = 'Email not sent: Mentor email not found (username: ' . $username . ')';
                }
            }
            
            echo json_encode([
                'success' => true, 
                'message' => "Resignation request rejected. $mentor_full_name has been notified. $email_sent_status"
            ]);
            
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => 'Error rejecting resignation: ' . $e->getMessage()]);
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

// Handle AJAX request for approving course change request from Mentor Management
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'approve_course_change_request') {
    header('Content-Type: application/json');
    $request_id = $_POST['request_id'];
    
    try {
        $conn->begin_transaction();
        
        // Get request details first
        $get_request = "SELECT username, current_course_id, wanted_course_id, reason
                       FROM mentor_requests 
                       WHERE request_id = ? AND request_type = 'Course Change'";
        $stmt = $conn->prepare($get_request);
        $stmt->bind_param("i", $request_id);
        $stmt->execute();
        $stmt->bind_result($username, $current_course_id, $wanted_course_id, $reason);
        $stmt->fetch();
        $stmt->close();
        
        // Get mentor details from users table using username
        $get_mentor = "SELECT email, CONCAT(first_name, ' ', last_name) AS full_name 
                      FROM users 
                      WHERE username = ? AND user_type = 'Mentor'";
        $stmt = $conn->prepare($get_mentor);
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->bind_result($mentor_email, $mentor_full_name);
        $stmt->fetch();
        $stmt->close();
        
        // Get current course title
        $current_course_title = null;
        if ($current_course_id) {
            $get_current = "SELECT Course_Title FROM courses WHERE Course_ID = ?";
            $stmt = $conn->prepare($get_current);
            $stmt->bind_param("i", $current_course_id);
            $stmt->execute();
            $stmt->bind_result($current_course_title);
            $stmt->fetch();
            $stmt->close();
            
            // Remove from current course - set Assigned_Mentor to NULL
            $update_current = "UPDATE courses SET Assigned_Mentor = NULL WHERE Course_ID = ?";
            $stmt = $conn->prepare($update_current);
            $stmt->bind_param("i", $current_course_id);
            $stmt->execute();
            $stmt->close();
        }
        
        // Get wanted course title
        $get_wanted = "SELECT Course_Title FROM courses WHERE Course_ID = ?";
        $stmt = $conn->prepare($get_wanted);
        $stmt->bind_param("i", $wanted_course_id);
        $stmt->execute();
        $stmt->bind_result($wanted_course_title);
        $stmt->fetch();
        $stmt->close();
        
        // Assign to wanted course
        $update_wanted = "UPDATE courses SET Assigned_Mentor = ? WHERE Course_ID = ?";
        $stmt = $conn->prepare($update_wanted);
        $stmt->bind_param("si", $mentor_full_name, $wanted_course_id);
        $stmt->execute();
        $stmt->close();
        
        // Update request status to Approved
        $update_request = "UPDATE mentor_requests SET status = 'Approved' WHERE request_id = ?";
        $stmt = $conn->prepare($update_request);
        $stmt->bind_param("i", $request_id);
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
                $email->setSubject("Course Change Request Approved - COACH Program");
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
                    .success-badge { background-color: #28a745; color: white; padding: 5px 10px; border-radius: 3px; display: inline-block; margin-bottom: 15px; }
                    .footer { text-align: center; padding: 10px; font-size: 12px; color: #777; }
                </style>
                </head>
                <body>
                <div class='container'>
                    <div class='header'>
                    <h2>Course Change Request Approved!</h2>
                    </div>
                    <div class='content'>
                    <div class='success-badge'>✓ REQUEST APPROVED</div>
                    <p>Dear $mentor_full_name,</p>
                    <p>Great news! Your course change request has been <strong>approved</strong> by the administrator.</p>
                    
                    <div class='course-box'>";
                
                if ($current_course_title) {
                    $html_body .= "<p><strong>Previous Course:</strong> $current_course_title</p>";
                }
                
                $html_body .= "
                        <p><strong>New Course Assignment:</strong> $wanted_course_title</p>
                    </div>
                    
                    <p>You have been successfully reassigned to your requested course. Please log in at <a href='https://coach-hub.online/login.php'>COACH</a> to view your updated course assignment and continue mentoring.</p>
                    <p>Thank you for your dedication to the COACH program.</p>
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
                } else {
                    $email_sent_status = 'Email failed (Status: ' . $response->statusCode() . ')';
                    error_log("SendGrid Approval Error: Status=" . $response->statusCode());
                }
                
            } catch (\Exception $email_e) {
                error_log("Course Change Approval Email Exception: " . $email_e->getMessage());
                $email_sent_status = 'Email error: ' . $email_e->getMessage();
            }
        } else {
            if (!$sendgrid_api_key) {
                $email_sent_status = 'Email not sent: Missing SendGrid API key';
            } elseif (!$mentor_email) {
                $email_sent_status = 'Email not sent: Mentor email not found';
            }
        }
        
        echo json_encode([
            'success' => true, 
            'message' => "Course change request approved! $mentor_full_name has been reassigned from " . 
                        ($current_course_title ? "'$current_course_title'" : "no course") . 
                        " to '$wanted_course_title'. $email_sent_status"
        ]);
        
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Error approving course change: ' . $e->getMessage()]);
    }
    exit();
}

// 1. Fetch all assigned courses - Fixed query to match Assigned_Mentor column structure
$assigned_courses = [];
$assigned_courses_query = "
    SELECT 
        Course_Title, 
        Skill_Level, 
        Category, 
        Assigned_Mentor
    FROM courses
    WHERE Assigned_Mentor IS NOT NULL 
    AND TRIM(Assigned_Mentor) != ''
    ORDER BY Course_Title
";

if ($stmt = $conn->prepare($assigned_courses_query)) {
    if ($stmt->execute()) {
        $stmt->bind_result($course_title, $skill_level, $category, $assigned_mentor);
        while ($stmt->fetch()) {
            $assigned_courses[] = [
                'Course_Title' => $course_title,
                'Skill_Level' => $skill_level,
                'Category' => $category,
                'Assigned_Mentor' => $assigned_mentor
            ];
        }
    }
    $stmt->close();
}


// 2. Fetch Resignation Appeals (CRITICAL: Fetch mr.request_id)
$resignation_appeals = [];
$resignation_appeals_query = "
    SELECT 
        mr.request_id,
        CONCAT(u.first_name, ' ', u.last_name) AS full_name,
        cc.Course_Title AS current_course_title,
        mr.reason,
        mr.request_date,
        mr.status
    FROM mentor_requests mr
    JOIN users u ON mr.username = u.username COLLATE utf8mb4_general_ci
    LEFT JOIN courses cc ON mr.current_course_id = cc.Course_ID
    WHERE mr.request_type = 'Resignation' AND mr.status = 'Pending'
    ORDER BY mr.request_date DESC
";

if ($stmt = $conn->prepare($resignation_appeals_query)) {
    if ($stmt->execute()) {
        $stmt->bind_result($request_id, $full_name, $current_course_title, $reason, $request_date, $status);
        while ($stmt->fetch()) {
            $resignation_appeals[] = [
                'request_id' => $request_id, // NEW
                'full_name' => $full_name,
                'current_course_title' => $current_course_title,
                'reason' => $reason,
                'request_date' => $request_date,
                'status' => $status
            ];
        }
    }
    $stmt->close();
}


// 3. Fetch Course Change Requests (CRITICAL: Fetch mr.request_id)
$course_change_requests = [];
$course_change_requests_query = "
    SELECT 
        mr.request_id,
        CONCAT(u.first_name, ' ', u.last_name) AS full_name,
        cc.Course_Title AS current_course_title,
        wc.Course_Title AS wanted_course_title,
        mr.reason,
        mr.request_date,
        mr.status
    FROM mentor_requests mr
    JOIN users u ON mr.username = u.username COLLATE utf8mb4_general_ci
    LEFT JOIN courses cc ON mr.current_course_id = cc.Course_ID
    LEFT JOIN courses wc ON mr.wanted_course_id = wc.Course_ID
    WHERE mr.request_type = 'Course Change' AND mr.status = 'Pending'
    ORDER BY mr.request_date DESC
";

if ($stmt = $conn->prepare($course_change_requests_query)) {
    if ($stmt->execute()) {
        $stmt->bind_result($request_id, $full_name, $current_course_title, $wanted_course_title, $reason, $request_date, $status);
        while ($stmt->fetch()) {
            $course_change_requests[] = [
                'request_id' => $request_id, // NEW
                'full_name' => $full_name,
                'current_course_title' => $current_course_title,
                'wanted_course_title' => $wanted_course_title,
                'reason' => $reason,
                'request_date' => $request_date,
                'status' => $status
            ];
        }
    }
    $stmt->close();
}
// --- END UPDATED DATA FETCHING ---

// --- START PHP ACTION HANDLER FOR MENTOR REQUESTS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type'])) {
    $actionType = $_POST['action_type'] ?? '';
    $requestId = $_POST['request_id'] ?? null;
    $newStatus = $_POST['new_status'] ?? '';
    
    if ($requestId && ($newStatus === 'Approved' || $newStatus === 'Rejected')) {
        // 1. Update the request status in mentor_requests table
        $updateQuery = "UPDATE mentor_requests SET status = ? WHERE request_id = ?";
        if ($stmt = $conn->prepare($updateQuery)) {
            $stmt->bind_param("si", $newStatus, $requestId);
            if (!$stmt->execute()) {
                error_log("Error updating request status: " . $stmt->error);
            }
            $stmt->close();
        }
        
        // 2. Add complex logic for approval if needed (e.g., updating course assignments)
        if ($newStatus === 'Approved') {
            // For Resignation: Logic to set course.Assigned_Mentor to NULL 
            // For Change: Logic to swap course assignments
            // This is left as a placeholder for full business logic implementation.
        }
        
        // Redirect to prevent form resubmission and show updated data
        header("Location: manage_mentors.php");
        exit();
    }
}
// --- END PHP ACTION HANDLER ---

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
    <title>Manage Mentors | Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f4f4f4;
            display: flex;
            min-height: 100vh;
        }

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

        /* Rejection Dialog Specific Styles */
        #rejectionReason {
            width: 100%;
            padding: 10px;
            margin-top: 10px;
            margin-bottom: 20px;
            border: 1px solid #ccc;
            border-radius: 5px;
            box-sizing: border-box;
            resize: vertical;
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
        <img src="<?php echo htmlspecialchars($admin_icon); ?>" alt="Admin Profile Picture" />
        <div class="admin-text">
          <span class="admin-name"><?php echo htmlspecialchars($admin_name); ?></span>
          <span class="admin-role">Moderator</span>
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
        <button id="btnManagement"><i class="fas fa-user-tie"></i> Mentor Management</button>
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

<div id="managementSection" class="table-container" style="display: none;">
    <h2 style="margin-bottom: 25px;">Mentor Management</h2>

    <h3 class="table-subtitle">Courses Assigned to Mentors</h3>
    <div class="table-wrapper" style="margin-bottom: 40px;">
        <table id="assignedCoursesTable" class="styled-table full-width-table">
            <thead>
                <tr>
                    <th>Course Title</th>
                    <th>Skill Level</th>
                    <th>Category</th>
                    <th>Assigned Mentor</th>
                </tr>
            </thead>
            <tbody>
                </tbody>
        </table>
    </div>

    <h3 class="table-subtitle">Course Change Requests</h3>
    <div class="table-wrapper">
            <table id="courseChangeRequestsTable" class="styled-table full-width-table">
                <thead>
                    <tr>
                        <th>Mentor Name</th>
                        <th>Current Course</th>
                        <th>Wanted Course</th>
                        <th>Reason</th>
                        <th>Request Date</th>
                        <th>Action</th> </tr>
                </thead>
                <tbody>
                    </tbody>
            </table>
        </div>
    
    <h3 class="table-subtitle">Resignation Appeals</h3>
    <div class="table-wrapper" style="margin-bottom: 40px;">
        <table id="resignationAppealsTable" class="styled-table full-width-table">
            <thead>
                <tr>
                    <th>Mentor Name</th>
                    <th>Current Course</th>
                    <th>Reason</th>
                    <th>Request Date</th>
                    <th>Action</th> </tr>
            </thead>
            <tbody>
                </tbody>
        </table>
    </div>
</div>

<div id="successDialog" class="logout-dialog" style="display: none;">
    <div class="logout-content">
        <h3><i class="fas fa-check-circle" style="color: #28a745;"></i> Success</h3>
        <p id="successMessage"></p>
        <div class="dialog-buttons">
            <button id="confirmSuccessBtn" type="button" class="btn-success"><i class="fas fa-thumbs-up"></i> OK</button>
        </div>
    </div>
</div>

<div id="errorDialog" class="logout-dialog" style="display: none;">
    <div class="logout-content">
        <h3><i class="fas fa-exclamation-triangle" style="color: #dc3545;"></i> Error</h3>
        <p id="errorMessage"></p>
        <div class="dialog-buttons">
            <button id="confirmErrorBtn" type="button" class="btn-danger"><i class="fas fa-times-circle"></i> Close</button>
        </div>
    </div>
</div>

<div id="rejectionDialog" class="logout-dialog" style="display: none;">
    <div class="logout-content">
        <h3><i class="fas fa-user-slash" style="color: #dc3545;"></i> Reject Mentor Application</h3>
        <p>Please enter the reason for rejecting <strong id="mentorNameReject"></strong>:</p>
        <textarea id="rejectionReason" rows="4" placeholder="Enter reason here..." required></textarea>
        <div class="dialog-buttons">
            <button id="cancelRejectionBtn" type="button" class="btn-cancel-dialog"><i class="fas fa-arrow-left"></i> Cancel</button>
            <button id="confirmRejectionBtn" type="button" class="btn-danger-dialog"><i class="fas fa-user-slash"></i> Reject</button>
        </div>
    </div>
</div>

<div id="confirmDialog" class="logout-dialog" style="display: none;">
    <div class="logout-content">
        <h3><i class="fas fa-question-circle" style="color: #ffc107;"></i> Confirmation</h3>
        <p id="confirmMessage"></p>
        <div class="dialog-buttons">
            <button id="cancelConfirmBtn" type="button" class="btn-cancel-dialog"><i class="fas fa-times"></i> Cancel</button>
            <button id="confirmActionBtn" type="button" class="btn-danger-dialog"><i class="fas fa-check"></i> Confirm</button>
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
    const btnManagement = document.getElementById('btnManagement');

    const applicants = mentorData.filter(m => m.status === 'Under Review');
    const approved = mentorData.filter(m => m.status === 'Approved');
    const rejected = mentorData.filter(m => m.status === 'Rejected');

    const updateCoursePopup = document.getElementById('updateCoursePopup');
    const courseChangePopup = document.getElementById('courseChangePopup');
    
    // Dialog elements
    const successDialog = document.getElementById('successDialog');
    const errorDialog = document.getElementById('errorDialog');
    const rejectionDialog = document.getElementById('rejectionDialog');
    const confirmDialog = document.getElementById('confirmDialog');
    let rejectionCallback = null;
    let confirmCallback = null;

    // New Data Arrays
    const assignedCourses = <?php echo json_encode($assigned_courses); ?>;
    const resignationAppeals = <?php echo json_encode($resignation_appeals); ?>;
    const courseChangeRequests = <?php echo json_encode($course_change_requests); ?>;

    // Function to populate the Assigned Courses table
    const populateAssignedCoursesTable = () => {
        const tableBody = document.querySelector('#assignedCoursesTable tbody');
        tableBody.innerHTML = ''; 
        
        if (assignedCourses.length === 0) {
            tableBody.innerHTML = '<tr><td colspan="4">No courses are currently assigned to mentors.</td></tr>';
            return;
        }

        // Category mapping
        const categoryMap = {
            'all': 'All',
            'it': 'Information Technology',
            'cs': 'Computer Science',
            'ds': 'Data Science',
            'gd': 'Game Development',
            'dat': 'Digital Animation'
        };

        assignedCourses.forEach(course => {
            const row = tableBody.insertRow();
            row.insertCell().textContent = course.Course_Title;
            row.insertCell().textContent = course.Skill_Level;
            
            // Convert category abbreviation to full name
            const categoryCell = row.insertCell();
            const categoryKey = course.Category ? course.Category.toLowerCase() : '';
            categoryCell.textContent = categoryMap[categoryKey] || course.Category || 'N/A';
            
            row.insertCell().textContent = course.Assigned_Mentor;
        });
    };

    // Function to populate the Resignation Appeals table with AJAX approval and rejection
    const populateResignationAppealsTable = () => {
        const tableBody = document.querySelector('#resignationAppealsTable tbody');
        tableBody.innerHTML = ''; 

        if (resignationAppeals.length === 0) {
            tableBody.innerHTML = '<tr><td colspan="6" style="text-align: center;">No pending resignation appeals.</td></tr>';
            return;
        }
        
        resignationAppeals.forEach(appeal => {
            const row = tableBody.insertRow();
            row.insertCell().textContent = appeal.full_name;
            row.insertCell().textContent = appeal.current_course_title || 'N/A';
            row.insertCell().textContent = appeal.reason;
            row.insertCell().textContent = appeal.request_date;
            row.insertCell().textContent = appeal.status;

            // Action Column Cell with AJAX handlers
            const actionCell = row.insertCell();
            actionCell.innerHTML = `
                <div style="display: flex; gap: 5px;">
                    <button class="action-button" style="background-color: #28a745; color: white;" 
                            onclick="approveResignationRequest(${appeal.request_id}, '${appeal.full_name.replace(/'/g, "\\'")}', '${(appeal.current_course_title || 'N/A').replace(/'/g, "\\'")}')">
                        Approve
                    </button>
                    <button class="action-button" style="background-color: #dc3545; color: white;"
                            onclick="showResignationRejectionDialog(${appeal.request_id}, '${appeal.full_name.replace(/'/g, "\\'")}', '${(appeal.current_course_title || 'N/A').replace(/'/g, "\\'")}')">
                        Reject
                    </button>
                </div>
            `;
        });
    };

    // Function to populate the Course Change Requests table with AJAX approval and rejection
    const populateCourseChangeRequestsTable = () => {
        const tableBody = document.querySelector('#courseChangeRequestsTable tbody');
        tableBody.innerHTML = ''; 

        if (courseChangeRequests.length === 0) {
            tableBody.innerHTML = '<tr><td colspan="7" style="text-align: center;">No pending course change requests.</td></tr>';
            return;
        }
        
        courseChangeRequests.forEach(request => {
            const row = tableBody.insertRow();
            row.insertCell().textContent = request.full_name;
            row.insertCell().textContent = request.current_course_title || 'N/A';
            row.insertCell().textContent = request.wanted_course_title || 'N/A';
            row.insertCell().textContent = request.reason;
            row.insertCell().textContent = request.request_date;
            row.insertCell().textContent = request.status;

            // Action Column Cell with AJAX handlers
            const actionCell = row.insertCell();
            actionCell.innerHTML = `
                <div style="display: flex; gap: 5px;">
                    <button class="action-button" style="background-color: #28a745; color: white;" 
                            onclick="approveCourseChangeRequest(${request.request_id}, '${request.full_name.replace(/'/g, "\\'")}', '${(request.current_course_title || 'N/A').replace(/'/g, "\\'")}', '${request.wanted_course_title.replace(/'/g, "\\'")}')">
                        Approve
                    </button>
                    <button class="action-button" style="background-color: #dc3545; color: white;"
                            onclick="showCourseChangeRejectionDialog(${request.request_id}, '${request.full_name.replace(/'/g, "\\'")}', '${request.wanted_course_title.replace(/'/g, "\\'")}')">
                        Reject
                    </button>
                </div>
            `;
        });
    };

    // --- New Dialog Logic ---

    // Master function to show the Mentor Management section
    const showManagementSection = () => {
        // 1. Hide the original mentor list containers
        if (detailView) detailView.classList.add('hidden');
        if (tableContainer) tableContainer.classList.add('hidden'); // Hide the main container

        // 2. Show the Management Section
        const managementSection = document.getElementById('managementSection');
        if (managementSection) managementSection.style.display = 'block';

        // 3. Update Tab Button Styles (Make this button active, others inactive)
        if (btnApplicants) btnApplicants.classList.remove('active');
        if (btnMentors) btnMentors.classList.remove('active');
        if (btnRejected) btnRejected.classList.remove('active');
        if (btnManagement) btnManagement.classList.add('active'); 

        // 4. Populate the three tables (Ensure these functions are defined above this point)
        populateAssignedCoursesTable();
        populateResignationAppealsTable();
        populateCourseChangeRequestsTable();
    };

    function showSuccessDialog(message) {
        document.getElementById('successMessage').innerHTML = message;
        successDialog.style.display = 'flex';
        document.getElementById('confirmSuccessBtn').onclick = () => {
            successDialog.style.display = 'none';
            // Reload page after successful action
            location.reload(); 
        };
    }

    function showErrorDialog(message) {
        document.getElementById('errorMessage').innerHTML = message;
        errorDialog.style.display = 'flex';
        document.getElementById('confirmErrorBtn').onclick = () => {
            errorDialog.style.display = 'none';
        };
    }
    
    function showConfirmDialog(message, callback) {
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

    function showRejectionDialog(mentorId) {
        const mentor = mentorData.find(m => m.user_id == mentorId);
        if (!mentor) return;

        document.getElementById('mentorNameReject').textContent = `${mentor.first_name} ${mentor.last_name}`;
        document.getElementById('rejectionReason').value = '';
        rejectionDialog.style.display = 'flex';

        rejectionCallback = (reason) => {
            confirmRejection(mentorId, reason);
        };
        
        document.getElementById('cancelRejectionBtn').onclick = () => {
            rejectionDialog.style.display = 'none';
        };
        
        document.getElementById('confirmRejectionBtn').onclick = () => {
            const reason = document.getElementById('rejectionReason').value.trim();
            if (reason === "") {
                showErrorDialog("Rejection reason cannot be empty.");
                return;
            }
            rejectionDialog.style.display = 'none';
            if (rejectionCallback) {
                rejectionCallback(reason);
            }
        };
    }

    // --- End New Dialog Logic ---

    function showTable(data, isApplicantView) {
        // NEW: Hide the management section and remove active class from its button
        document.getElementById('managementSection').style.display = 'none';
        if (btnManagement) {
            btnManagement.classList.remove('active');
        }
        
        // Existing logic:
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
         let credentialsLink = row.credentials ? `<a href="view_application.php?file=${encodeURIComponent(row.credentials)}&type=credentials" target="_blank"><i class="fas fa-id-card"></i> View Credentials</a>` : "No Credentials";  
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
            <p style="grid-column: 1 / -1; margin-top: 20px;"><strong>Application Files:</strong> ${resumeLink} | ${certLink} | ${credentialsLink}</p>`;

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
            showErrorDialog('Please select a course.');
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
            closeCourseAssignmentPopup();
            if (data.success) {
                showSuccessDialog(data.message);
            } else {
                showErrorDialog('Approval failed: ' + data.message);
            }
        })
        .catch(error => {
            closeCourseAssignmentPopup();
            console.error('Error:', error);
            showErrorDialog('An error occurred during approval. Please try again.');
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
                            <div>
                                <button type="button" class="btn-confirm change-btn" onclick="showCourseChangePopup(${mentorId}, ${course.Course_ID})"><i class="fas fa-exchange-alt"></i> Change Course</button>
                                <button type="button" class="btn-confirm remove-btn" onclick="initiateConfirmRemoveCourse(${mentorId}, ${course.Course_ID}, '${course.Course_Title.replace(/'/g, "\\'")}')"><i class="fas fa-trash-alt"></i> Remove</button>
                            </div>
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
            showErrorDialog('Please select a course.');
            return;
        }

        const confirmButton = document.querySelector('#courseChangePopup .btn-confirm');
        confirmButton.disabled = true;
        confirmButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';

        const formData = new FormData();
        formData.append('action', 'change_course');
        formData.append('mentor_id', mentorId);
        // Use 'null' string if oldCourseId is null to differentiate in PHP
        formData.append('old_course_id', oldCourseId ? oldCourseId : 'null'); 
        formData.append('new_course_id', newCourseId);
        
        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            closeUpdateCoursePopup();
            if (data.success) {
                showSuccessDialog(data.message);
            } else {
                showErrorDialog('Error: ' + data.message);
            }
        })
        .catch(error => {
            closeUpdateCoursePopup();
            console.error('Error:', error);
            showErrorDialog('An error occurred during course change. Please try again.');
        });
    }

    function initiateConfirmRemoveCourse(mentorId, courseId, courseTitle) {
        closeUpdateCoursePopup();
        const mentor = mentorData.find(m => m.user_id == mentorId);
        const message = `Are you sure you want to **REMOVE** ${mentor.first_name}'s assignment from the course: <br><strong>"${courseTitle}"</strong>? <br><br>The course will become available for assignment.`;
        
        showConfirmDialog(message, () => {
             // This is the callback for when 'Confirm' is pressed in the custom dialog
             performRemoveCourse(mentorId, courseId);
        });
    }

    function performRemoveCourse(mentorId, courseId) {
        // Find the mentor again (optional, for logging/context)
        const mentor = mentorData.find(m => m.user_id == mentorId);
        
        // Disable and update the button within the dialog content if it was still open
        // Note: Since the dialog is closed upon confirmation, we don't have a button to update here.
        // We'll rely on the success/error dialogs for feedback.

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
                showSuccessDialog(data.message);
            } else {
                showErrorDialog('Error: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showErrorDialog('An error occurred during removal. Please try again.');
        });
    }

    function confirmRejection(mentorId, reason) {
        // Rejection logic already handles the fetch request

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
                showSuccessDialog(data.message);
            } else {
                showErrorDialog('Rejection failed: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showErrorDialog('An error occurred during rejection. Please try again.');
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

    if (btnManagement) {
        btnManagement.onclick = showManagementSection;
    }

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

    // Function to approve course change request
    function approveCourseChangeRequest(requestId, mentorName, currentCourse, wantedCourse) {
        const message = `Are you sure you want to <strong>APPROVE</strong> the course change request?<br><br>
                        <strong>Mentor:</strong> ${mentorName}<br>
                        <strong>Current Course:</strong> ${currentCourse}<br>
                        <strong>Requested Course:</strong> ${wantedCourse}<br><br>
                        This will remove them from their current course and assign them to the new course.`;
        
        showConfirmDialog(message, () => {
            const formData = new FormData();
            formData.append('action', 'approve_course_change_request');
            formData.append('request_id', requestId);
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showSuccessDialog(data.message);
                } else {
                    showErrorDialog('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showErrorDialog('An error occurred while approving the course change. Please try again.');
            });
        });
    }

    // Function to show rejection dialog for course change requests
    function showCourseChangeRejectionDialog(requestId, mentorName, wantedCourse) {
        // Create a custom rejection dialog for course changes
        const dialogHtml = `
            <div id="courseChangeRejectionDialog" class="logout-dialog" style="display: flex;">
                <div class="logout-content">
                    <h3><i class="fas fa-times-circle" style="color: #dc3545;"></i> Reject Course Change Request</h3>
                    <p>You are about to reject <strong>${mentorName}</strong>'s request to change to:<br>
                    <strong>"${wantedCourse}"</strong></p>
                    <p>Please enter the reason for rejection:</p>
                    <textarea id="courseChangeRejectionReason" rows="4" placeholder="Enter reason here..." 
                            style="width: 100%; padding: 10px; margin-top: 10px; margin-bottom: 20px; 
                            border: 1px solid #ccc; border-radius: 5px; box-sizing: border-box; resize: vertical;" 
                            required></textarea>
                    <div class="dialog-buttons">
                        <button id="cancelCourseChangeRejectionBtn" type="button" class="btn-cancel-dialog">
                            <i class="fas fa-arrow-left"></i> Cancel
                        </button>
                        <button id="confirmCourseChangeRejectionBtn" type="button" class="btn-danger-dialog">
                            <i class="fas fa-times-circle"></i> Reject Request
                        </button>
                    </div>
                </div>
            </div>
        `;
        
        // Remove any existing dialog
        const existingDialog = document.getElementById('courseChangeRejectionDialog');
        if (existingDialog) {
            existingDialog.remove();
        }
        
        // Add dialog to body
        document.body.insertAdjacentHTML('beforeend', dialogHtml);
        
        // Set up event listeners
        document.getElementById('cancelCourseChangeRejectionBtn').onclick = () => {
            document.getElementById('courseChangeRejectionDialog').remove();
        };
        
        document.getElementById('confirmCourseChangeRejectionBtn').onclick = () => {
            const reason = document.getElementById('courseChangeRejectionReason').value.trim();
            if (reason === "") {
                showErrorDialog("Rejection reason cannot be empty.");
                return;
            }
            
            // Remove dialog
            document.getElementById('courseChangeRejectionDialog').remove();
            
            // Send rejection request
            rejectCourseChangeRequest(requestId, reason);
        };
    }

    // Function to reject course change request
    function rejectCourseChangeRequest(requestId, reason) {
        const formData = new FormData();
        formData.append('action', 'reject_course_change_request');
        formData.append('request_id', requestId);
        formData.append('rejection_reason', reason);
        
        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showSuccessDialog(data.message);
            } else {
                showErrorDialog('Error: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showErrorDialog('An error occurred while rejecting the course change. Please try again.');
        });
    }

    // Function to approve resignation request
    function approveResignationRequest(requestId, mentorName, currentCourse) {
        const message = `Are you sure you want to <strong>APPROVE</strong> the resignation request?<br><br>
                        <strong>Mentor:</strong> ${mentorName}<br>
                        <strong>Current Course:</strong> ${currentCourse}<br><br>
                        This will <strong>remove</strong> them from their assigned course.`;
        
        showConfirmDialog(message, () => {
            const formData = new FormData();
            formData.append('action', 'approve_resignation_request');
            formData.append('request_id', requestId);
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showSuccessDialog(data.message);
                } else {
                    showErrorDialog('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showErrorDialog('An error occurred while approving the resignation. Please try again.');
            });
        });
    }

    // Function to show rejection dialog for resignation requests
    function showResignationRejectionDialog(requestId, mentorName, currentCourse) {
        // Create a custom rejection dialog for resignations
        const dialogHtml = `
            <div id="resignationRejectionDialog" class="logout-dialog" style="display: flex;">
                <div class="logout-content">
                    <h3><i class="fas fa-times-circle" style="color: #dc3545;"></i> Reject Resignation Request</h3>
                    <p>You are about to reject <strong>${mentorName}</strong>'s resignation request.</p>
                    <p><strong>Current Course:</strong> ${currentCourse}</p>
                    <p>Please enter the reason for rejection:</p>
                    <textarea id="resignationRejectionReason" rows="4" placeholder="Enter reason here..." 
                            style="width: 100%; padding: 10px; margin-top: 10px; margin-bottom: 20px; 
                            border: 1px solid #ccc; border-radius: 5px; box-sizing: border-box; resize: vertical;" 
                            required></textarea>
                    <div class="dialog-buttons">
                        <button id="cancelResignationRejectionBtn" type="button" class="btn-cancel-dialog">
                            <i class="fas fa-arrow-left"></i> Cancel
                        </button>
                        <button id="confirmResignationRejectionBtn" type="button" class="btn-danger-dialog">
                            <i class="fas fa-times-circle"></i> Reject Request
                        </button>
                    </div>
                </div>
            </div>
        `;
        
        // Remove any existing dialog
        const existingDialog = document.getElementById('resignationRejectionDialog');
        if (existingDialog) {
            existingDialog.remove();
        }
        
        // Add dialog to body
        document.body.insertAdjacentHTML('beforeend', dialogHtml);
        
        // Set up event listeners
        document.getElementById('cancelResignationRejectionBtn').onclick = () => {
            document.getElementById('resignationRejectionDialog').remove();
        };
        
        document.getElementById('confirmResignationRejectionBtn').onclick = () => {
            const reason = document.getElementById('resignationRejectionReason').value.trim();
            if (reason === "") {
                showErrorDialog("Rejection reason cannot be empty.");
                return;
            }
            
            // Remove dialog
            document.getElementById('resignationRejectionDialog').remove();
            
            // Send rejection request
            rejectResignationRequest(requestId, reason);
        };
    }

    // Function to reject resignation request
    function rejectResignationRequest(requestId, reason) {
        const formData = new FormData();
        formData.append('action', 'reject_resignation_request');
        formData.append('request_id', requestId);
        formData.append('rejection_reason', reason);
        
        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showSuccessDialog(data.message);
            } else {
                showErrorDialog('Error: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showErrorDialog('An error occurred while rejecting the resignation. Please try again.');
        });
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