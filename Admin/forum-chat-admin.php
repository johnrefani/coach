<?php
session_start();

// Database connection
require 'connection/db_connection.php';

// Update chat_messages table to add required columns if they don't exist
$updateSql = "ALTER TABLE chat_messages 
              ADD COLUMN IF NOT EXISTS is_mentor TINYINT(1) DEFAULT 0,
              ADD COLUMN IF NOT EXISTS file_path VARCHAR(255) NULL,
              ADD COLUMN IF NOT EXISTS file_name VARCHAR(255) NULL";
$conn->query($updateSql);

// Create session_participants table if it doesn't exist (to track who has left a session)
$conn->query("
CREATE TABLE IF NOT EXISTS session_participants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    forum_id INT NOT NULL,
    username VARCHAR(70) NOT NULL,
    status ENUM('active', 'left', 'review') DEFAULT 'active',
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY (forum_id, username)
)");

// SESSION CHECK
if (!isset($_SESSION['admin_username'])) {
    header("Location: login_admin.php");
    exit();
}

// Get admin's information
$currentUser = $_SESSION['admin_username'];
$sql = "SELECT Admin_Name FROM admins WHERE Admin_Username = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $currentUser);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 1) {
    $row = $result->fetch_assoc();
    $_SESSION['admin_name'] = $row['Admin_Name'];
    $displayName = $row['Admin_Name'];
} else {
    $_SESSION['admin_name'] = "Unknown Admin";
    $displayName = "Unknown Admin";
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
)");

$conn->query("
CREATE TABLE IF NOT EXISTS forum_participants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    forum_id INT NOT NULL,
    username VARCHAR(70) NOT NULL,
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY (forum_id, username)
)");

// Handle creating a new forum
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_forum') {
    $title = trim($_POST['title']);
    $course_title = trim($_POST['course_title']);
    $session_date = $_POST['session_date'];
    $time_slot = trim($_POST['time_slot']);
    $max_users = (int)$_POST['max_users'];

    $stmt = $conn->prepare("INSERT INTO forum_chats (title, course_title, session_date, time_slot, max_users) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssi", $title, $course_title, $session_date, $time_slot, $max_users);
    
    if ($stmt->execute()) {
        $success = "Forum created successfully!";
    } else {
        $error = "Failed to create forum. Please try again.";
    }
}

// Handle editing a forum
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_forum') {
    $forum_id = $_POST['forum_id'];
    $title = trim($_POST['title']);
    $course_title = trim($_POST['course_title']);
    $session_date = $_POST['session_date'];
    $time_slot = trim($_POST['time_slot']);
    $max_users = (int)$_POST['max_users'];

    $stmt = $conn->prepare("UPDATE forum_chats SET title = ?, course_title = ?, session_date = ?, time_slot = ?, max_users = ? WHERE id = ?");
    $stmt->bind_param("ssssii", $title, $course_title, $session_date, $time_slot, $max_users, $forum_id);
    
    if ($stmt->execute()) {
        $success = "Forum updated successfully!";
    } else {
        $error = "Failed to update forum. Please try again.";
    }
}

// Handle deleting a forum
if (isset($_GET['action']) && $_GET['action'] === 'delete_forum' && isset($_GET['forum_id'])) {
    $forumId = $_GET['forum_id'];
    $stmt = $conn->prepare("DELETE FROM forum_chats WHERE id = ?");
    $stmt->bind_param("i", $forumId);
    
    if ($stmt->execute()) {
        $success = "Forum deleted successfully!";
    } else {
        $error = "Failed to delete forum. Please try again.";
    }
}

// Handle leaving a chat
if (isset($_GET['action']) && $_GET['action'] === 'leave_chat' && isset($_GET['forum_id'])) {
    $forumId = $_GET['forum_id'];

    // Update or insert into session_participants to mark user as having left
    $checkParticipant = $conn->prepare("SELECT id FROM session_participants WHERE forum_id = ? AND username = ?");
    $checkParticipant->bind_param("is", $forumId, $currentUser);
    $checkParticipant->execute();
    $participantResult = $checkParticipant->get_result();

    if ($participantResult->num_rows > 0) {
        $updateStatus = $conn->prepare("UPDATE session_participants SET status = 'left' WHERE forum_id = ? AND username = ?");
        $updateStatus->bind_param("is", $forumId, $currentUser);
        $updateStatus->execute();
    } else {
        $insertStatus = $conn->prepare("INSERT INTO session_participants (forum_id, username, status) VALUES (?, ?, 'left')");
        $insertStatus->bind_param("is", $forumId, $currentUser);
        $insertStatus->execute();
    }

    // Redirect to forums list
    header("Location: forum-chat-admin.php?view=forums");
    exit();
}

