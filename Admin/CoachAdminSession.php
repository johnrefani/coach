<?php
session_start();
$conn = new mysqli("localhost", "root", "", "coach");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$message = "";
$sessions = [];

// Create pending_sessions table if it doesn't exist
$conn->query("
CREATE TABLE IF NOT EXISTS pending_sessions (
    Pending_ID INT AUTO_INCREMENT PRIMARY KEY,
    Mentor_Username VARCHAR(70) NOT NULL,
    Course_Title VARCHAR(250) NOT NULL,
    Session_Date DATE NOT NULL,
    Time_Slot VARCHAR(200) NOT NULL,
    Submission_Date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    Status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    Admin_Notes TEXT NULL
)");

// Handle time slot update
if (isset($_POST['update_slot_id'], $_POST['new_time_slot'])) {
    $updateId = $_POST['update_slot_id'];
    $newSlot = trim($_POST['new_time_slot']);
    $stmt = $conn->prepare("UPDATE sessions SET Time_Slot = ? WHERE Session_ID = ?");
    $stmt->bind_param("si", $newSlot, $updateId);
    if ($stmt->execute()) {
        $message = "✅ Session updated successfully.";
    }
    $stmt->close();
}

// Handle time slot deletion
if (isset($_POST['delete_slot_id'])) {
    $deleteId = $_POST['delete_slot_id'];
    $stmt = $conn->prepare("DELETE FROM sessions WHERE Session_ID = ?");
    $stmt->bind_param("i", $deleteId);
    if ($stmt->execute()) {
        $message = "🗑️ Session deleted successfully.";
    }
    $stmt->close();
}

// Handle form submission for new session
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['course_title'], $_POST['available_date'], $_POST['start_time'], $_POST['end_time']) && !isset($_POST['update_slot_id'])) {
    $course = $_POST['course_title'];
    $date = $_POST['available_date'];
    $startTime = $_POST['start_time'];
    $endTime = $_POST['end_time'];

    // Convert 24-hour format to 12-hour format with AM/PM
    $startTime12hr = date("g:i A", strtotime($startTime));
    $endTime12hr = date("g:i A", strtotime($endTime));

    $timeSlot = $startTime12hr . " - " . $endTime12hr;
    $today = date('Y-m-d');

    if ($date < $today) {
        $message = "⚠️ Cannot set sessions for past dates.";
    } else {
        // Check for duplicate time slot
        $stmt = $conn->prepare("SELECT * FROM sessions WHERE Session_Date = ? AND Time_Slot = ?");
        $stmt->bind_param("ss", $date, $timeSlot);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $message = "⚠️ Session time slot already exists for this date.";
        } else {
            $stmt = $conn->prepare("INSERT INTO sessions (Course_Title, Session_Date, Time_Slot) VALUES (?, ?, ?)");
            $stmt->bind_param("sss", $course, $date, $timeSlot);
            if ($stmt->execute()) {
                $message = "✅ Session added successfully.";
                
                // Create a forum chat for this session
                $forumTitle = "$course Session";
                $stmt = $conn->prepare("INSERT INTO forum_chats (title, course_title, session_date, time_slot) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("ssss", $forumTitle, $course, $date, $timeSlot);
                $stmt->execute();
            }
        }
        $stmt->close();
    }
}

// Handle pending session approval/rejection
if (isset($_POST['approve_pending_id'])) {
    $pendingId = $_POST['approve_pending_id'];
    
    // Get pending session details
    $stmt = $conn->prepare("SELECT * FROM pending_sessions WHERE Pending_ID = ?");
    $stmt->bind_param("i", $pendingId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $pendingSession = $result->fetch_assoc();
        $course = $pendingSession['Course_Title'];
        $date = $pendingSession['Session_Date'];
        $timeSlot = $pendingSession['Time_Slot'];
        $mentorUsername = $pendingSession['Mentor_Username'];
        
        // Check for duplicate time slot
        $stmt = $conn->prepare("SELECT * FROM sessions WHERE Session_Date = ? AND Time_Slot = ?");
        $stmt->bind_param("ss", $date, $timeSlot);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $message = "⚠️ Cannot approve: Session time slot already exists for this date.";
        } else {
            // Add to sessions table
            $stmt = $conn->prepare("INSERT INTO sessions (Course_Title, Session_Date, Time_Slot) VALUES (?, ?, ?)");
            $stmt->bind_param("sss", $course, $date, $timeSlot);
            
            if ($stmt->execute()) {
                // Update pending session status
                $stmt = $conn->prepare("UPDATE pending_sessions SET Status = 'approved' WHERE Pending_ID = ?");
                $stmt->bind_param("i", $pendingId);
                $stmt->execute();
                
                // Create a forum chat for this session
                $forumTitle = "$course Session";
                $stmt = $conn->prepare("INSERT INTO forum_chats (title, course_title, session_date, time_slot) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("ssss", $forumTitle, $course, $date, $timeSlot);
                $stmt->execute();
                
                $message = "✅ Session request approved successfully.";
            } else {
                $message = "❌ Error approving session: " . $stmt->error;
            }
        }
    } else {
        $message = "❌ Pending session not found.";
    }
}

