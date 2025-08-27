<?php
session_start();

// Database connection
require 'connection/db_connection.php';

// Update chat_messages table to add file columns if they don't exist
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

// Create video_participants table if it doesn't exist
$conn->query("
CREATE TABLE IF NOT EXISTS video_participants (
    forum_id INT NOT NULL,
    username VARCHAR(70) NOT NULL,
    peer_id VARCHAR(100) NULL,
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (forum_id, username)
)");

// SESSION CHECK
if (!isset($_SESSION['username']) && !isset($_SESSION['admin_username'])) {
    header("Location: login_mentee.php");
    exit();
}

// Determine if user is admin or mentee
$isAdmin = isset($_SESSION['admin_username']);
$isMentor = false;
$currentUser = $isAdmin ? $_SESSION['admin_username'] : $_SESSION['username'];

// Check if user is a mentor
if (!$isAdmin) {
    $mentorCheck = $conn->prepare("SELECT Mentor_ID FROM applications WHERE Applicant_Username = ? AND Status = 'Approved'");
    $mentorCheck->bind_param("s", $currentUser);
    $mentorCheck->execute();
    $mentorResult = $mentorCheck->get_result();
    $isMentor = $mentorResult->num_rows > 0;
}

// Get user's first name for display
if ($isAdmin) {
    $stmt = $conn->prepare("SELECT Admin_Name FROM admins WHERE Admin_Username = ?");
    $stmt->bind_param("s", $currentUser);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $displayName = $row['Admin_Name'];
    } else {
        $displayName = $currentUser;
    }
} elseif ($isMentor) {
    $stmt = $conn->prepare("SELECT First_Name, Last_Name FROM applications WHERE Applicant_Username = ?");
    $stmt->bind_param("s", $currentUser);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $displayName = $row['First_Name'] . ' ' . $row['Last_Name'];
    } else {
        $displayName = $currentUser;
    }
} else {
    $stmt = $conn->prepare("SELECT First_Name, Last_Name FROM mentee_profiles WHERE Username = ?");
    $stmt->bind_param("s", $currentUser);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $displayName = $row['First_Name'] . ' ' . $row['Last_Name'];
    } else {
        $displayName = $currentUser;
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
    is_mentor TINYINT(1) DEFAULT 0,
    chat_type ENUM('group', 'forum') DEFAULT 'group',
    forum_id INT NULL,
    file_name VARCHAR(255) NULL,
    file_path VARCHAR(255) NULL
)
");

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
            // User has left the session or is in review mode, redirect back
            header("Location: forum-chat.php?view=forum&forum_id=" . $forumId . "&review=true");
            exit();
        }
    }
    
    // Check if session is still active
    $sessionCheck = $conn->prepare("
        SELECT * FROM forum_chats WHERE id = ?
    ");
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
        
        if (!$isSessionActive && !$isAdmin) {
            // Session is over, redirect to review mode
            header("Location: forum-chat.php?view=forum&forum_id=" . $forumId . "&review=true");
            exit();
        }
    }
    
    // Mentees can only send text messages, not files
    if (!empty($message)) {
        $stmt = $conn->prepare("INSERT INTO chat_messages (username, display_name, message, is_admin, is_mentor, chat_type, forum_id) VALUES (?, ?, ?, ?, ?, 'forum', ?)");
        $stmt->bind_param("sssiii", $currentUser, $displayName, $message, $isAdmin, $isMentor, $forumId);
        $stmt->execute();
    }
    
    // Redirect to prevent form resubmission
    header("Location: forum-chat.php?view=forum&forum_id=" . $forumId);
    exit();
}

// Handle forum creation (admin only)
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_forum') {
    $title = trim($_POST['forum_title']);
    $courseTitle = $_POST['course_title'];
    $sessionDate = $_POST['session_date'];
    $timeSlot = $_POST['time_slot'];
    
    if (!empty($title)) {
        $stmt = $conn->prepare("INSERT INTO forum_chats (title, course_title, session_date, time_slot) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssss", $title, $courseTitle, $sessionDate, $timeSlot);
        $stmt->execute();
    }
    header("Location: forum-chat.php?view=forums");
    exit();
}