// Handle message submission for forum chat
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['forum_id']) && isset($_POST['action']) && $_POST['action'] === 'forum_chat') {
    $message = trim($_POST['message']);
    $forumId = $_POST['forum_id'];

    // Check if user is in review mode or has left the session
    $checkStatus = $conn->prepare("SELECT status FROM session_participants WHERE forum_id = ? AND username = ?");
    $checkStatus->bind_param("is", $forumId, $currentUser);
    $checkStatus->execute();
    $statusResult = $checkStatus->get_result();

    if ($statusResult->num_rows > 0) {
        $participantStatus = $statusResult->fetch_assoc()['status'];
        if ($participantStatus === 'left' || $participantStatus === 'review') {
            header("Location: forum-chat-admin.php?view=forum&forum_id=" . $forumId . "&review=true");
            exit();
        }
    }

    // Check if session is still active
    $sessionCheck = $conn->prepare("SELECT * FROM forum_chats WHERE id = ?");
    $sessionCheck->bind_param("i", $forumId);
    $sessionCheck->execute();
    $sessionResult = $sessionCheck->get_result();

    if ($sessionResult->num_rows > 0) {
        $session = $sessionResult->fetch_assoc();
        $today = date('Y-m-d');
        $currentTime = date('H:i');

        // Extract time range from time_slot (format: "10:00 AM - 11:00 AM")
        $timeRange = explode(' - ', $session['time_slot']);
        $endTime = date('H:i', strtotime($timeRange[1]));

        // Check if session is still active
        $isSessionActive = ($today <= $session['session_date']) || 
                          ($today == $session['session_date'] && $currentTime <= $endTime);

        if (!$isSessionActive) {
            header("Location: forum-chat-admin.php?view=forum&forum_id=" . $forumId . "&review=true");
            exit();
        }
    }

    // Check if a file was uploaded
    $fileName = null;
    $filePath = null;

    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] == 0) {
        $uploadDir = 'Uploads/chat_files/';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $fileName = $_FILES['attachment']['name'];
        $tempName = $_FILES['attachment']['tmp_name'];
        $uniqueName = uniqid() . '_' . $fileName;
        $filePath = $uploadDir . $uniqueName;

        if (move_uploaded_file($tempName, $filePath)) {
            $fileName = $fileName;
            $filePath = $filePath;
        }
    }

    if (!empty($message) || $fileName) {
        $isAdmin = 1; // Admin message
        $isMentor = 0; // Not a mentor message
        $stmt = $conn->prepare("INSERT INTO chat_messages (username, display_name, message, is_admin, is_mentor, chat_type, forum_id, file_name, file_path) VALUES (?, ?, ?, ?, ?, 'forum', ?, ?, ?)");
        $stmt->bind_param("sssiiiss", $currentUser, $displayName, $message, $isAdmin, $isMentor, $forumId, $fileName, $filePath);
        $stmt->execute();
    }

    // Redirect to prevent form resubmission
    header("Location: forum-chat-admin.php?view=forum&forum_id=" . $forumId);
    exit();
}

// Handle adding a user to a forum
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_user_to_forum' && isset($_POST['forum_id']) && isset($_POST['username'])) {
    $forumId = $_POST['forum_id'];
    $username = $_POST['username'];

    // Check if user is already in the forum
    $stmt = $conn->prepare("SELECT id FROM forum_participants WHERE forum_id = ? AND username = ?");
    $stmt->bind_param("is", $forumId, $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        // Add user to forum
        $stmt = $conn->prepare("INSERT INTO forum_participants (forum_id, username) VALUES (?, ?)");
        $stmt->bind_param("is", $forumId, $username);
        if ($stmt->execute()) {
            $success = "User added successfully!";
        } else {
            $error = "Failed to add user. Please try again.";
        }
    } else {
        $error = "User is already in the forum.";
    }

    // Redirect to prevent form resubmission
    header("Location: forum-chat-admin.php?view=forum&forum_id=" . $forumId);
    exit();
}

// Handle removing a user from a forum
if (isset($_GET['action']) && $_GET['action'] === 'remove_user' && isset($_GET['forum_id']) && isset($_GET['username'])) {
    $forumId = $_GET['forum_id'];
    $username = $_GET['username'];

    // Remove user from forum
    $stmt = $conn->prepare("DELETE FROM forum_participants WHERE forum_id = ? AND username = ?");
    $stmt->bind_param("is", $forumId, $username);
    if ($stmt->execute()) {
        $success = "User removed successfully!";
    } else {
        $error = "Failed to remove user. Please try again.";
    }

    // Redirect back to forum
    header("Location: forum-chat-admin.php?view=forum&forum_id=" . $forumId);
    exit();
}

// Determine which view to show
$view = isset($_GET['view']) ? $_GET['view'] : 'forums';