if (isset($_POST['reject_pending_id'])) {
    $pendingId = $_POST['reject_pending_id'];
    $adminNotes = isset($_POST['admin_notes']) ? $_POST['admin_notes'] : '';
    
    // Update pending session status
    $stmt = $conn->prepare("UPDATE pending_sessions SET Status = 'rejected', Admin_Notes = ? WHERE Pending_ID = ?");
    $stmt->bind_param("si", $adminNotes, $pendingId);
    
    if ($stmt->execute()) {
        $message = "✅ Session request rejected.";
    } else {
        $message = "❌ Error rejecting session: " . $stmt->error;
    }
}

// Create tables if they don't exist
$conn->query("
CREATE TABLE IF NOT EXISTS forum_chats (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    course_title VARCHAR(200) NOT NULL,
    session_date DATE NOT NULL,
    time_slot VARCHAR(200) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    max_users INT DEFAULT 10
)
");

$conn->query("
CREATE TABLE IF NOT EXISTS forum_participants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    forum_id INT NOT NULL,
    username VARCHAR(70) NOT NULL,
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY (forum_id, username)
)
");

$conn->query("
CREATE TABLE IF NOT EXISTS chat_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(70) NOT NULL,
    display_name VARCHAR(100) NOT NULL,
    message TEXT NOT NULL,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_admin TINYINT(1) DEFAULT 0,
    chat_type ENUM('group', 'forum') DEFAULT 'group',
    forum_id INT NULL,
    file_name VARCHAR(255) NULL,
    file_path VARCHAR(255) NULL
)
");

// Create session_bookings table if it doesn't exist
$conn->query("
CREATE TABLE IF NOT EXISTS `session_bookings` (
  `booking_id` int(11) NOT NULL AUTO_INCREMENT,
  `mentee_username` varchar(70) NOT NULL,
  `course_title` varchar(200) NOT NULL,
  `session_date` date NOT NULL,
  `time_slot` varchar(100) NOT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `booking_time` timestamp NOT NULL DEFAULT current_timestamp(),
  `forum_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`booking_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

// Create booking_notifications table if it doesn't exist
$conn->query("
CREATE TABLE IF NOT EXISTS `booking_notifications` (
  `notification_id` int(11) NOT NULL AUTO_INCREMENT,
  `booking_id` int(11) NOT NULL,
  `recipient_type` enum('admin','mentor','mentee') NOT NULL,
  `recipient_username` varchar(70) NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`notification_id`),
  KEY `booking_id` (`booking_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

// Handle forum deletion
if (isset($_GET['delete_forum'])) {
    $forumId = $_GET['delete_forum'];
    
    // Delete forum participants
    $stmt = $conn->prepare("DELETE FROM forum_participants WHERE forum_id = ?");
    $stmt->bind_param("i", $forumId);
    $stmt->execute();
    
    // Delete forum messages
    $stmt = $conn->prepare("DELETE FROM chat_messages WHERE forum_id = ? AND chat_type = 'forum'");
    $stmt->bind_param("i", $forumId);
    $stmt->execute();
    
    // Delete forum
    $stmt = $conn->prepare("DELETE FROM forum_chats WHERE id = ?");
    $stmt->bind_param("i", $forumId);
    if ($stmt->execute()) {
        $message = "🗑️ Forum deleted successfully.";
    }
}

// Fetch existing sessions
$sql = "SELECT * FROM sessions ORDER BY Session_Date ASC";
$res = $conn->query($sql);
if ($res && $res->num_rows > 0) {
    while ($row = $res->fetch_assoc()) {
        $course = $row['Course_Title'];
        $date = $row['Session_Date'];
        $slot = $row['Time_Slot'];
        $sessions[$course][$date][] = ['id' => $row['Session_ID'], 'slot' => $slot];
    }
}

// Fetch pending session requests
$pendingRequests = [];
$pendingResult = $conn->query("
    SELECT ps.*, CONCAT(a.First_Name, ' ', a.Last_Name) as mentor_name 
    FROM pending_sessions ps
    JOIN applications a ON ps.Mentor_Username = a.Applicant_Username
    WHERE ps.Status = 'pending'
    ORDER BY ps.Submission_Date ASC
");
if ($pendingResult && $pendingResult->num_rows > 0) {
    while ($row = $pendingResult->fetch_assoc()) {
        $pendingRequests[] = $row;
    }
}

// Fetch courses for dropdown
$courses = [];
$res = $conn->query("SELECT Course_Title FROM courses");
if ($res && $res->num_rows > 0) {
    while ($row = $res->fetch_assoc()) {
        $courses[] = $row['Course_Title'];
    }
}

// Fetch forums for listing
$forums = [];
$forumsResult = $conn->query("
SELECT f.*, COUNT(fp.id) as current_users 
FROM forum_chats f 
LEFT JOIN forum_participants fp ON f.id = fp.forum_id 
GROUP BY f.id 
ORDER BY f.session_date ASC, f.time_slot ASC
");
if ($forumsResult && $forumsResult->num_rows > 0) {
    while ($row = $forumsResult->fetch_assoc()) {
        $forums[] = $row;
    }
}

if (!isset($_SESSION['admin_username'])) {
    header("Location: login_mentee.php");
    exit();
}

// Get admin's name for display
$currentUser = $_SESSION['admin_username'];
$stmt = $conn->prepare("SELECT Admin_Name, Admin_Icon FROM admins WHERE Admin_Username = ?");
$stmt->bind_param("s", $currentUser);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $displayName = $row['Admin_Name'];
    $_SESSION['admin_name'] = $displayName;
    $_SESSION['admin_icon'] = $row['Admin_Icon'] ?: 'img/default-admin.png';
} else {
    $displayName = $currentUser;
    $_SESSION['admin_name'] = $currentUser;
    $_SESSION['admin_icon'] = 'img/default-admin.png';
}

// Fetch all mentees for admin to add to forums
$allMentees = [];
$menteesResult = $conn->query("SELECT Username, First_Name, Last_Name FROM mentee_profiles ORDER BY First_Name, Last_Name");
if ($menteesResult && $menteesResult->num_rows > 0) {
    while ($row = $menteesResult->fetch_assoc()) {
        $allMentees[] = $row;
    }
}

// Handle adding a user to a forum
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['forum_id'], $_POST['username']) && $_POST['action'] === 'add_user_to_forum') {
    $forumId = $_POST['forum_id'];
    $usernameToAdd = $_POST['username'];
    
    // Check if user exists
    $stmt = $conn->prepare("SELECT Username FROM mentee_profiles WHERE Username = ?");
    $stmt->bind_param("s", $usernameToAdd);
    $stmt->execute();
    $userResult = $stmt->get_result();
    
    if ($userResult->num_rows > 0) {
        // Check if user is already in the forum
        $stmt = $conn->prepare("SELECT id FROM forum_participants WHERE forum_id = ? AND username = ?");
        $stmt->bind_param("is", $forumId, $usernameToAdd);
        $stmt->execute();
        $participantResult = $stmt->get_result();
        
        if ($participantResult->num_rows === 0) {
            // Add user to forum
            $stmt = $conn->prepare("INSERT INTO forum_participants (forum_id, username) VALUES (?, ?)");
            $stmt->bind_param("is", $forumId, $usernameToAdd);
            $stmt->execute();
            $message = "✅ User added to forum successfully.";
        } else {
            $message = "⚠️ User is already in this forum.";
        }
    } else {
        $message = "⚠️ User not found.";
    }
}

// Handle removing a user from a forum
if (isset($_GET['remove_user'], $_GET['forum_id'])) {
    $usernameToRemove = $_GET['remove_user'];
    $forumId = $_GET['forum_id'];
    
    $stmt = $conn->prepare("DELETE FROM forum_participants WHERE forum_id = ? AND username = ?");
    $stmt->bind_param("is", $forumId, $usernameToRemove);
    if ($stmt->execute()) {
        $message = "✅ User removed from forum successfully.";
    }
}

// Get forum participants if viewing a specific forum
$forumParticipants = [];
if (isset($_GET['view_forum'])) {
    $forumId = $_GET['view_forum'];
    $stmt = $conn->prepare("
        SELECT fp.username, 
               CASE 
                 WHEN a.Admin_Username IS NOT NULL THEN a.Admin_Name
                 ELSE CONCAT(mp.First_Name, ' ', mp.Last_Name)
               END as display_name,
               CASE WHEN a.Admin_Username IS NOT NULL THEN 1 ELSE 0 END as is_admin
        FROM forum_participants fp
        LEFT JOIN admins a ON fp.username = a.Admin_Username
        LEFT JOIN mentee_profiles mp ON fp.username = mp.Username
        WHERE fp.forum_id = ?
    ");
    $stmt->bind_param("i", $forumId);
    $stmt->execute();
    $participantsResult = $stmt->get_result();
    if ($participantsResult->num_rows > 0) {
        while ($row = $participantsResult->fetch_assoc()) {
            $forumParticipants[] = $row;
        }
    }
}

// Count unread notifications
$notifStmt = $conn->prepare("SELECT COUNT(*) as count FROM booking_notifications WHERE recipient_username = ? AND recipient_type = 'admin' AND is_read = 0");
$notifStmt->bind_param("s", $currentUser);
$notifStmt->execute();
$notifResult = $notifStmt->get_result();
$notifCount = $notifResult->fetch_assoc()['count'];
?>

<!DOCTYPE html>
<html>
    <head>
        <meta charset="UTF-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
        <link rel="stylesheet" href="css/admin_dashboardstyle.css" />
        <link rel="icon" href="coachicon.svg" type="image/svg+xml">
        <title>Admin Dashboard - Session</title>
        <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
        <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
        <style>
            /* Session Scheduler Styles */
            .container {
                padding: 20px;
                margin-top: 60px;
            }
            
            h2 {
                color: #6a2c70;
                margin-bottom: 20px;
            }
            
            h3 {
                color: #6a2c70;
                margin: 30px 0 15px;
                border-bottom: 1px solid #eee;
                padding-bottom: 10px;
            }
            
            .form-row {
                display: flex;
                flex-wrap: wrap;
                gap: 15px;
                margin-bottom: 15px;
                align-items: center;
            }
            
            label {
                font-weight: 600;
                margin-right: 5px;
            }
            
            input[type="date"],
            input[type="time"],
            select,
            textarea {
                padding: 8px 12px;
                border: 1px solid #ccc;
                border-radius: 4px;
                font-size: 14px;
            }
            
            textarea {
                width: 100%;
                min-height: 100px;
            }
            
            button {
                background-color: #6a2c70;
                color: white;
                border: none;
                padding: 8px 15px;
                border-radius: 4px;
                cursor: pointer;
                font-size: 14px;
            }
            
            button:hover {
                background-color: #5a2460;
            }
            
            .message {
                background-color: #f8f9fa;
                padding: 10px 15px;
                border-radius: 4px;
                margin: 15px 0;
                border-left: 4px solid #6a2c70;
            }
            
            .session-block {
                background-color: #f8f9fa;
                padding: 15px;
                border-radius: 8px;
                margin-bottom: 20px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            }
            
            .session-block h4 {
                color: #6a2c70;
                margin-bottom: 10px;
                font-size: 18px;
            }
            
            .session-block strong {
                display: block;
                margin: 10px 0 5px;
                color: #333;
            }
            
            .session-block ul {
                list-style-type: none;
                padding: 0;
                margin: 0;
            }
            
            .session-block li {
                padding: 8px 0;
                border-bottom: 1px solid #eee;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            
            .slot-input {
                padding: 5px 10px;
                border: 1px solid #ccc;
                border-radius: 4px;
                margin-right: 10px;
                width: 150px;
            }
            
            .inline-form {
                display: inline;
            }
            
            /* Forum Styles */
            .tabs {
                display: flex;
                margin-bottom: 20px;
                border-bottom: 1px solid #ddd;
            }
            
            .tab {
                padding: 10px 20px;
                cursor: pointer;
                border: 1px solid transparent;
                border-bottom: none;
                border-radius: 4px 4px 0 0;
                margin-right: 5px;
                background-color: #f8f9fa;
            }
            
            .tab.active {
                background-color: #6a2c70;
                color: white;
                border-color: #6a2c70;
            }
            
            .tab-content {
                display: none;
            }
            
            .tab-content.active {
                display: block;
            }
            
            .forum-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
                gap: 20px;
            }
            
            .forum-card {
                background-color: white;
                border-radius: 8px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                padding: 15px;
                transition: transform 0.2s;
            }
            
            .forum-card:hover {
                transform: translateY(-5px);
                box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            }
            
            .forum-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 10px;
                padding-bottom: 10px;
                border-bottom: 1px solid #eee;
            }
            
            .forum-title {
                font-size: 18px;
                font-weight: 600;
                color: #6a2c70;
            }
            
            .forum-actions {
                display: flex;
                gap: 10px;
            }
            
            .forum-actions a {
                color: #6a2c70;
                font-size: 18px;
            }
            
            .forum-detail {
                display: flex;
                align-items: center;
                gap: 8px;
                margin-bottom: 8px;
                color: #555;
            }
            
            .forum-detail ion-icon {
                color: #6a2c70;
                font-size: 16px;
            }
            
            .forum-footer {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-top: 15px;
                padding-top: 10px;
                border-top: 1px solid #eee;
            }
            
            .forum-stats {
                display: flex;
                gap: 15px;
            }
            
            .forum-stat {
                display: flex;
                align-items: center;
                gap: 5px;
                color: #777;
                font-size: 14px;
            }
            
            .forum-button {
                background-color: #6a2c70;
                color: white;
                border: none;
                padding: 8px 15px;
                border-radius: 4px;
                cursor: pointer;
                font-size: 14px;
                text-decoration: none;
                display: inline-flex;
                align-items: center;
                gap: 5px;
            }
            
            .forum-button:hover {
                background-color: #5a2460;
            }
            
            .participant-list {
                margin-top: 20px;
            }
            
            .participant-item {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 10px;
                border-bottom: 1px solid #eee;
            }
            
            .participant-info {
                display: flex;
                align-items: center;
                gap: 10px;
            }
            
            .participant-name {
                font-weight: 600;
            }
            
            .participant-badge {
                background-color: #e6f7ff;
                color: #1890ff;
                padding: 2px 8px;
                border-radius: 10px;
                font-size: 12px;
            }
            
            .participant-badge.admin {
                background-color: #f6ffed;
                color: #52c41a;
            }
            
            .participant-actions a {
                color: #ff4d4f;
                font-size: 16px;
            }
            
            .add-participant-form {
                margin-top: 20px;
                padding: 15px;
                background-color: #f8f9fa;
                border-radius: 8px;
            }
            
            .add-participant-form h4 {
                margin-top: 0;
                margin-bottom: 15px;
            }
            
            .add-participant-form .form-row {
                margin-bottom: 0;
            }
            
            .back-button {
                display: inline-flex;
                align-items: center;
                gap: 5px;
                color: #6a2c70;
                text-decoration: none;
                margin-bottom: 20px;
            }
            
            .back-button:hover {
                text-decoration: underline;
            }
            
            /* Pending Sessions Styles */
            .pending-request {
                background-color: white;
                border-radius: 8px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                padding: 15px;
                margin-bottom: 20px;
            }
            
            .pending-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 15px;
                padding-bottom: 10px;
                border-bottom: 1px solid #eee;
            }
            
            .pending-title {
                font-size: 18px;
                font-weight: 600;
                color: #6a2c70;
            }
            
            .pending-mentor {
                font-weight: 600;
                color: #1890ff;
            }
            
            .pending-details {
                margin-bottom: 15px;
            }
            
            .pending-detail {
                display: flex;
                align-items: center;
                gap: 8px;
                margin-bottom: 8px;
                color: #555;
            }
            
            .pending-detail ion-icon {
                color: #6a2c70;
                font-size: 16px;
            }
            
            .pending-actions {
                display: flex;
                gap: 10px;
                margin-top: 15px;
            }
            
            .approve-btn {
                background-color: #52c41a;
            }
            
            .approve-btn:hover {
                background-color: #389e0d;
            }
            
            .reject-btn {
                background-color: #ff4d4f;
            }
            
            .reject-btn:hover {
                background-color: #cf1322;
            }
            
            .modal {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background-color: rgba(0,0,0,0.5);
                z-index: 1000;
                justify-content: center;
                align-items: center;
            }
            
            .modal.active {
                display: flex;
            }
            
            .modal-content {
                background-color: white;
                border-radius: 8px;
                padding: 20px;
                width: 90%;
                max-width: 500px;
                box-shadow: 0 5px 15px rgba(0,0,0,0.3);
            }
            
            .modal-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 15px;
                padding-bottom: 10px;
                border-bottom: 1px solid #eee;
            }
            
            .modal-title {
                font-size: 18px;
                font-weight: 600;
                color: #6a2c70;
            }
            
            .modal-close {
                background: none;
                border: none;
                font-size: 24px;
                cursor: pointer;
                color: #999;
            }
            
            .modal-body {
                margin-bottom: 20px;
            }
            
            .modal-footer {
                display: flex;
                justify-content: flex-end;
                gap: 10px;
            }
            
            .badge {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 10px;
                font-size: 12px;
                font-weight: 600;
            }
            
            .badge-pending {
                background-color: #fff7e6;
                color: #fa8c16;
            }
            
            .badge-approved {
                background-color: #f6ffed;
                color: #52c41a;
            }
            
            .badge-rejected {
                background-color: #fff1f0;
                color: #ff4d4f;
            }
            
            .notification-badge {
                position: relative;
                display: inline-flex;
                align-items: center;
                justify-content: center;
            }
            
            .notification-count {
                position: absolute;
                top: -8px;
                right: -8px;
                background-color: #ff4d4f;
                color: white;
                border-radius: 50%;
                width: 18px;
                height: 18px;
                font-size: 12px;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            
            @media (max-width: 768px) {
                .form-row {
                    flex-direction: column;
                    align-items: flex-start;
                }
                
                .forum-grid {
                    grid-template-columns: 1fr;
                }
                
                .pending-actions {
                    flex-direction: column;
                }
                
                .pending-actions button {
                    width: 100%;
                }
            }
        </style>
    </head>
    <body>
        <nav>
            <div class="nav-top">
                <div class="logo">
                    <div class="logo-image"><img src="img/logo.png" alt="Logo"></div>
                    <div class="logo-name">COACH</div>
                </div>

                <div class="admin-profile">
                    <img src="<?php echo htmlspecialchars($_SESSION['admin_icon']); ?>" alt="Admin Profile Picture" />
                    <div class="admin-text">
                        <span class="admin-name">
                            <?php echo htmlspecialchars($_SESSION['admin_name']); ?>
                        </span>
                        <span class="admin-role">Moderator</span>
                    </div>
                    <a href="CoachAdminPFP.php?username=<?= urlencode($_SESSION['admin_username']) ?>" class="edit-profile-link" title="Edit Profile">
                        <ion-icon name="create-outline" class="verified-icon"></ion-icon>
                    </a>
                </div>
            </div>
            
  <div class="menu-items">
        <ul class="navLinks">
            <li class="navList">
                <a href="CoachAdmin.php"> <ion-icon name="home-outline"></ion-icon>
                    <span class="links">Home</span>
                </a>
            </li>
            <li class="navList">
                <a href="CoachAdminCourses.php"> <ion-icon name="book-outline"></ion-icon>
                    <span class="links">Courses</span>
                </a>
            </li>
            <li class="navList">
                <a href="CoachAdminMentees.php"> <ion-icon name="person-outline"></ion-icon>
                    <span class="links">Mentees</span>
                </a>
            </li>
             <li class="navList">
                <a href="CoachAdminMentors.php"> <ion-icon name="people-outline"></ion-icon>
                    <span class="links">Mentors</span>
                </a>
            </li>
             <li class="navList active">
                <a href="CoachAdminSession.php"> <ion-icon name="calendar-outline"></ion-icon>
                    <span class="links">Sessions</span>
                </a>
            </li>
            <li class="navList"> <a href="CoachAdminFeedback.php"> <ion-icon name="star-outline"></ion-icon>
                    <span class="links">Feedback</span>
                </a>
            </li>
            <li class="navList">
                <a href="admin-sessions.php"> <ion-icon name="chatbubbles-outline"></ion-icon>
                    <span class="links">Channels</span>
                </a>
            </li>
             <li class="navList">
                <a href="CoachAdminActivities.php"> <ion-icon name="clipboard"></ion-icon>
                    <span class="links">Activities</span>
                </a>
            </li>
             <li class="navList">
                <a href="CoachAdminResource.php"> <ion-icon name="library-outline"></ion-icon>
                    <span class="links">Resource Library</span>
                </a>
            </li>
        </ul>


                <ul class="bottom-link">
                    <li class="logout-link">
                        <a href="#" onclick="confirmLogout()" style="color: white; text-decoration: none; font-size: 18px;">
                            <ion-icon name="log-out-outline"></ion-icon>
                            Logout
                        </a>
                    </li>
                </ul>
            </div>
        </nav>

        <section class="dashboard">
            <div class="top">
                <ion-icon class="navToggle" name="menu-outline"></ion-icon>
                <img src="img/logo.png" alt="Logo">
            </div>

            <div class="container">
                <h1 style="margin-bottom: 20px;">Session Management</h1>
                
                <?php if ($message): ?>
                    <div class="message"><?= $message ?></div>
                <?php endif; ?>
                
                <div class="tabs">
                    <div class="tab <?= !isset($_GET['view_forum']) && !isset($_GET['tab']) ? 'active' : (isset($_GET['tab']) && $_GET['tab'] === 'pending' ? 'active' : '') ?>" data-tab="pending">Pending Approvals</div>
                    <div class="tab <?= isset($_GET['tab']) && $_GET['tab'] === 'scheduler' ? 'active' : '' ?>" data-tab="scheduler">Session Scheduler</div>
                    <div class="tab <?= isset($_GET['tab']) && $_GET['tab'] === 'forums' ? 'active' : '' ?>" data-tab="forums">Session Forums</div>
                </div>
                
                <div class="tab-content <?= !isset($_GET['view_forum']) && !isset($_GET['tab']) ? 'active' : (isset($_GET['tab']) && $_GET['tab'] === 'pending' ? 'active' : '') ?>" id="pending-tab">
                    <h2>Pending Session Requests</h2>
                    
                    <?php if (empty($pendingRequests)): ?>
                        <p>No pending session requests at this time.</p>
                    <?php else: ?>
                        <?php foreach ($pendingRequests as $request): ?>
                            <div class="pending-request">
                                <div class="pending-header">
                                    <div class="pending-title"><?= htmlspecialchars($request['Course_Title']) ?> Session Request</div>
                                    <div class="pending-mentor">Mentor: <?= htmlspecialchars($request['mentor_name']) ?></div>
                                </div>
                                <div class="pending-details">
                                    <div class="pending-detail">
                                        <ion-icon name="calendar-outline"></ion-icon>
                                        <span>Date: <?= date('F j, Y', strtotime($request['Session_Date'])) ?></span>
                                    </div>
                                    <div class="pending-detail">
                                        <ion-icon name="time-outline"></ion-icon>
                                        <span>Time: <?= htmlspecialchars($request['Time_Slot']) ?></span>
                                    </div>
                                    <div class="pending-detail">
                                        <ion-icon name="hourglass-outline"></ion-icon>
                                        <span>Submitted: <?= date('M j, Y g:i A', strtotime($request['Submission_Date'])) ?></span>
                                    </div>
                                </div>
                                <div class="pending-actions">
                                    <form method="POST">
                                        <input type="hidden" name="approve_pending_id" value="<?= $request['Pending_ID'] ?>">
                                        <button type="submit" class="approve-btn">
                                            <ion-icon name="checkmark-outline"></ion-icon>
                                            Approve
                                        </button>
                                    </form>
                                    <button type="button" class="reject-btn" onclick="openRejectModal(<?= $request['Pending_ID'] ?>)">
                                        <ion-icon name="close-outline"></ion-icon>
                                        Reject
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                
                <div class="tab-content <?= isset($_GET['tab']) && $_GET['tab'] === 'scheduler' ? 'active' : '' ?>" id="scheduler-tab">
                    <h2>Session Scheduler</h2>
                    
                    <div class="session-scheduler">
                        <h3>Add New Session</h3>
                        <form method="POST">
                            <div class="form-row">
                                <label>Course:</label>
                                <select name="course_title" required>
                                    <option value="">-- Select Course --</option>
                                    <?php foreach ($courses as $course): ?>
                                        <option value="<?= htmlspecialchars($course) ?>"><?= htmlspecialchars($course) ?></option>
                                    <?php endforeach; ?>
                                </select>

                                <label>Date:</label>
                                <input type="date" name="available_date" required min="<?= date('Y-m-d') ?>">
                            </div>

                            <div class="form-row">
                                <label>Start Time:</label>
                                <input type="time" name="start_time" required>

                                <label>End Time:</label>
                                <input type="time" name="end_time" required>

                                <button type="submit">Add Session</button>
                            </div>
                        </form>
                    </div>
                    
                    <h3>Available Sessions</h3>
                    <?php if (empty($sessions)): ?>
                        <p>No sessions available yet.</p>
                    <?php else: ?>
                        <?php foreach ($sessions as $course => $dates): ?>
                            <div class="session-block">
                                <h4><?= htmlspecialchars($course) ?></h4>
                                <?php foreach ($dates as $date => $slots): ?>
                                    <strong><?= htmlspecialchars($date) ?></strong>
                                    <ul>
                                        <?php foreach ($slots as $slotData): ?>
                                            <li>
                                                <form method="POST" class="inline-form">
                                                    <input type="hidden" name="update_slot_id" value="<?= $slotData['id'] ?>">
                                                    <input type="text" name="new_time_slot" value="<?= htmlspecialchars($slotData['slot']) ?>" readonly class="slot-input">
                                                    <button type="button" onclick="enableEdit(this)">Edit</button>
                                                    <button type="submit" style="display:none;">Confirm</button>
                                                </form>
                                                <form method="POST" class="inline-form" onsubmit="return confirm('Are you sure you want to delete this session?');">
                                                    <input type="hidden" name="delete_slot_id" value="<?= $slotData['id'] ?>">
                                                    <button type="submit">Delete</button>
                                                </form>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                
                <div class="tab-content <?= isset($_GET['tab']) && $_GET['tab'] === 'forums' ? 'active' : '' ?>" id="forums-tab">
                    <?php if (isset($_GET['view_forum'])): ?>
                        <a href="?tab=forums" class="back-button">
                            <ion-icon name="arrow-back-outline"></ion-icon>
                            Back to Forums
                        </a>
                        
                        <?php
                        // Get forum details
                        $forumId = $_GET['view_forum'];
                        $stmt = $conn->prepare("SELECT * FROM forum_chats WHERE id = ?");
                        $stmt->bind_param("i", $forumId);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        $forum = $result->fetch_assoc();
                        ?>
                        
                        <h2>Forum: <?= htmlspecialchars($forum['title']) ?></h2>
                        
                        <div class="forum-details">
                            <div class="forum-detail">
                                <ion-icon name="book-outline"></ion-icon>
                                <span>Course: <?= htmlspecialchars($forum['course_title']) ?></span>
                            </div>
                            <div class="forum-detail">
                                <ion-icon name="calendar-outline"></ion-icon>
                                <span>Date: <?= date('F j, Y', strtotime($forum['session_date'])) ?></span>
                            </div>
                            <div class="forum-detail">
                                <ion-icon name="time-outline"></ion-icon>
                                <span>Time: <?= htmlspecialchars($forum['time_slot']) ?></span>
                            </div>
                            <div class="forum-detail">
                                <ion-icon name="people-outline"></ion-icon>
                                <span>Max Participants: <?= $forum['max_users'] ?></span>
                            </div>
                        </div>
                        
                        <h3>Participants</h3>
                        <?php if (empty($forumParticipants)): ?>
                            <p>No participants have joined this forum yet.</p>
                        <?php else: ?>
                            <div class="participant-list">
                                <?php foreach ($forumParticipants as $participant): ?>
                                    <div class="participant-item">
                                        <div class="participant-info">
                                            <span class="participant-name"><?= htmlspecialchars($participant['display_name']) ?></span>
                                            <?php if ($participant['is_admin']): ?>
                                                <span class="participant-badge admin">Admin</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="participant-actions">
                                            <a href="?view_forum=<?= $forumId ?>&remove_user=<?= urlencode($participant['username']) ?>&tab=forums" onclick="return confirm('Are you sure you want to remove this user from the forum?');" title="Remove User">
                                                <ion-icon name="close-circle-outline"></ion-icon>
                                            </a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        
                        <div class="add-participant-form">
                            <h4>Add Participant</h4>
                            <form method="POST">
                                <input type="hidden" name="action" value="add_user_to_forum">
                                <input type="hidden" name="forum_id" value="<?= $forumId ?>">
                                <div class="form-row">
                                    <select name="username" required>
                                        <option value="">-- Select Mentee --</option>
                                        <?php foreach ($allMentees as $mentee): ?>
                                            <option value="<?= htmlspecialchars($mentee['Username']) ?>">
                                                <?= htmlspecialchars($mentee['First_Name'] . ' ' . $mentee['Last_Name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit">Add to Forum</button>
                                </div>
                            </form>
                        </div>
                        
                        <div class="forum-actions" style="margin-top: 30px;">
                            <a href="forum-chat-admin.php?view=forum&forum_id=<?= $forumId ?>" class="forum-button">
                                <ion-icon name="chatbubbles-outline"></ion-icon>
                                Join Forum Chat
                            </a>
                        </div>
                    <?php else: ?>
                        <h2>Session Forums</h2>
                        
                        <?php if (empty($forums)): ?>
                            <p>No forums available yet.</p>
                        <?php else: ?>
                            <div class="forum-grid">
                                <?php foreach ($forums as $forum): ?>
                                    <div class="forum-card">
                                        <div class="forum-header">
                                            <div class="forum-title"><?= htmlspecialchars($forum['title']) ?></div>
                                            <div class="forum-actions">
                                                <a href="?delete_forum=<?= $forum['id'] ?>&tab=forums" onclick="return confirm('Are you sure you want to delete this forum? All messages will be lost.');" title="Delete Forum">
                                                    <ion-icon name="trash-outline"></ion-icon>
                                                </a>
                                            </div>
                                        </div>
                                        <div class="forum-content">
                                            <div class="forum-detail">
                                                <ion-icon name="book-outline"></ion-icon>
                                                <span><?= htmlspecialchars($forum['course_title']) ?></span>
                                            </div>
                                            <div class="forum-detail">
                                                <ion-icon name="calendar-outline"></ion-icon>
                                                <span><?= date('F j, Y', strtotime($forum['session_date'])) ?></span>
                                            </div>
                                            <div class="forum-detail">
                                                <ion-icon name="time-outline"></ion-icon>
                                                <span><?= htmlspecialchars($forum['time_slot']) ?></span>
                                            </div>
                                            <div class="forum-detail">
                                                <ion-icon name="people-outline"></ion-icon>
                                                <span>Participants: <?= $forum['current_users'] ?>/<?= $forum['max_users'] ?></span>
                                            </div>
                                        </div>
                                        <div class="forum-footer">
                                            <div class="forum-stats">
                                                <div class="forum-stat">
                                                    <ion-icon name="time-outline"></ion-icon>
                                                    <span><?= date('M j, Y', strtotime($forum['created_at'])) ?></span>
                                                </div>
                                            </div>
                                            <a href="?view_forum=<?= $forum['id'] ?>&tab=forums" class="forum-button">
                                                <ion-icon name="people-outline"></ion-icon>
                                                Manage
                                            </a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </section>
        
        <!-- Reject Modal -->
        <div id="rejectModal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title">Reject Session Request</h3>
                    <button type="button" class="modal-close" onclick="closeRejectModal()">&times;</button>
                </div>
                <div class="modal-body">
                    <form id="rejectForm" method="POST">
                        <input type="hidden" id="reject_pending_id" name="reject_pending_id" value="">
                        <div class="form-group">
                            <label for="admin_notes">Reason for Rejection (optional):</label>
                            <textarea id="admin_notes" name="admin_notes" placeholder="Provide feedback to the mentor about why this session was rejected..."></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" onclick="closeRejectModal()">Cancel</button>
                    <button type="button" onclick="submitRejectForm()" class="reject-btn">Reject Session</button>
                </div>
            </div>
        </div>
        
        <script>
            function enableEdit(button) {
                const form = button.closest('form');
                const input = form.querySelector('input[name="new_time_slot"]');
                const confirmButton = form.querySelector('button[type="submit"]');
                input.removeAttribute('readonly');
                input.focus();
                button.style.display = 'none';
                confirmButton.style.display = 'inline';
            }
            
            function confirmLogout() {
                var confirmation = confirm("Are you sure you want to log out?");
                if (confirmation) {
                    window.location.href = "logout.php";
                }
                return false;
            }
            
            // Tab switching
            document.addEventListener('DOMContentLoaded', function() {
                const tabs = document.querySelectorAll('.tab');
                const tabContents = document.querySelectorAll('.tab-content');
                
                tabs.forEach(tab => {
                    tab.addEventListener('click', () => {
                        // Remove active class from all tabs
                        tabs.forEach(t => t.classList.remove('active'));
                        
                        // Add active class to clicked tab
                        tab.classList.add('active');
                        
                        // Hide all tab content
                        tabContents.forEach(content => {
                            content.classList.remove('active');
                        });
                        
                        // Show the corresponding tab content
                        const tabId = tab.getAttribute('data-tab') + '-tab';
                        document.getElementById(tabId).classList.add('active');
                        
                        // Update URL to maintain tab state on refresh
                        const url = new URL(window.location);
                        url.searchParams.set('tab', tab.getAttribute('data-tab'));
                        window.history.pushState({}, '', url);
                    });
                });
            });
            
            // Modal functions
            function openRejectModal(pendingId) {
                document.getElementById('reject_pending_id').value = pendingId;
                document.getElementById('rejectModal').classList.add('active');
            }
            
            function closeRejectModal() {
                document.getElementById('rejectModal').classList.remove('active');
            }
            
            function submitRejectForm() {
                document.getElementById('rejectForm').submit();
            }
        </script>
    </body>
</html>