// Handle leaving a chat
if (isset($_GET['action']) && $_GET['action'] === 'leave_chat' && isset($_GET['forum_id'])) {
    $forumId = $_GET['forum_id'];
    
    // Update the session_participants table to mark user as having left
    $checkParticipant = $conn->prepare("SELECT id FROM session_participants WHERE forum_id = ? AND username = ?");
    $checkParticipant->bind_param("is", $forumId, $currentUser);
    $checkParticipant->execute();
    $participantResult = $checkParticipant->get_result();
    
    if ($participantResult->num_rows > 0) {
        // Update existing record
        $updateStatus = $conn->prepare("UPDATE session_participants SET status = 'left' WHERE forum_id = ? AND username = ?");
        $updateStatus->bind_param("is", $forumId, $currentUser);
        $updateStatus->execute();
    } else {
        // Insert new record
        $insertStatus = $conn->prepare("INSERT INTO session_participants (forum_id, username, status) VALUES (?, ?, 'left')");
        $insertStatus->bind_param("is", $forumId, $currentUser);
        $insertStatus->execute();
    }
    
    // Redirect to feedback
    header("Location: feedback.php?forum_id=" . $forumId);
    exit();
}

// Handle joining a forum
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['forum_id']) && $_POST['action'] === 'join_forum') {
    $forumId = $_POST['forum_id'];
    
    // Check if forum exists and has space
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
        $forum = $result->fetch_assoc();
        
        // Check if user is already in the forum
        $stmt = $conn->prepare("SELECT id FROM forum_participants WHERE forum_id = ? AND username = ?");
        $stmt->bind_param("is", $forumId, $currentUser);
        $stmt->execute();
        $participantResult = $stmt->get_result();
        
        // Check if user has left the session before
        $checkLeft = $conn->prepare("SELECT status FROM session_participants WHERE forum_id = ? AND username = ?");
        $checkLeft->bind_param("is", $forumId, $currentUser);
        $checkLeft->execute();
        $leftResult = $checkLeft->get_result();
        $hasLeft = false;
        
        if ($leftResult->num_rows > 0) {
            $participantStatus = $leftResult->fetch_assoc()['status'];
            $hasLeft = ($participantStatus === 'left');
        }
        
        if ($participantResult->num_rows === 0 && !$hasLeft) {
            // Check if forum is full
            if ($forum['current_users'] < $forum['max_users'] || $isAdmin || $isMentor) {
                // Check if the current time matches the forum's scheduled time
                $today = date('Y-m-d');
                $currentTime = date('H:i');
                
                // Extract time range from time_slot (format: "10:00 AM - 11:00 AM")
                $timeRange = explode(' - ', $forum['time_slot']);
                $startTime = date('H:i', strtotime($timeRange[0]));
                $endTime = date('H:i', strtotime($timeRange[1]));
                
                // Check if session is active or over
                $isSessionOver = ($today > $forum['session_date']) || 
                                ($today == $forum['session_date'] && $currentTime > $endTime);
                
                // Allow admin and mentor to join anytime
                if ($isAdmin || $isMentor || !$isSessionOver) {
                    // Add user to forum participants
                    $stmt = $conn->prepare("INSERT INTO forum_participants (forum_id, username) VALUES (?, ?)");
                    $stmt->bind_param("is", $forumId, $currentUser);
                    $stmt->execute();
                    
                    // Add to session_participants with active status
                    $insertStatus = $conn->prepare("INSERT INTO session_participants (forum_id, username, status) VALUES (?, ?, 'active')");
                    $insertStatus->bind_param("is", $forumId, $currentUser);
                    $insertStatus->execute();
                    
                    header("Location: forum-chat.php?view=forum&forum_id=" . $forumId);
                    exit();
                } else {
                    // Session is over, redirect to review mode
                    $stmt = $conn->prepare("INSERT INTO forum_participants (forum_id, username) VALUES (?, ?)");
                    $stmt->bind_param("is", $forumId, $currentUser);
                    $stmt->execute();
                    
                    // Add to session_participants with review status
                    $insertStatus = $conn->prepare("INSERT INTO session_participants (forum_id, username, status) VALUES (?, ?, 'review')");
                    $insertStatus->bind_param("is", $forumId, $currentUser);
                    $insertStatus->execute();
                    
                    header("Location: forum-chat.php?view=forum&forum_id=" . $forumId . "&review=true");
                    exit();
                }
            } else {
                $error = "This forum is full (maximum 10 participants)";
            }
        } elseif ($hasLeft) {
            // User has left the session, redirect to review mode
            header("Location: forum-chat.php?view=forum&forum_id=" . $forumId . "&review=true");
            exit();
        } else {
            // User is already in the forum, check if session is active
            $today = date('Y-m-d');
            $currentTime = date('H:i');
            
            // Extract time range from time_slot
            $timeRange = explode(' - ', $forum['time_slot']);
            $endTime = date('H:i', strtotime($timeRange[1]));
            
            // Check if session is over
            $isSessionOver = ($today > $forum['session_date']) || 
                            ($today == $forum['session_date'] && $currentTime > $endTime);
            
            // Check if user has left the session
            $checkStatus = $conn->prepare("SELECT status FROM session_participants WHERE forum_id = ? AND username = ?");
            $checkStatus->bind_param("is", $forumId, $currentUser);
            $checkStatus->execute();
            $statusResult = $checkStatus->get_result();
            
            if ($statusResult->num_rows > 0) {
                $participantStatus = $statusResult->fetch_assoc()['status'];
                if ($participantStatus === 'left') {
                    // User has left the session, redirect to review mode
                    header("Location: forum-chat.php?view=forum&forum_id=" . $forumId . "&review=true");
                    exit();
                }
            }
            
            if ($isSessionOver && !$isAdmin && !$isMentor) {
                // Session is over, redirect to review mode
                header("Location: forum-chat.php?view=forum&forum_id=" . $forumId . "&review=true");
                exit();
            } else {
                // Session is still active, redirect to forum
                header("Location: forum-chat.php?view=forum&forum_id=" . $forumId);
                exit();
            }
        }
    } else {
        $error = "Forum not found";
    }
}