// Fetch all mentees for admin to add to forums
$allMentees = [];
$menteesResult = $conn->query("SELECT Username, First_Name, Last_Name FROM mentee_profiles");
if ($menteesResult && $menteesResult->num_rows > 0) {
    while ($row = $menteesResult->fetch_assoc()) {
        $allMentees[] = $row;
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

// Fetch forum details if viewing a specific forum
$forumDetails = null;
$forumParticipants = [];
$isReviewMode = false;
$hasLeftSession = false;
$isAdmin = isset($_SESSION['admin_username']);

if ($view === 'forum' && isset($_GET['forum_id'])) {
    $forumId = $_GET['forum_id'];
    $stmt = $conn->prepare("
        SELECT f.*, COUNT(fp.id) as current_users 
        FROM forum_chats f 
        LEFT JOIN forum_participants fp ON f.id = fp.forum_id 
        WHERE f.id = ? 
        GROUP BY f.id
    ");
    $stmt->bind_param("i", $forumId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $forumDetails = $result->fetch_assoc();

        // Check if user is a participant or admin
        if (!$isAdmin) {
            $stmt = $conn->prepare("SELECT id FROM forum_participants WHERE forum_id = ? AND username = ?");
            $stmt->bind_param("is", $forumId, $currentUser);
            $stmt->execute();
            $participantResult = $stmt->get_result();
            if ($participantResult->num_rows === 0) {
                header("Location: forum-chat-admin.php?view=forums");
                exit();
            }
        }

        // Check if session is over or user is in review mode
        $today = date('Y-m-d');
        $currentTime = date('H:i');
        $timeRange = explode(' - ', $forumDetails['time_slot']);
        $startTime = date('H:i', strtotime($timeRange[0]));
        $endTime = date('H:i', strtotime($timeRange[1]));

        $isSessionOver = ($today > $forumDetails['session_date']) || 
                        ($today == $forumDetails['session_date'] && $currentTime > $endTime);

        // Check if user has explicitly left this session
        $checkLeft = $conn->prepare("SELECT status FROM session_participants WHERE forum_id = ? AND username = ?");
        $checkLeft->bind_param("is", $forumId, $currentUser);
        $checkLeft->execute();
        $leftResult = $checkLeft->get_result();

        if ($leftResult->num_rows > 0) {
            $participantStatus = $leftResult->fetch_assoc()['status'];
            $hasLeftSession = ($participantStatus === 'left');
        }

        // Set review mode if session is over, user has left, or review parameter is set
        $isReviewMode = $isSessionOver || $hasLeftSession || (isset($_GET['review']) && $_GET['review'] === 'true');

        // If in review mode and not already marked as such, update status
        if ($isReviewMode && !$hasLeftSession) {
            if ($leftResult->num_rows > 0) {
                $updateStatus = $conn->prepare("UPDATE session_participants SET status = 'review' WHERE forum_id = ? AND username = ?");
                $updateStatus->bind_param("is", $forumId, $currentUser);
                $updateStatus->execute();
            } else {
                $insertStatus = $conn->prepare("INSERT INTO session_participants (forum_id, username, status) VALUES (?, ?, 'review')");
                $insertStatus->bind_param("is", $forumId, $currentUser);
                $insertStatus->execute();
            }
        }

        // Fetch participants
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
    } else {
        header("Location: forum-chat-admin.php?view=forums");
        exit();
    }
}

// Fetch messages for the current forum
$messages = [];
if ($view === 'forum' && isset($_GET['forum_id'])) {
    $forumId = $_GET['forum_id'];
    $stmt = $conn->prepare("
        SELECT * FROM chat_messages 
        WHERE chat_type = 'forum' AND forum_id = ? 
        ORDER BY timestamp ASC 
        LIMIT 500
    ");
    $stmt->bind_param("i", $forumId);
    $stmt->execute();
    $messagesResult = $stmt->get_result();

    if ($messagesResult->num_rows > 0) {
        while ($row = $messagesResult->fetch_assoc()) {
            $messages[] = $row;
        }
    }
}

// Determine return URL
$returnUrl = "admin_dashboard.php";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $view === 'forums' ? 'Forums' : 'Forum Chat'; ?> - COACH</title>
    <link rel="icon" href="coachicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="css/mentee_navbarstyle.css" />
    <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
    <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
    <link rel="stylesheet" href="css/forum-chat-admin.css"/>
    <style>
         /* Base Styles */
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap');
        
        :root {
            --primary-color: #6a2c70;
            --nav-color: #693B69;
            --dash-color: #fff;
            --logo-color: #fff;
            --text-color: #000;
            --text-color-light: #333;
            --white: #fff;
            --border-color: #ccc;
            --toggle-color: #fff;
            --title-icon-color: #fff;
            --admin-message-bg: #f0e6f5;
            --mentor-message-bg: #e6f0f5;
            --user-message-bg: #e6f5f0;
            --time-03: all 0.3s linear;
            --time-02: all 0.2s linear;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }
        
        body {
            width: 100%;
            min-height: 100vh;
            background-color: var(--dash-color);
            display: flex;
            flex-direction: column;
        }
        
        body.dark {
            --primary-color: #3a3b3c;
            --nav-color: #181919;
            --dash-color: #262629;
            --logo-color: #ecd4ea;
            --text-color: #ecd4ea;
            --text-color-light: #ccc;
            --white: #aaa;
            --border-color: #404040;
            --toggle-color: #693b69;
            --title-icon-color: #ddd;
            --admin-message-bg: #3a3a3a;
            --mentor-message-bg: #2a3a4a;
            --user-message-bg: #2a2a2a;
        }
        
        /* Header Styles */
        .chat-header {
            background-color: var(--primary-color);
            color: white;
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        
        .chat-header h1 {
            font-size: 1.5rem;
            font-weight: 600;
        }
        
        .chat-header .actions {
            display: flex;
            gap: 15px;
        }
        
        .chat-header button {
            background-color: rgba(255, 255, 255, 0.2);
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 4px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: background-color 0.2s;
        }
        
        .chat-header button:hover {
            background-color: rgba(255, 255, 255, 0.3);
        }
        
        .chat-header button ion-icon {
            font-size: 18px;
        }
        
        /* Forums List Styles */
        .forums-container {
            max-width: 1200px;
            margin: 0 auto;
            width: 100%;
            padding: 20px;
        }
        
        .forums-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .forums-header h2 {
            font-size: 1.5rem;
            color: var(--text-color);
        }
        
        .forums-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }
        
        .forum-card {
            background-color: var(--dash-color);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 15px;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .forum-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        
        .forum-card h3 {
            font-size: 1.2rem;
            margin-bottom: 10px;
            color: var(--text-color);
        }
        
        .forum-card .details {
            margin-bottom: 15px;
        }
        
        .forum-card .details p {
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 5px;
            color: var(--text-color-light);
            font-size: 0.9rem;
        }
        
        .forum-card .details ion-icon {
            font-size: 16px;
            color: var(--primary-color);
        }
        
        .forum-card .capacity {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .forum-card .capacity-bar {
            flex: 1;
            height: 8px;
            background-color: #eee;
            border-radius: 4px;
            overflow: hidden;
        }
        
        .forum-card .capacity-fill {
            height: 100%;
            background-color: var(--primary-color);
            border-radius: 4px;
        }
        
        .forum-card .capacity-text {
            font-size: 0.8rem;
            color: var(--text-color-light);
        }
        
        .forum-card .actions {
            display: flex;
            justify-content: space-between;
        }
        
        .forum-card .join-btn, .forum-card .review-btn {
            background-color: var(--primary-color);
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 4px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .forum-card .review-btn {
            background-color: #6c757d;
        }
        
        .forum-card .join-btn:hover {
            background-color: #5a2460;
        }
        
        .forum-card .review-btn:hover {
            background-color: #5a6268;
        }
        
        /* Session Status Badge */
        .session-status {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: bold;
            margin-left: 10px;
            text-transform: uppercase;
        }
        
        .status-upcoming {
            background-color: #cce5ff;
            color: #004085;
        }
        
        .status-active {
            background-color: #d4edda;
            color: #155724;
        }
        
        .status-ended {
            background-color: #f8d7da;
            color: #721c24;
        }
        
        /* Chat Container Styles */
        .chat-container {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            grid-template-rows: repeat(2, auto);
            gap: 24px;
            max-width: 1500px;
            margin: 0 auto;
            width: 100%;
            padding: 20px;
            height: 90vh;
            max-height: 2000px;
        }
        
        /* Forum Info */
        .forum-info {
            background-color: rgba(0, 0, 0, 0.02);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 16px;
            height: 100%;

        }
        
        .forum-info h2 {
            font-size: 1.5rem;
            margin-bottom: 16px;
            padding-top: 12px;
            color: var(--text-color);
            border-top: 1px solid var(--border-color);
        }
        
        .forum-info .details {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--border-color);
        }
        
        .forum-info .detail {
            display: flex;
            align-items: center;
            gap: 5px;
            color: var(--text-color-light);
            font-size: 0.9rem;
        }
        
        .forum-info .detail ion-icon {
            font-size: 16px;
            color: var(--primary-color);
        }
        
        .forum-info .participants {
            margin-top: 16px;
        }
        
        .forum-info .participants h3 {
            font-size: 1rem;
            margin-bottom: 12px;
            color: var(--text-color);
        }
        
        .forum-info .participant-list {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .forum-info .participant {
            display: flex;
            align-items: center;
            gap: 5px;
            background-color: #f5f5f5;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
        }
        
        .forum-info .participant.admin {
            background-color: var(--admin-message-bg);
        }
        
        .forum-info .participant.mentor {
            background-color: var(--mentor-message-bg);
        }
        
        /* Messages Area */
        .messages-area {
            flex: 1;
            display: flex;             
            flex-direction: column;
            justify-content: space-between;
            overflow-y: auto;
            padding: 16px;
            background-color: rgba(0, 0, 0, 0.02);
            border-radius: 8px;
            border: 1px solid var(--border-color);
            grid-column: span 2;
            grid-row: span 2; 
            row-gap: 12px;
        }

        .message-box {
            height: 100%;
            overflow-y: auto;
            overflow-x: hidden;
        }

        .video-call {
            display: flex;
            justify-content: flex-end;
            padding: 0 16px 8px;
            border-radius: 8px 8px 0 0;
            border-bottom: 1px solid var(--border-color);
        }

        .join-video-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 12px 20px;
            background: #1e67f0;
            color: #fff;
            font-weight: 600;
            text-decoration: none;
            border-radius: 8px;
            transition: background 0.3s ease, transform 0.2s ease;
        }

        .join-video-btn ion-icon {
            font-size: 16px;
        }

        .join-video-btn:hover {
            transform: translateY(-2px);
        }

        .join-video-btn:active {
            transform: scale(0.97); 
        }

        .message-input {
            padding: 16px 8px;
        }
        
        .message {
            margin-bottom: 15px;
            max-width: 80%;
            padding: 10px 15px;
            border-radius: 8px;
            position: relative;
        }
        
        
        .message.admin {
            background-color: var(--admin-message-bg);
            align-self: flex-start;
            margin-left: auto;
            border-bottom-right-radius: 0;
        }
        
        .message.mentor {
            background-color: var(--mentor-message-bg);
            align-self: flex-start;
            margin-left: auto;
            border-bottom-right-radius: 0;
        }
        
        .message.user {
            background-color: var(--user-message-bg);
            align-self: flex-start;
            margin-right: auto;
            border-bottom-left-radius: 0;
        }
        
        .message .sender {
            font-weight: 600;
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .message .sender ion-icon {
            font-size: 16px;
        }
        
        .message .content {
            word-break: break-word;
        }
        
        .message .timestamp {
            font-size: 0.7rem;
            color: #777;
            margin-top: 5px;
            text-align: right;
        }
        
        .message .file-attachment {
            margin-top: 10px;
            padding: 8px;
            background-color: rgba(255, 255, 255, 0.5);
            border-radius: 4px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .message .file-attachment ion-icon {
            font-size: 20px;
            color: var(--primary-color);
        }
        
        .message .file-attachment a {
            color: var(--primary-color);
            text-decoration: none;
            font-size: 0.9rem;
        }
        
        .message .file-attachment a:hover {
            text-decoration: underline;
        }
        
        /* Message Form */
        .message-form {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        .file-upload-container {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .file-upload-btn {
            background-color: transparent;
            border: 1px solid var(--border-color);
            color: var(--text-color);
            padding: 8px 15px;
            border-radius: 4px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 0.9rem;
        }
        
        .file-upload-btn:hover {
            background-color: rgba(0, 0, 0, 0.05);
        }
        
        .file-name {
            font-size: 0.9rem;
            color: var(--text-color-light);
        }
        
        .message-input-container {
            display: flex;
            gap: 10px;
        }
        
        .message-form input[type="text"] {
            flex: 1;
            padding: 12px 15px;
            border: 1px solid var(--border-color);
            border-radius: 4px;
            font-size: 1rem;
            background-color: var(--dash-color);
            color: var(--text-color);
        }
        
        .message-form input[type="text"]:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(106, 44, 112, 0.2);
        }
        
        .message-form button {
            background-color: var(--primary-color);
            color: white;
            border: none;
            padding: 0 20px;
            border-radius: 4px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background-color 0.2s;
        }
        
        .message-form button:hover {
            background-color: #5a2460;
        }
        
        .message-form button ion-icon {
            font-size: 20px;
        }
        
        /* Error Message */
        .error-message {
            background-color: #ffebee;
            color: #c62828;
            padding: 10px 15px;
            border-radius: 4px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .error-message ion-icon {
            font-size: 20px;
        }
        
        /* Review Mode Banner */
        .review-mode-banner {
            background-color: #f8d7da;
            color: #721c24;
            padding: 10px 15px;
            border-radius: 4px;
            margin-bottom: 15px;
            text-align: center;
            font-weight: bold;
        }
        
        /* Leave Chat Button */
        .leave-chat-btn {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background-color: transparent;
            border: none;
            color: var(--text-color);
            cursor: pointer;
            font-size: 16px;
            padding: 5px 10px;
            border-radius: 4px;
            margin-bottom: 10px;
        }
        
        .leave-chat-btn:hover {
            background-color: rgba(0, 0, 0, 0.05);
        }
        
        /* Responsive Styles */
        @media (max-width: 768px) {
            .message {
                max-width: 100%;
            }
            
            .chat-container {
            display: grid;
            grid-template-columns: repeat(1, 1fr);
            grid-template-rows: repeat(3, 1fr);
            gap: 24px;
            max-width: 1500px;
            margin: 0 auto;
            width: 100%;
            padding: 20px;
            height: 950px;
            }

            .messages-area {
                grid-column: span 1;
                grid-row: span 2;
            }
        }
        
        @media (max-width: 480px) {
            .message-form {
                flex-direction: column;
            }
            
            .message-form button {
                width: 100%;
                padding: 10px;
            }
        }
    </style>
</head>
<body>
    <!-- Admin Header -->
    <header class="chat-header">
        <h1><?php echo $view === 'forums' ? 'Forums' : htmlspecialchars($forumDetails['title']); ?></h1>
        <div class="actions">
            <button onclick="window.location.href='<?php echo $returnUrl; ?>'">
                <ion-icon name="arrow-back-outline"></ion-icon>
                Back to Dashboard
            </button>
        </div>
    </header>

    <?php if (isset($error)): ?>
        <div class="error-message">
            <ion-icon name="alert-circle-outline"></ion-icon>
            <?php echo $error; ?>
        </div>
    <?php endif; ?>

    <?php if (isset($success)): ?>
        <div class="success-message">
            <ion-icon name="checkmark-circle-outline"></ion-icon>
            <?php echo $success; ?>
        </div>
    <?php endif; ?>

    <?php if ($view === 'forums'): ?>
        <!-- Forums List View -->
        <div class="forums-container">
            <div class="forums-header">
                <h2>Available Forums</h2>
                <button class="create-forum-btn" onclick="document.getElementById('create-forum-modal').classList.add('active')">
                    <ion-icon name="add-outline"></ion-icon>
                    Create Forum
                </button>
            </div>
            
            <div class="forums-grid">
                <?php if (empty($forums)): ?>
                    <p>No forums available yet.</p>
                <?php else: ?>
                    <?php foreach ($forums as $forum): ?>
                        <div class="forum-card">
                            <h3>
                                <?php echo htmlspecialchars($forum['title']); ?>
                                <span class="session-status <?php
                                    $today = date('Y-m-d');
                                    $currentTime = date('H:i');
                                    $timeRange = explode(' - ', $forum['time_slot']);
                                    $startTime = date('H:i', strtotime($timeRange[0]));
                                    $endTime = date('H:i', strtotime($timeRange[1]));
                                    if ($today < $forum['session_date']) {
                                        echo 'status-upcoming';
                                    } elseif ($today == $forum['session_date'] && $currentTime < $endTime) {
                                        echo 'status-active';
                                    } else {
                                        echo 'status-ended';
                                    }
                                ?>">
                                    <?php
                                        if ($today < $forum['session_date']) {
                                            echo 'Upcoming';
                                        } elseif ($today == $forum['session_date'] && $currentTime < $endTime) {
                                            echo 'Active';
                                        } else {
                                            echo 'Ended';
                                        }
                                    ?>
                                </span>
                            </h3>
                            <div class="details">
                                <p>
                                    <ion-icon name="book-outline"></ion-icon>
                                    <span><?php echo htmlspecialchars($forum['course_title']); ?></span>
                                </p>
                                <p>
                                    <ion-icon name="calendar-outline"></ion-icon>
                                    <span><?php echo date('F j, Y', strtotime($forum['session_date'])); ?></span>
                                </p>
                                <p>
                                    <ion-icon name="time-outline"></ion-icon>
                                    <span><?php echo htmlspecialchars($forum['time_slot']); ?></span>
                                </p>
                                <p>
                                    <ion-icon name="people-outline"></ion-icon>
                                    <span>Participants: <?php echo $forum['current_users']; ?>/<?php echo $forum['max_users']; ?></span>
                                </p>
                            </div>
                            
                            <div class="capacity">
                                <div class="capacity-bar">
                                    <div class="capacity-fill" style="width: <?php echo ($forum['current_users'] / $forum['max_users']) * 100; ?>%;"></div>
                                </div>
                                <span class="capacity-text"><?php echo $forum['current_users']; ?>/<?php echo $forum['max_users']; ?></span>
                            </div>
                            
                            <div class="actions">
                                <button class="join-btn" onclick="window.location.href='forum-chat-admin.php?view=forum&forum_id=<?php echo $forum['id']; ?>'">
                                    <ion-icon name="enter-outline"></ion-icon>
                                    View Forum
                                </button>
                                <div class="admin-actions">
                                    <button class="edit-btn" onclick="openEditModal(<?php echo $forum['id']; ?>, '<?php echo htmlspecialchars($forum['title'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($forum['course_title'], ENT_QUOTES); ?>', '<?php echo $forum['session_date']; ?>', '<?php echo htmlspecialchars($forum['time_slot'], ENT_QUOTES); ?>', <?php echo $forum['max_users']; ?>)">
                                        <ion-icon name="create-outline"></ion-icon>
                                        Edit
                                    </button>
                                    <a href="?action=delete_forum&forum_id=<?php echo $forum['id']; ?>" class="delete-btn" onclick="return confirm('Are you sure you want to delete this forum?')">
                                        <ion-icon name="trash-outline"></ion-icon>
                                        Delete
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Create Forum Modal -->
        <div id="create-forum-modal" class="modal-overlay">
            <div class="modal-content">
                <div class="modal-header">
                    <h3>Create New Forum</h3>
                    <button class="modal-close" onclick="document.getElementById('create-forum-modal').classList.remove('active')">&times;</button>
                </div>
                <form class="modal-form" method="POST" action="">
                    <input type="hidden" name="action" value="create_forum">
                    <div class="form-group">
                        <label for="title">Forum Title</label>
                        <input type="text" id="title" name="title" required>
                    </div>
                    <div class="form-group">
                        <label for="course_title">Course Title</label>
                        <input type="text" id="course_title" name="course_title" required>
                    </div>
                    <div class="form-group">
                        <label for="session_date">Session Date</label>
                        <input type="date" id="session_date" name="session_date" required>
                    </div>
                    <div class="form-group">
                        <label for="time_slot">Time Slot</label>
                        <input type="text" id="time_slot" name="time_slot" placeholder="e.g., 10:00 AM - 11:00 AM" required>
                    </div>
                    <div class="form-group">
                        <label for="max_users">Max Participants</label>
                        <input type="number" id="max_users" name="max_users" min="1" value="10" required>
                    </div>
                    <div class="modal-actions">
                        <button type="button" class="cancel-btn" onclick="document.getElementById('create-forum-modal').classList.remove('active')">Cancel</button>
                        <button type="submit" class="submit-btn">Create Forum</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Edit Forum Modal -->
        <div id="edit-forum-modal" class="modal-overlay">
            <div class="modal-content">
                <div class="modal-header">
                    <h3>Edit Forum</h3>
                    <button class="modal-close" onclick="document.getElementById('edit-forum-modal').classList.remove('active')">&times;</button>
                </div>
                <form class="modal-form" method="POST" action="">
                    <input type="hidden" name="action" value="edit_forum">
                    <input type="hidden" name="forum_id" id="edit_forum_id">
                    <div class="form-group">
                        <label for="edit_title">Forum Title</label>
                        <input type="text" id="edit_title" name="title" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_course_title">Course Title</label>
                        <input type="text" id="edit_course_title" name="course_title" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_session_date">Session Date</label>
                        <input type="date" id="edit_session_date" name="session_date" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_time_slot">Time Slot</label>
                        <input type="text" id="edit_time_slot" name="time_slot" placeholder="e.g., 10:00 AM - 11:00 AM" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_max_users">Max Participants</label>
                        <input type="number" id="edit_max_users" name="max_users" min="1" required>
                    </div>
                    <div class="modal-actions">
                        <button type="button" class="cancel-btn" onclick="document.getElementById('edit-forum-modal').classList.remove('active')">Cancel</button>
                        <button type="submit" class="submit-btn">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    <?php elseif ($view === 'forum' && $forumDetails): ?>
        <!-- Forum Chat View -->
        <div class="chat-container">
            <!-- Forum Info -->
            <div class="forum-info">
                <?php if (!$isReviewMode && !$hasLeftSession): ?>
                    <a href="forum-chat-admin.php?view=forum&forum_id=<?php echo $forumDetails['id']; ?>&action=leave_chat" class="leave-chat-btn" onclick="return confirm('Are you sure you want to leave this chat? You will only be able to view messages in read-only mode after leaving.')">
                        <ion-icon name="exit-outline"></ion-icon>
                        Leave Chat
                    </a>
                <?php else: ?>
                    <a href="forum-chat-admin.php" class="leave-chat-btn">
                        <ion-icon name="arrow-back-outline"></ion-icon>
                        Back to Forums
                    </a>
                <?php endif; ?>

                <h2><?php echo htmlspecialchars($forumDetails['title']); ?></h2>
                <div class="details">
                    <div class="detail">
                        <ion-icon name="book-outline"></ion-icon>
                        <span><?php echo htmlspecialchars($forumDetails['course_title']); ?></span>
                    </div>
                    <div class="detail">
                        <ion-icon name="calendar-outline"></ion-icon>
                        <span><?php echo date('F j, Y', strtotime($forumDetails['session_date'])); ?></span>
                    </div>
                    <div class="detail">
                        <ion-icon name="time-outline"></ion-icon>
                        <span><?php echo htmlspecialchars($forumDetails['time_slot']); ?></span>
                    </div>
                    <div class="detail">
                        <ion-icon name="people-outline"></ion-icon>
                        <span><?php echo count($forumParticipants); ?>/<?php echo $forumDetails['max_users']; ?> participants</span>
                    </div>
                    <?php
                    // Determine session status
                    $sessionStatus = '';
                    $statusClass = '';
                    if ($today < $forumDetails['session_date']) {
                        $sessionStatus = 'Upcoming';
                        $statusClass = 'status-upcoming';
                    } elseif ($today == $forumDetails['session_date'] && $currentTime < $endTime) {
                        $sessionStatus = 'Active';
                        $statusClass = 'status-active';
                    } else {
                        $sessionStatus = 'Ended';
                        $statusClass = 'status-ended';
                    }
                    ?>
                    <div class="detail">
                        <ion-icon name="information-circle-outline"></ion-icon>
                        <span>Status: <span class="session-status <?php echo $statusClass; ?>"><?php echo $sessionStatus; ?></span></span>
                    </div>
                </div>

                <div class="participants">
                    <h3>Participants</h3>
                    <div class="participant-list">
                        <?php foreach ($forumParticipants as $participant): ?>
                            <div class="participant <?php echo $participant['is_admin'] ? 'admin' : ''; ?>">
                                <?php if ($participant['is_admin']): ?>
                                    <ion-icon name="shield-outline"></ion-icon>
                                <?php else: ?>
                                    <ion-icon name="person-outline"></ion-icon>
                                <?php endif; ?>
                                <span><?php echo htmlspecialchars($participant['display_name']); ?></span>
                                <?php if ($isAdmin && $currentUser !== $participant['username']): ?>
                                    <a href="?view=forum&forum_id=<?php echo $forumDetails['id']; ?>&remove_user=<?php echo $participant['username']; ?>" class="remove-btn" title="Remove user" onclick="return confirm('Are you sure you want to remove this user from the forum?')">
                                        <ion-icon name="close-outline"></ion-icon>
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php if ($isAdmin): ?>
                    <form class="add-user-form" method="POST" action="">
                        <input type="hidden" name="action" value="add_user_to_forum">
                        <input type="hidden" name="forum_id" value="<?php echo $forumDetails['id']; ?>">
                        <div class="form-group">
                            <label for="username">Add User to Forum</label>
                            <select id="username" name="username" required>
                                <option value="">-- Select User --</option>
                                <?php foreach ($allMentees as $mentee): ?>
                                    <?php
                                    $isParticipant = false;
                                    foreach ($forumParticipants as $participant) {
                                        if ($participant['username'] === $mentee['Username']) {
                                            $isParticipant = true;
                                            break;
                                        }
                                    }
                                    if (!$isParticipant):
                                    ?>
                                        <option value="<?php echo htmlspecialchars($mentee['Username']); ?>">
                                            <?php echo htmlspecialchars($mentee['First_Name'] . ' ' . $mentee['Last_Name']); ?>
                                        </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit">Add User</button>
                    </form>
                <?php endif; ?>
            </div>

            <div class="messages-area" id="messages">
                <div>
                    <?php if ($isReviewMode || $hasLeftSession): ?>
                        <div class="review-mode-banner">
                            <?php if ($hasLeftSession): ?>
                                <strong>Review Mode:</strong> You have left this session. You can review the conversation but cannot send new messages.
                            <?php else: ?>
                                <strong>Review Mode:</strong> This session has ended. You can review the conversation but cannot send new messages.
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="video-call">
                    <?php if (!$isReviewMode && !$hasLeftSession && $sessionStatus === 'Active'): ?>
                        <a href="video-call.php?forum_id=<?php echo $forumDetails['id']; ?>" class="join-video-btn">
                            <ion-icon name="videocam-outline"></ion-icon>
                            Join Video Call
                        </a>
                    <?php endif; ?>
                </div>
                <div class="message-box">
                    <?php if (empty($messages)): ?>
                        <p class="no-messages">No messages yet in this forum. Start the conversation!</p>
                    <?php else: ?>
                        <?php foreach ($messages as $msg): ?>
                            <div class="message <?php echo $msg['is_admin'] ? 'admin' : 'user'; ?>">
                                <div class="sender">
                                    <?php if ($msg['is_admin']): ?>
                                        <ion-icon name="shield-outline"></ion-icon>
                                    <?php else: ?>
                                        <ion-icon name="person-outline"></ion-icon>
                                    <?php endif; ?>
                                    <?php echo htmlspecialchars($msg['display_name']); ?>
                                </div>
                                <div class="content"><?php echo htmlspecialchars($msg['message']); ?></div>
                                <?php if (!empty($msg['file_path'])): ?>
                                    <div class="attachment">
                                        <a href="<?php echo htmlspecialchars($msg['file_path']); ?>" target="_blank" download>
                                            <ion-icon name="document-outline"></ion-icon>
                                            Download Attachment
                                        </a>
                                    </div>
                                <?php endif; ?>
                                <div class="timestamp"><?php echo date('M d, g:i a', strtotime($msg['timestamp'])); ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div class="message-input">
                    <?php if (!$isReviewMode && !$hasLeftSession): ?>
                        <form class="message-form" method="POST" action="" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="forum_chat">
                            <input type="hidden" name="forum_id" value="<?php echo $forumDetails['id']; ?>">
                            <div class="attachment-container">
                                <label for="attachment" class="attachment-label">
                                    <ion-icon name="attach-outline"></ion-icon>
                                    Attach File
                                </label>
                                <input type="file" id="attachment" name="attachment" style="display: none;" onchange="updateFileName(this)">
                                <span id="file-name" class="attachment-name"></span>
                            </div>
                            <div class="message-input-container">
                                <input type="text" name="message" placeholder="Type your message..." autocomplete="off">
                                <button type="submit">
                                    <ion-icon name="send-outline"></ion-icon>
                                </button>
                            </div>
                        </form>
                    <?php else: ?>
                        <div class="message-form" style="opacity: 0.7;">
                            <div class="attachment-container">
                                <label class="attachment-label" style="cursor: not-allowed; background-color: #f5f5f5;">
                                    <ion-icon name="attach-outline"></ion-icon>
                                    Attach File
                                </label>
                                <span class="attachment-name">You cannot send files in review mode</span>
                            </div>
                            <div class="message-input-container">
                                <input type="text" placeholder="You cannot send messages in review mode" disabled>
                                <button disabled style="background-color: #ccc;">
                                    <ion-icon name="send-outline"></ion-icon>
                                </button>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <script>
        // Scroll to bottom of messages
        function scrollToBottom() {
            const messagesContainer = document.getElementById('messages');
            if (messagesContainer) {
                messagesContainer.scrollTop = messagesContainer.scrollHeight;
            }
        }

        // Call on page load
        window.onload = function() {
            scrollToBottom();
        };

        // Update file name when file is selected
        function updateFileName(input) {
            const fileName = input.files[0] ? input.files[0].name : '';
            document.getElementById('file-name').textContent = fileName;
        }

        // Auto-refresh for chat (every 5 seconds)
        <?php if ($view === 'forum'): ?>
        setInterval(function() {
            const xhr = new XMLHttpRequest();
            xhr.open('GET', window.location.href, true);
            xhr.onload = function() {
                if (xhr.status === 200) {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(xhr.responseText, 'text/html');
                    const newMessages = doc.getElementById('messages').innerHTML;
                    const currentMessages = document.getElementById('messages').innerHTML;

                    if (newMessages !== currentMessages) {
                        document.getElementById('messages').innerHTML = newMessages;
                        scrollToBottom();
                    }
                }
            };
            xhr.send();
        }, 5000);
        <?php endif; ?>

        // Function to open edit modal with pre-filled values
        function openEditModal(id, title, course_title, session_date, time_slot, max_users) {
            document.getElementById('edit_forum_id').value = id;
            document.getElementById('edit_title').value = title;
            document.getElementById('edit_course_title').value = course_title;
            document.getElementById('edit_session_date').value = session_date;
            document.getElementById('edit_time_slot').value = time_slot;
            document.getElementById('edit_max_users').value = max_users;
            document.getElementById('edit-forum-modal').classList.add('active');
        }

        
        // Modal functions
        function openCreateForumModal() {
            document.getElementById('createForumModal').classList.add('active');
        }
        
        function closeCreateForumModal() {
            document.getElementById('createForumModal').classList.remove('active');
        }
        
        
        // Function to toggle dark mode
        function toggleDarkMode() {
            document.body.classList.toggle('dark');
            localStorage.setItem('darkMode', document.body.classList.contains('dark'));
        }
        
        // Check for saved dark mode preference
        if (localStorage.getItem('darkMode') === 'true') {
            document.body.classList.add('dark');
        }

        document.addEventListener("DOMContentLoaded", function () {
            const profileIcon = document.getElementById("profile-icon");
            const profileMenu = document.getElementById("profile-menu");

            if (profileIcon && profileMenu) {
                profileIcon.addEventListener("click", function (e) {
                    e.preventDefault();
                    profileMenu.classList.toggle("show");
                    profileMenu.classList.remove("hide");
                });

                window.addEventListener("click", function (e) {
                    if (!profileMenu.contains(e.target) && !profileIcon.contains(e.target)) {
                        profileMenu.classList.remove("show");
                        profileMenu.classList.add("hide");
                    }
                });
            }
        });
        
        function confirmLogout() {
            var confirmation = confirm("Are you sure you want to log out?");
            if (confirmation) {
                window.location.href = "logout.php";
            } else {
                return false;
            }
        }
    </script>
</body>
</html>