// Handle admin adding a user to a forum
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['forum_id'], $_POST['username']) && $_POST['action'] === 'add_user_to_forum') {
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
            
            // Add to session_participants with active status
            $insertStatus = $conn->prepare("INSERT INTO session_participants (forum_id, username, status) VALUES (?, ?, 'active')");
            $insertStatus->bind_param("is", $forumId, $usernameToAdd);
            $insertStatus->execute();
            
            $success = "User added to forum successfully";
        } else {
            $error = "User is already in this forum";
        }
    } else {
        $error = "User not found";
    }
}

// Handle removing a user from a forum (admin only)
if ($isAdmin && isset($_GET['remove_user'], $_GET['forum_id'])) {
    $usernameToRemove = $_GET['remove_user'];
    $forumId = $_GET['forum_id'];
    
    $stmt = $conn->prepare("DELETE FROM forum_participants WHERE forum_id = ? AND username = ?");
    $stmt->bind_param("is", $forumId, $usernameToRemove);
    $stmt->execute();
    
    // Also remove from session_participants
    $removeStatus = $conn->prepare("DELETE FROM session_participants WHERE forum_id = ? AND username = ?");
    $removeStatus->bind_param("is", $forumId, $usernameToRemove);
    $removeStatus->execute();
    
    header("Location: forum-chat.php?view=forum&forum_id=" . $forumId);
    exit();
}

// Determine which view to show
$view = isset($_GET['view']) ? $_GET['view'] : 'forums';

// Fetch available courses for forum creation
$courses = [];
$coursesResult = $conn->query("SELECT Course_Title FROM courses");
if ($coursesResult && $coursesResult->num_rows > 0) {
    while ($row = $coursesResult->fetch_assoc()) {
        $courses[] = $row['Course_Title'];
    }
}

// Fetch available sessions for forum creation
$sessions = [];
$sessionsResult = $conn->query("SELECT Session_ID, Course_Title, Session_Date, Time_Slot FROM sessions ORDER BY Session_Date ASC");
if ($sessionsResult && $sessionsResult->num_rows > 0) {
    while ($row = $sessionsResult->fetch_assoc()) {
        $sessions[] = $row;
    }
}

// Fetch forums for listing - only show forums where the user is a participant (unless admin/mentor)
$forums = [];
if ($isAdmin || $isMentor) {
    // Admins and mentors can see all forums
    $forumsResult = $conn->query("
    SELECT f.*, COUNT(fp.id) as current_users 
    FROM forum_chats f 
    LEFT JOIN forum_participants fp ON f.id = fp.forum_id 
    GROUP BY f.id 
    ORDER BY f.session_date ASC, f.time_slot ASC
    ");
} else {
    // Regular users can only see forums they're part of
    $stmt = $conn->prepare("
    SELECT f.*, COUNT(fp2.id) as current_users 
    FROM forum_chats f 
    INNER JOIN forum_participants fp ON f.id = fp.forum_id AND fp.username = ?
    LEFT JOIN forum_participants fp2 ON f.id = fp2.forum_id
    GROUP BY f.id 
    ORDER BY f.session_date ASC, f.time_slot ASC
    ");
    $stmt->bind_param("s", $currentUser);
    $stmt->execute();
    $forumsResult = $stmt->get_result();
}

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
        
        // Check if user is a participant or admin/mentor
        if (!$isAdmin && !$isMentor) {
            $stmt = $conn->prepare("SELECT id FROM forum_participants WHERE forum_id = ? AND username = ?");
            $stmt->bind_param("is", $forumId, $currentUser);
            $stmt->execute();
            $participantResult = $stmt->get_result();
            
            if ($participantResult->num_rows === 0) {
                // User is not a participant and not an admin/mentor, redirect to forums list
                header("Location: forum-chat.php?view=forums");
                exit();
            }
        }
        
        // Check if session is over or user is in review mode
        $today = date('Y-m-d');
        $currentTime = date('H:i');
        $timeRange = explode(' - ', $forumDetails['time_slot']);
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
        
        // Fetch participants with icons
        $stmt = $conn->prepare("
            SELECT fp.username, 
                   CASE 
                     WHEN a.Admin_Username IS NOT NULL THEN a.Admin_Name
                     WHEN app.Applicant_Username IS NOT NULL THEN CONCAT(app.First_Name, ' ', app.Last_Name)
                     ELSE CONCAT(mp.First_Name, ' ', mp.Last_Name)
                   END as display_name,
                   CASE 
                     WHEN a.Admin_Username IS NOT NULL THEN 'admin'
                     WHEN app.Applicant_Username IS NOT NULL THEN 'mentor'
                     ELSE 'mentee'
                   END as user_type,
                   CASE 
                     WHEN mp.Username IS NOT NULL THEN mp.Mentee_Icon
                     ELSE ''
                   END as icon
            FROM forum_participants fp
            LEFT JOIN admins a ON fp.username = a.Admin_Username
            LEFT JOIN applications app ON fp.username = app.Applicant_Username
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
        // Forum not found, redirect to forums list
        header("Location: forum-chat.php?view=forums");
        exit();
    }
}

// Fetch all mentees for admin to add to forums
$allMentees = [];
if ($isAdmin) {
    $menteesResult = $conn->query("SELECT Username, First_Name, Last_Name FROM mentee_profiles ORDER BY First_Name, Last_Name");
    if ($menteesResult && $menteesResult->num_rows > 0) {
        while ($row = $menteesResult->fetch_assoc()) {
            $allMentees[] = $row;
        }
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
        LIMIT 100
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

// Determine return URL based on user type
$returnUrl = $isAdmin ? "admin-sessions.php" : "CoachMentee.php";

// Get username from session
$username = isset($_SESSION['username']) ? $_SESSION['username'] : $_SESSION['admin_username'];

// Fetch First_Name and Mentee_Icon from the database
if (!$isAdmin) {
    $sql = "SELECT First_Name, Mentee_Icon FROM mentee_profiles WHERE Username = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $firstName = $row['First_Name'];
        $menteeIcon = $row['Mentee_Icon'];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $view === 'forums' ? 'Forums' : 'Forum Chat'; ?> - COACH</title>
    <link rel="icon" href="coachicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="css/mentee_navbarstyle.css" />
    <link rel="stylesheet" href="css/forum-chat.css" />
    <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
    <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
</head>
<body>
     <!-- Header -->
  <section class="background" id="home">
    <nav class="navbar">
      <div class="logo">
        <img src="img/LogoCoach.png" alt="Logo">
        <span>COACH</span>
      </div>

      <div class="nav-center">
        <ul class="nav_items" id="nav_links">
          <li><a href="CoachMenteeHome.php">Home</a></li>
          <li><a href="CoachMentee.php#courses">Courses</a></li>
          <li><a href="CoachMentee.php#resourceLibrary">Resource Library</a></li>
          <li><a href="#mentors">Activities</a></li>
          <li><a href="forum-chat.php">Sessions</a></li>
          <li><a href="group-chat.php">Forums</a></li>
        </ul>
      </div>

      <div class="nav-profile">
  <a href="#" id="profile-icon">
    <?php if (!$isAdmin && !empty($menteeIcon)): ?>
      <img src="<?php echo htmlspecialchars($menteeIcon); ?>" alt="User Icon" style="width: 35px; height: 35px; border-radius: 50%;">
    <?php else: ?>
      <ion-icon name="person-circle-outline" style="font-size: 35px;"></ion-icon>
    <?php endif; ?>
  </a>
</div>

<div class="sub-menu-wrap hide" id="profile-menu">
  <div class="sub-menu">
    <div class="user-info">
      <div class="user-icon">
        <?php if (!$isAdmin && !empty($menteeIcon)): ?>
          <img src="<?php echo htmlspecialchars($menteeIcon); ?>" alt="User Icon" style="width: 40px; height: 40px; border-radius: 50%;">
        <?php else: ?>
          <ion-icon name="person-circle-outline" style="font-size: 40px;"></ion-icon>
        <?php endif; ?>
      </div>
      <div class="user-name"><?php echo htmlspecialchars($isAdmin ? $displayName : $firstName); ?></div>
    </div>
    <ul class="sub-menu-items">
      <li><a href="profile.php">Profile</a></li>
      <li><a href="#settings">Settings</a></li>
      <li><a href="#" onclick="confirmLogout()">Logout</a></li>
    </ul>
  </div>
</div>
    </nav>
  </section>

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
                <h2>My Sessions</h2>
                <?php if ($isAdmin): ?>
                    <button class="create-forum-btn" onclick="openCreateForumModal()">
                        <ion-icon name="add-outline"></ion-icon>
                        Create Session
                    </button>
                <?php endif; ?>
            </div>
            
            <div class="forums-grid">
                <?php if (empty($forums)): ?>
                    <p>No sessions available. <?php echo (!$isAdmin && !$isMentor) ? 'Book a session to get started.' : 'Create a session to get started.'; ?></p>
                <?php else: ?>
                    <?php foreach ($forums as $forum): ?>
                        <?php
                        // Determine session status
                        $today = date('Y-m-d');
                        $currentTime = date('H:i');
                        $timeRange = explode(' - ', $forum['time_slot']);
                        $startTime = date('H:i', strtotime($timeRange[0]));
                        $endTime = date('H:i', strtotime($timeRange[1]));
                        
                        $sessionStatus = '';
                        $statusClass = '';
                        
                        if ($today < $forum['session_date']) {
                            $sessionStatus = 'Upcoming';
                            $statusClass = 'status-upcoming';
                        } elseif ($today == $forum['session_date'] && $currentTime < $endTime) {
                            $sessionStatus = 'Active';
                            $statusClass = 'status-active';
                        } else {
                            $sessionStatus = 'Ended';
                            $statusClass = 'status-ended';
                        }
                        
                        // Check if user has left this session
                        $hasLeft = false;
                        $checkLeft = $conn->prepare("SELECT status FROM session_participants WHERE forum_id = ? AND username = ?");
                        $checkLeft->bind_param("is", $forum['id'], $currentUser);
                        $checkLeft->execute();
                        $leftResult = $checkLeft->get_result();
                        
                        if ($leftResult->num_rows > 0) {
                            $participantStatus = $leftResult->fetch_assoc()['status'];
                            $hasLeft = ($participantStatus === 'left');
                        }
                        ?>
                        <div class="forum-card">
                            <h3>
                                <?php echo htmlspecialchars($forum['title']); ?>
                                <span class="session-status <?php echo $statusClass; ?>"><?php echo $sessionStatus; ?></span>
                            </h3>
                            <div class="details">
                                <p>
                                    <ion-icon name="book-outline"></ion-icon>
                                    <?php echo htmlspecialchars($forum['course_title']); ?>
                                </p>
                                <p>
                                    <ion-icon name="calendar-outline"></ion-icon>
                                    <?php echo htmlspecialchars($forum['session_date']); ?>
                                </p>
                                <p>
                                    <ion-icon name="time-outline"></ion-icon>
                                    <?php echo htmlspecialchars($forum['time_slot']); ?>
                                </p>
                            </div>
                            
                            <div class="capacity">
                                <div class="capacity-bar">
                                    <div class="capacity-fill" style="width: <?php echo ($forum['current_users'] / $forum['max_users']) * 100; ?>%;"></div>
                                </div>
                                <span class="capacity-text"><?php echo $forum['current_users']; ?>/<?php echo $forum['max_users']; ?></span>
                            </div>
                            
                            <div class="actions">
                                <form method="POST" action="">
                                    <input type="hidden" name="action" value="join_forum">
                                    <input type="hidden" name="forum_id" value="<?php echo $forum['id']; ?>">
                                    
                                    <?php if ($sessionStatus === 'Ended' || $hasLeft): ?>
                                        <button type="submit" class="review-btn">
                                            Review
                                        </button>
                                    <?php else: ?>
                                        <button type="submit" class="join-btn" <?php echo ($forum['current_users'] >= $forum['max_users'] && !$isAdmin && !$isMentor) ? 'disabled' : ''; ?>>
                                            Join Session
                                        </button>
                                    <?php endif; ?>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if ($isAdmin): ?>
            <!-- Create Forum Modal -->
            <div class="modal-overlay" id="createForumModal">
                <div class="modal-content">
                    <div class="modal-header">
                        <h3>Create New Session</h3>
                        <button class="modal-close" onclick="closeCreateForumModal()">&times;</button>
                    </div>
                    
                    <form class="modal-form" method="POST" action="">
                        <input type="hidden" name="action" value="create_forum">
                        
                        <div class="form-group">
                            <label for="forum_title">Session Title</label>
                            <input type="text" id="forum_title" name="forum_title" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="course_title">Course</label>
                            <select id="course_title" name="course_title" required>
                                <option value="">-- Select Course --</option>
                                <?php foreach ($courses as $course): ?>
                                    <option value="<?php echo htmlspecialchars($course); ?>"><?php echo htmlspecialchars($course); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="session_date">Date</label>
                            <input type="date" id="session_date" name="session_date" required min="<?php echo date('Y-m-d'); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="time_slot">Time Slot</label>
                            <select id="time_slot" name="time_slot" required>
                                <option value="">-- Select Time Slot --</option>
                                <option value="9:00 AM - 10:00 AM">9:00 AM - 10:00 AM</option>
                                <option value="10:00 AM - 11:00 AM">10:00 AM - 11:00 AM</option>
                                <option value="11:00 AM - 12:00 PM">11:00 AM - 12:00 PM</option>
                                <option value="1:00 PM - 2:00 PM">1:00 PM - 2:00 PM</option>
                                <option value="2:00 PM - 3:00 PM">2:00 PM - 3:00 PM</option>
                                <option value="3:00 PM - 4:00 PM">3:00 PM - 4:00 PM</option>
                                <option value="4:00 PM - 5:00 PM">4:00 PM - 5:00 PM</option>
                            </select>
                        </div>
                        
                        <div class="modal-actions">
                            <button type="button" class="cancel-btn" onclick="closeCreateForumModal()">Cancel</button>
                            <button type="submit" class="submit-btn">Create Session</button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    <?php elseif ($view === 'forum' && $forumDetails): ?>
        <!-- Forum Chat View -->
        <div class="chat-container" style="margin-top: 70px;">
            <!-- Forum Info -->
            <div class="forum-info">
                <?php if (!$isReviewMode && !$hasLeftSession): ?>
                    <a href="forum-chat.php?view=forum&forum_id=<?php echo $forumDetails['id']; ?>&action=leave_chat" class="leave-chat-btn" onclick="return confirm('Are you sure you want to leave this chat? You will only be able to view messages in read-only mode after leaving.')">
                        <ion-icon name="exit-outline"></ion-icon>
                        Leave Chat
                    </a>
                <?php else: ?>
                    <a href="forum-chat.php" class="leave-chat-btn">
                        <ion-icon name="arrow-back-outline"></ion-icon>
                        Back to Sessions
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
                        <span><?php echo htmlspecialchars($forumDetails['session_date']); ?></span>
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
                    $today = date('Y-m-d');
                    $currentTime = date('H:i');
                    $timeRange = explode(' - ', $forumDetails['time_slot']);
                    $startTime = date('H:i', strtotime($timeRange[0]));
                    $endTime = date('H:i', strtotime($timeRange[1]));
                    
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
                            <div class="participant <?php echo $participant['user_type']; ?>">
                                <?php if ($participant['user_type'] === 'admin'): ?>
                                    <ion-icon name="shield-outline"></ion-icon>
                                <?php elseif ($participant['user_type'] === 'mentor'): ?>
                                    <ion-icon name="school-outline"></ion-icon>
                                <?php else: ?>
                                    <ion-icon name="person-outline"></ion-icon>
                                <?php endif; ?>
                                <span><?php echo htmlspecialchars($participant['display_name']); ?></span>
                                
                                <?php if ($isAdmin && $currentUser !== $participant['username'] && $participant['user_type'] === 'mentee'): ?>
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
                                    // Check if user is already in the forum
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
                            <div class="message <?php echo $msg['is_admin'] ? 'admin' : ($msg['is_mentor'] ? 'mentor' : 'user'); ?>">
                                <div class="sender">
                                    <?php if ($msg['is_admin']): ?>
                                        <ion-icon name="shield-outline"></ion-icon>
                                    <?php elseif ($msg['is_mentor']): ?>
                                        <ion-icon name="school-outline"></ion-icon>
                                    <?php else: ?>
                                        <ion-icon name="person-outline"></ion-icon>
                                    <?php endif; ?>
                                    <?php echo htmlspecialchars($msg['display_name']); ?>
                                </div>
                                <div class="content"><?php echo htmlspecialchars($msg['message']); ?></div>
                                <?php if (isset($msg['file_name']) && $msg['file_name']): ?>
                                    <div class="file-attachment">
                                        <ion-icon name="document-outline"></ion-icon>
                                        <a href="<?php echo htmlspecialchars($msg['file_path']); ?>" target="_blank" download>
                                            <?php echo htmlspecialchars($msg['file_name']); ?>
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
                    
                    <div class="message-input-container">
                        <input type="text" name="message" placeholder="Type your message..." autocomplete="off" required>
                        <button type="submit">
                            <ion-icon name="send-outline"></ion-icon>
                        </button>
                    </div>
                </form>
                    <?php else: ?>
                        <div class="message-form" style="opacity: 0.7;">
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
        
        // Modal functions
        function openCreateForumModal() {
            document.getElementById('createForumModal').classList.add('active');
        }
        
        function closeCreateForumModal() {
            document.getElementById('createForumModal').classList.remove('active');
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
        });
        
        function confirmLogout() {
            var confirmation = confirm("Are you sure you want to log out?");
            if (confirmation) {
                // If the user clicks "OK", redirect to logout.php
                window.location.href = "logout.php";
            } else {
                // If the user clicks "Cancel", do nothing
                return false;
            }
        }
    </script>
</body>
</html>