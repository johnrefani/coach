<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();

// *** FIX: Set timezone to Philippine Time (PHT) ***
date_default_timezone_set('Asia/Manila');

// ==========================================================
// --- NEW: ANTI-CACHING HEADERS (Security Block) ---
// ==========================================================
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT"); 
// ==========================================================

// --- ACCESS CONTROL ---
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'Mentee') {
    header("Location: ../login.php");
    exit();
}

// --- FETCH USER ACCOUNT ---
require '../connection/db_connection.php';

// SESSION CHECK
if (!isset($_SESSION['username'])) {
  header("Location: ../login.php"); 
  exit();
}

$username = $_SESSION['username'];
$displayName = '';
$userIcon = 'img/default-user.png';
$userId = null;

$stmt = $conn->prepare("SELECT user_id, first_name, last_name, icon FROM users WHERE username = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $userId = $row['user_id'];
    $displayName = $row['first_name'] . ' ' . $row['last_name'];
    if (!empty($row['icon'])) $userIcon = $row['icon'];
}
$stmt->close();

if ($userId === null) {
    header("Location: login.php");
    exit();
}

// --- ENHANCED BAN CHECK WITH AUTO-UNBAN ---
$isBanned = false;
$ban_details = null;
$banCountdownTime = null;

// Check if user has an active ban
$ban_check_stmt = $conn->prepare("SELECT ban_id, reason, ban_until, ban_duration_text, ban_type FROM banned_users WHERE username = ?");
$ban_check_stmt->bind_param("s", $username);
$ban_check_stmt->execute();
$ban_result = $ban_check_stmt->get_result();

if ($ban_result->num_rows > 0) {
    $ban_details = $ban_result->fetch_assoc();
    
    // Check if ban has expired
    if ($ban_details['ban_until'] !== null && $ban_details['ban_until'] !== '') {
        $unbanTime = strtotime($ban_details['ban_until']);
        $currentTime = time();
        
        if ($currentTime >= $unbanTime) {
            // Ban has expired - remove it
            $remove_ban_stmt = $conn->prepare("DELETE FROM banned_users WHERE ban_id = ?");
            $remove_ban_stmt->bind_param("i", $ban_details['ban_id']);
            $remove_ban_stmt->execute();
            $remove_ban_stmt->close();
            
            // User is no longer banned
            $isBanned = false;
            $ban_details = null;
        } else {
            // Ban is still active
            $isBanned = true;
            $banCountdownTime = $unbanTime - $currentTime;
        }
    } else {
        // Permanent ban
        $isBanned = true;
    }
}
$ban_check_stmt->close();

// --- PROFANITY FILTER ---
function filterProfanity($text) {
    $profaneWords = ['fuck','shit','bitch','asshole','bastard','slut','whore','dick','pussy','faggot','cunt','motherfucker','cock','prick','jerkoff','cum','putangina','tangina','pakshet','gago','ulol','leche','bwisit','pucha','punyeta','hinayupak','lintik','tarantado','inutil','siraulo','bobo','tanga','pakyu','yawa','yati','pisti','buang','pendejo','cabron','maricon','chingada','mierda'];
    foreach ($profaneWords as $word) {
        $pattern = '/\b' . preg_quote($word, '/') . '\b/i';
        $text = preg_replace($pattern, '****', $text);
    }
    return $text;
}

// --- LINK HELPER ---
function makeLinksClickable($text) {
    $urlRegex = '/(https?:\/\/[^\s<]+|www\.[^\s<]+)/i';
    return preg_replace_callback($urlRegex, function($matches) {
        $url = $matches[0];
        $protocol = (strpos($url, '://') === false) ? 'http://' : '';
        return '<a href="' . htmlspecialchars($protocol . $url) . '" target="_blank" rel="noopener noreferrer">' . htmlspecialchars($url) . '</a>';
    }, $text);
}

// --- POST ACTION HANDLERS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Handle Like/Unlike
    if (($action === 'like_post' || $action === 'unlike_post') && isset($_POST['post_id'])) {
        $postId = intval($_POST['post_id']);
        $response = ['success' => false, 'message' => '', 'action' => ''];

        if ($postId > 0) {
            $stmt = $conn->prepare("SELECT COUNT(*) FROM post_likes WHERE post_id = ? AND user_id = ?");
            $stmt->bind_param("ii", $postId, $userId);
            $stmt->execute();
            $stmt->bind_result($likeCount);
            $stmt->fetch();
            $stmt->close();

            if ($likeCount == 0) {
                // Add like
                $conn->begin_transaction();
                try {
                    $stmt = $conn->prepare("INSERT INTO post_likes (post_id, user_id) VALUES (?, ?)");
                    $stmt->bind_param("ii", $postId, $userId);
                    $stmt->execute();
                    $stmt = $conn->prepare("UPDATE general_forums SET likes = likes + 1 WHERE id = ?");
                    $stmt->bind_param("i", $postId);
                    $stmt->execute();
                    $conn->commit();
                    $response['success'] = true;
                    $response['action'] = 'liked';
                } catch (mysqli_sql_exception $e) {
                    $conn->rollback();
                }
            } else {
                // Remove like
                $conn->begin_transaction();
                try {
                    $stmt = $conn->prepare("DELETE FROM post_likes WHERE post_id = ? AND user_id = ?");
                    $stmt->bind_param("ii", $postId, $userId);
                    $stmt->execute();
                    $stmt = $conn->prepare("UPDATE general_forums SET likes = GREATEST(0, likes - 1) WHERE id = ?");
                    $stmt->bind_param("i", $postId);
                    $stmt->execute();
                    $conn->commit();
                    $response['success'] = true;
                    $response['action'] = 'unliked';
                } catch (mysqli_sql_exception $e) {
                    $conn->rollback();
                }
            }
        }
        header('Content-Type: application/json');
        echo json_encode($response);
        exit();
    }

    // Handle New Post
    elseif ($action === 'create_post' && isset($_POST['post_title'], $_POST['post_content'])) {
        // Check if banned FIRST, before processing
        if ($isBanned) {
            header("Location: forums.php");
            exit();
        }
        
        // Now process the post creation (all your existing code)
        $postTitle = filterProfanity(trim($_POST['post_title']));
        $postContent = filterProfanity($_POST['post_content']);
        $filePath = null;
        $fileName = null;

        if (isset($_FILES['post_image']) && $_FILES['post_image']['error'] == 0) {
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
            if (in_array($_FILES['post_image']['type'], $allowed_types) && $_FILES['post_image']['size'] < 5000000) {
                $uploadDir = '../uploads/forum_images/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                $fileName = uniqid() . '_' . basename($_FILES['post_image']['name']);
                $filePath = $uploadDir . $fileName;
                move_uploaded_file($_FILES['post_image']['tmp_name'], $filePath);
            }
        }

        if (!empty($postTitle) && !empty($postContent)) {
            $currentDateTime = (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
            $stmt = $conn->prepare("INSERT INTO general_forums (user_id, display_name, message, is_admin, is_mentor, chat_type, title, file_path, file_name, user_icon, timestamp) VALUES (?, ?, ?, ?, ?, 'forum', ?, ?, ?, ?, ?)");
            
            $isAdmin = 0;
            $isMentor = 0;

            $stmt->bind_param("issiisssss", $userId, $displayName, $postContent, $isAdmin, $isMentor, $postTitle, $filePath, $fileName, $userIcon, $currentDateTime);
            $stmt->execute();
            $stmt->close();
        }
        header("Location: forums.php");
        exit();
    }

    // Handle New Comment
    elseif ($action === 'create_comment' && isset($_POST['comment_message'], $_POST['post_id'])) {
        // Check if banned FIRST, before processing
        if ($isBanned) {
            header("Location: forums.php");
            exit();
        }

        // Now process the comment creation
        $commentMessage = filterProfanity(trim($_POST['comment_message']));
        $postId = intval($_POST['post_id']);

        if (!empty($commentMessage) && $postId > 0) {
            $currentDateTime = (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
            
            $stmt = $conn->prepare("INSERT INTO general_forums 
                (user_id, display_name, title, message, is_admin, is_mentor, chat_type, forum_id, user_icon, timestamp) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

            $isAdmin = 0;
            $isMentor = 0;
            $chatType = 'comment';   // Set comment type
            $title = '';             // Comments don’t have titles

            $stmt->bind_param(
                "isssiissss", 
                $userId,         
                $displayName,    
                $title,          
                $commentMessage, 
                $isAdmin,        
                $isMentor,       
                $chatType,       
                $postId,         
                $userIcon,       
                $currentDateTime 
            );

            $stmt->execute();
            $stmt->close();
        }
        // FIXED: Redirect to the specific post ID using the anchor tag
        header("Location: forums.php#post-" . $postId);
        exit();
    }

    // Handle Delete Comment
    elseif ($action === 'delete_comment' && isset($_POST['comment_id'])) {
        $commentId = intval($_POST['comment_id']);
        $response = ['success' => false, 'message' => ''];

        if ($commentId > 0) {
            $stmt = $conn->prepare("DELETE FROM general_forums WHERE id = ? AND user_id = ? AND chat_type = 'comment'");
            $stmt->bind_param("ii", $commentId, $userId);
            $stmt->execute();
            
            if ($stmt->affected_rows === 1) {
                $response['success'] = true;
                $response['message'] = 'Comment deleted.';
            } else {
                $response['message'] = 'Comment not found or you are not the author.';
            }
            $stmt->close();
        }
        
        header('Content-Type: application/json');
        echo json_encode($response);
        exit();
    }
    
    // --- FIXED REPORT BLOCK ---
    // Handle Report Post (using action 'report_post' from the HTML form)
    elseif ($action === 'report_post' && isset($_POST['post_id'], $_POST['reported_user_id'], $_POST['reason'], $_POST['report_type'])) {
        
        // Check if banned FIRST, before processing
        if ($isBanned) {
            header("Location: forums.php");
            exit();
        }
        
        $postId = intval($_POST['post_id']);
        $reportedUserId = intval($_POST['reported_user_id']); 
        $reportType = trim($_POST['report_type']);             
        $reason = filterProfanity(trim($_POST['reason']));
        
        $reporterUserId = $userId; // Current user's ID
        $commentId = 0; // Set to 0 since we're reporting a post
        
        if ($reportedUserId > 0 && $postId > 0 && !empty($reason) && !empty($reportType)) {
            // FIXED SQL: Correct columns and 6 placeholders
            $stmt = $conn->prepare("INSERT INTO reports (reported_user_id, reporter_user_id, report_type, post_id, comment_id, report_message) VALUES (?, ?, ?, ?, ?, ?)");
            
            // FIXED BIND_PARAM: "iisiss" matches 6 variables (int, int, string, int, int, string)
            $stmt->bind_param("iisiss", $reportedUserId, $reporterUserId, $reportType, $postId, $commentId, $reason);

            if ($stmt->execute()) {
                echo "<script>alert('Report submitted successfully!'); window.location.href='forums.php#post-" . $postId . "';</script>";
            } else {
                // Log the actual error for debugging, but show a safe message to the user
                error_log("Error saving report: " . $stmt->error);
                echo "<script>alert('Error saving report. Please try again.'); window.location.href='forums.php';</script>";
            }
            $stmt->close();
        } else {
            echo "<script>alert('Missing required information for report.'); window.location.href='forums.php';</script>";
        }
        exit();
    }


    // Handle Delete Post
    elseif ($action === 'delete_post' && isset($_POST['post_id'])) {
        $postId = intval($_POST['post_id']);
        if ($postId > 0) {
            $conn->begin_transaction();

            try {
                $comments_stmt = $conn->prepare("DELETE FROM general_forums WHERE forum_id = ? AND chat_type = 'comment'");
                $comments_stmt->bind_param("i", $postId);
                $comments_stmt->execute();
                $comments_stmt->close();

                $post_stmt = $conn->prepare("DELETE FROM general_forums WHERE id = ? AND user_id = ?");
                $post_stmt->bind_param("ii", $postId, $userId);
                $post_stmt->execute();
                $post_stmt->close();

                $conn->commit();

            } catch (mysqli_sql_exception $exception) {
                $conn->rollback();
            }
        }
        header("Location: forums.php");
        exit();
    }
}

// --- DATA FETCHING ---
$posts = [];
$postQuery = "SELECT c.*, 
              (SELECT COUNT(*) FROM post_likes WHERE post_id = c.id) as likes,
              (SELECT COUNT(*) FROM post_likes WHERE post_id = c.id AND user_id = ?) as has_liked
              FROM general_forums c
              WHERE c.chat_type = 'forum'
              ORDER BY c.timestamp DESC";
$postsStmt = $conn->prepare($postQuery);

if ($postsStmt === false) {
    die('SQL preparation failed: ' . htmlspecialchars($conn->error));
}

$postsStmt->bind_param("i", $userId);
$postsStmt->execute();
$postsResult = $postsStmt->get_result();

if ($postsResult && $postsResult->num_rows > 0) {
    while ($row = $postsResult->fetch_assoc()) {
        $comments = [];
        $commentsStmt = $conn->prepare("SELECT * FROM general_forums WHERE chat_type = 'comment' AND forum_id = ? ORDER BY timestamp ASC");
        $commentsStmt->bind_param("i", $row['id']);
        $commentsStmt->execute();
        $commentsResult = $commentsStmt->get_result();
        
        if ($commentsResult && $commentsResult->num_rows > 0) {
            while ($commentRow = $commentsResult->fetch_assoc()) {
                $comments[] = $commentRow;
            }
        }
        $commentsStmt->close();
        $row['comments'] = $comments;
        $posts[] = $row;
    }
}
$postsStmt->close();

// Fetch user details for navbar
$navFirstName = '';
$navUserIcon = '';
$isMentee = ($_SESSION['user_type'] === 'Mentee');
if ($isMentee) {
    $sql = "SELECT first_name, icon FROM users WHERE username = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $navFirstName = $row['first_name'];
        $navUserIcon = $row['icon'];
    }
    $stmt->close();
}

$baseUrl = "http://localhost/coachlocal";

// Format ban datetime for JavaScript (ISO 8601 format)
$banDatetimeJS = null;
if ($ban_details && $ban_details['ban_until'] && $ban_details['ban_until'] !== '') {
    $banDatetimeJS = (new DateTime($ban_details['ban_until'], new DateTimeZone('Asia/Manila')))->format('Y-m-d\TH:i:s');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forums</title>
    <link rel="icon" href="../uploads/img/coachicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="css/navbar.css" />
    <link rel="stylesheet" href="css/forum.css">
    <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
    <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />

    <style>
        .banned-message {
            text-align: center;
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
            padding: 30px;
            border-radius: 12px;
            margin: 20px auto;
            max-width: 600px;
            border: 2px solid #721c24;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        
        .banned-message h2 {
            color: #721c24;
            margin-bottom: 15px;
            font-size: 24px;
        }
        
        .banned-message p {
            color: #721c24;
            margin: 10px 0;
            font-size: 16px;
        }
        
        .banned-message .ban-reason {
            background: white;
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
            font-style: italic;
        }
        
        .banned-message .ban-duration {
            font-weight: bold;
            color: #c82333;
            font-size: 18px;
            margin-top: 15px;
        }
        
        .banned-message .ban-status {
            background: #ffe0e6;
            padding: 12px;
            border-radius: 6px;
            margin: 10px 0;
            color: #721c24;
        }
        
        .ban-countdown {
            font-size: 20px;
            font-weight: bold;
            color: #c82333;
            padding: 15px;
            background: white;
            border-radius: 8px;
            margin: 15px 0;
            font-family: monospace;
            border: 2px solid #c82333;
        }
        
        .permanent-ban-label {
            display: inline-block;
            background: #721c24;
            color: white;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: bold;
            margin: 10px 0;
        }
        
        .banned-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.3);
            z-index: 999;
            display: none;
        }
        
        .banned-overlay.show {
            display: block;
        }
    </style>
</head>

<body>
    <section class="background" id="home">
        <nav class="navbar">
          <div class="logo">
            <img src="../uploads/img/LogoCoach.png" alt="Logo">
            <span>COACH</span>
          </div>
          <div class="nav-center">
            <ul class="nav_items" id="nav_links">
                <li><a href="home.php">Home</a></li>
                <li><a href="course.php">Courses</a></li>
                <li><a href="resource_library.php">Resource Library</a></li>
                <li><a href="activities.php">Activities</a></li>
                <li><a href="forum-chat.php">Sessions</a></li>
                <li><a href="forums.php">Forums</a></li>
            </ul>
          </div>
          <div class="nav-profile">
            <a href="#" id="profile-icon">
              <?php if (!empty($navUserIcon)): ?>
                <img src="<?php echo htmlspecialchars($navUserIcon); ?>" alt="User Icon" style="width: 35px; height: 35px; border-radius: 50%;">
              <?php else: ?>
                <ion-icon name="person-circle-outline" style="font-size: 35px;"></ion-icon>
              <?php endif; ?>
            </a>
          </div>
          <div class="sub-menu-wrap hide" id="profile-menu">
            <div class="sub-menu">
              <div class="user-info">
                <div class="user-icon">
                  <?php if (!empty($navUserIcon)): ?>
                    <img src="<?php echo htmlspecialchars($navUserIcon); ?>" alt="User Icon" style="width: 40px; height: 40px; border-radius: 50%;">
                  <?php else: ?>
                    <ion-icon name="person-circle-outline" style="font-size: 40px;"></ion-icon>
                  <?php endif; ?>
                </div>
                <div class="user-name"><?php echo htmlspecialchars($navFirstName); ?></div>
              </div>
              <ul class="sub-menu-items">
                <li><a href="profile.php">Profile</a></li>
                <li><a href="taskprogress.php">Progress</a></li>
                <li><a href="#" onclick="confirmLogout(event)">Logout</a></li>
              </ul>
            </div>
          </div>
        </nav>
    </section>

    <?php if ($isBanned): ?>
        <div class="banned-overlay show"></div>
    <?php endif; ?>

    <div class="forum-layout">
        <div class="sidebar-left">
            <div class="sidebar-box user-stats-box">
                <h3>My Activity</h3>
                <ul>
                    <?php
                    $post_count = 0;
                    $likes_received = 0;

                    if ($userId) { 
                        $sql_posts = "SELECT COUNT(id) AS total_posts FROM general_forums WHERE user_id = ?";
                        $stmt_posts = $conn->prepare($sql_posts);
                        $stmt_posts->bind_param("i", $userId); 
                        $stmt_posts->execute();
                        $result_posts = $stmt_posts->get_result();
                        
                        if ($row_posts = $result_posts->fetch_assoc()) {
                            $post_count = $row_posts['total_posts'];
                        }
                        $stmt_posts->close();

                        $sql_likes = "SELECT COALESCE(SUM(post_likes.like_count), 0) AS total_likes FROM (SELECT gf.id, COUNT(pl.like_id) AS like_count FROM general_forums gf INNER JOIN post_likes pl ON gf.id = pl.post_id WHERE gf.user_id = ? GROUP BY gf.id) AS post_likes";
                        $stmt_likes = $conn->prepare($sql_likes);
                        $stmt_likes->bind_param("i", $userId);
                        $stmt_likes->execute();
                        $result_likes = $stmt_likes->get_result();
                        
                        if ($row_likes = $result_likes->fetch_assoc()) {
                            $likes_received = $row_likes['total_likes'];
                        }
                        $stmt_likes->close();
                    }
                    
                    $avatarHtml = '';
                    $avatarSize = '75px';
                    if (!empty($userIcon) && $userIcon !== 'img/default-user.png') {
                        $avatarHtml = '<img src="' . htmlspecialchars($userIcon) . '" alt="' . htmlspecialchars($displayName) . ' Icon" class="user-icon-summary">';
                    } else {
                        $initials = '';
                        $nameParts = explode(' ', $displayName);
                        foreach ($nameParts as $part) {
                            if (!empty($part)) {
                                $initials .= strtoupper(substr($part, 0, 1));
                            }
                            if (strlen($initials) >= 2) break;
                        }
                        if (empty($initials)) {
                            $initials = '?';
                        }
                        $avatarHtml = '<div class="user-icon-summary" style="width: ' . $avatarSize . '; height: ' . $avatarSize . '; border-radius: 50%; background: #6a2c70; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: bold; margin: 0 auto 10px auto;">' . htmlspecialchars($initials) . '</div>';
                    }
                    ?>
                    <div class="user-profile-summary">
                        <?php echo $avatarHtml; ?>
                        <p class="user-name-summary"><?php echo htmlspecialchars($displayName); ?></p>
                    </div>
                    <li class="stat-item">
                        <span class="stat-label">Posts:</span>
                        <span class="stat-value"><?php echo $post_count; ?></span>
                    </li>
                    <li class="stat-item">
                        <span class="stat-label">Likes Received:</span>
                        <span class="stat-value"><?php echo $likes_received; ?></span>
                    </li>
                </ul>
            </div>
            <div class="sidebar-box">
                <h3>Pinned</h3>
                <ul>
                    <li><a href="#" onclick="openModal('rulesModal')">📌 Forum Rules</a></li>
                    <li><a href="#" onclick="openModal('welcomeModal')">📌 Welcome Post</a></li>
                </ul>
            </div>
            <h3>❤️ Recent Likes</h3>
            <ul>
                <?php
                $sql = "SELECT u.first_name, u.last_name, u.icon, gf.title 
                        FROM post_likes pl 
                        INNER JOIN general_forums gf ON pl.post_id = gf.id 
                        INNER JOIN users u ON pl.user_id = u.user_id 
                        WHERE gf.user_id = {$userId} 
                        ORDER BY pl.like_id DESC 
                        LIMIT 4";
                $result = $conn->query($sql);
                
                if ($result && $result->num_rows > 0) {
                    while ($row = $result->fetch_assoc()) {
                        $likerName = htmlspecialchars($row['first_name']);
                        $postTitle = htmlspecialchars($row['title']);
                        $likerIconPath = $row['icon'] ?? '';
                        $firstName = $row['first_name'] ?? '';
                        $lastName = $row['last_name'] ?? '';
                        $avatarSize = '25px';
                        $avatarMargin = '4px';
                        $likerAvatar = '';
                        
                        if (!empty($likerIconPath)) {
                            $likerAvatar = '<img src="' . htmlspecialchars($likerIconPath) . '" alt="Liker Icon" style="width:' . $avatarSize . '; height:' . $avatarSize . '; border-radius:50%; margin-right: ' . $avatarMargin . ';">';
                        } else {
                            $initials = '';
                            if (!empty($firstName)) $initials .= strtoupper(substr($firstName, 0, 1));
                            if (!empty($lastName)) $initials .= strtoupper(substr($lastName, 0, 1));
                            $initials = substr($initials, 0, 2);
                            if (empty($initials)) $initials = '?';
                            $likerAvatar = '<div style="width:' . $avatarSize . '; height:' . $avatarSize . '; border-radius:50%; background:#6a2c70; color:#fff; display:inline-flex; align-items:center; justify-content:center; font-size:10px; font-weight:bold; margin-right: ' . $avatarMargin . ';">' . htmlspecialchars($initials) . '</div>';
                        }
                        
                        if (strlen($postTitle) > 30) {
                            $postTitle = substr($postTitle, 0, 30) . '...';
                        }
                        
                        echo '<li style="display: flex; align-items: center;">' . $likerAvatar . '<div style="flex: 1; min-width: 0; line-height: 1.3; font-size: 14px;"><strong>' . $likerName . '</strong> liked your post: <em>' . $postTitle . '</em></div></li>';
                    }
                } else {
                    echo "<li>No recent likes yet.</li>";
                }
                ?>
            </ul>
        </div>

        <div class="chat-container">
            <?php if ($isBanned): ?>
                <div class="banned-message">
                    <h2>⛔ You have been banned</h2>
                    <?php if ($ban_details['ban_type'] === 'Permanent'): ?>
                        <span class="permanent-ban-label">PERMANENT BAN</span>
                    <?php endif; ?>
                    <div class="ban-reason">
                        <strong>Reason:</strong> <?php echo htmlspecialchars($ban_details['reason']); ?>
                    </div>
                    <div class="ban-status">
                        <strong>Ban Type:</strong> <?php echo htmlspecialchars($ban_details['ban_type']); ?>
                    </div>
                    <?php if ($ban_details['ban_type'] === 'Temporary' && $ban_details['ban_until'] && $ban_details['ban_until'] !== ''): ?>
                        <p style="margin-top: 15px; color: #721c24; font-size: 14px;">
                            <strong>Ban Duration:</strong> <?php echo htmlspecialchars($ban_details['ban_duration_text']); ?>
                        </p>
                        <p style="margin-top: 10px; color: #721c24; font-size: 14px;">
                            <strong>Unban Date:</strong> <?php echo date("F j, Y, g:i a", strtotime($ban_details['ban_until'])); ?>
                        </p>
                        <div class="ban-countdown">
                            <span>Time remaining:</span><br>
                            <span id="countdown-timer">Loading...</span>
                        </div>
                        <script>
                            function updateCountdown() {
                                const unbanTime = new Date('<?php echo $banDatetimeJS; ?>').getTime();
                                const currentTime = new Date().getTime();
                                const timeRemaining = unbanTime - currentTime;
                                
                                if (timeRemaining <= 0) {
                                    document.getElementById('countdown-timer').textContent = 'Ban has expired. Please refresh the page.';
                                    document.getElementById('countdown-timer').style.color = '#28a745';
                                    return;
                                }

                                const days = Math.floor(timeRemaining / (1000 * 60 * 60 * 24));
                                const hours = Math.floor((timeRemaining % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                                const minutes = Math.floor((timeRemaining % (1000 * 60 * 60)) / (1000 * 60));
                                const seconds = Math.floor((timeRemaining % (1000 * 60)) / 1000);

                                document.getElementById('countdown-timer').textContent = 
                                    (days > 0 ? days + "d " : "") + 
                                    (hours < 10 ? "0" + hours : hours) + "h " + 
                                    (minutes < 10 ? "0" + minutes : minutes) + "m " + 
                                    (seconds < 10 ? "0" + seconds : seconds) + "s";
                            }
                            
                            updateCountdown();
                            setInterval(updateCountdown, 1000);
                        </script>
                    <?php else: ?>
                        <div class="ban-status" style="margin-top: 20px;">
                            This is a permanent ban.
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="post-form-container">
                    <form action="forums.php" method="POST" enctype="multipart/form-data" class="post-form">
                        <input type="hidden" name="action" value="create_post">
                        <input type="text" name="post_title" placeholder="Title your post..." required>
                        <textarea name="post_content" placeholder="Share your thoughts or ask a question..." rows="3" required></textarea>
                        <div class="form-footer">
                            <input type="file" name="post_image" accept="image/*" id="post-image-upload" style="display: none;">
                            <label for="post-image-upload" class="upload-label">
                                <ion-icon name="image-outline"></ion-icon> Add Image
                            </label>
                            <button type="submit" class="post-btn">Post</button>
                        </div>
                    </form>
                </div>

                <?php if (empty($posts)): ?>
                    <div class="no-posts">Be the first to post in the forum!</div>
                <?php else: ?>
                    <?php foreach ($posts as $post): ?>
                        <div class="post-container" id="post-<?php echo $post['id']; ?>">
                            <div class="post-header">
                                <img src="<?php echo htmlspecialchars($post['user_icon'] ?? 'img/default-user.png'); ?>" alt="User Icon" class="post-user-icon">
                                <div>
                                    <span class="post-display-name"><?php echo htmlspecialchars($post['display_name']); ?></span>
                                    <span class="post-timestamp"><?php echo (new DateTime($post['timestamp']))->format('F j, Y \a\t g:i a'); ?></span>
                                </div>
                            </div>
                            <div class="post-content">
                                <h3><?php echo htmlspecialchars($post['title']); ?></h3>
                                <p><?php echo makeLinksClickable(nl2br(htmlspecialchars($post['message']))); ?></p>
                                <?php if (!empty($post['file_path'])): ?>
                                    <img src="<?php echo htmlspecialchars($post['file_path']); ?>" alt="Post Image" class="post-image">
                                <?php endif; ?>
                            </div>
                            <div class="post-actions">
                                <form action="forums.php" method="POST" class="like-form" data-post-id="<?php echo $post['id']; ?>">
                                    <input type="hidden" name="action" value="<?php echo $post['has_liked'] ? 'unlike_post' : 'like_post'; ?>">
                                    <input type="hidden" name="post_id" value="<?php echo $post['id']; ?>">
                                    <button type="submit" class="like-btn <?php echo $post['has_liked'] ? 'liked' : ''; ?>">
                                        <ion-icon name="<?php echo $post['has_liked'] ? 'heart' : 'heart-outline'; ?>"></ion-icon>
                                        <span class="like-count"><?php echo $post['likes']; ?></span>
                                    </button>
                                </form>
                                <button class="comment-btn" onclick="toggleCommentForm(this)">
                                    <ion-icon name="chatbox-outline"></ion-icon> <?php echo count($post['comments']); ?> Comments
                                </button>
                                <button class="report-btn" onclick="openReportModal(<?php echo $post['id']; ?>, <?php echo $post['user_id']; ?>)">
                                    <ion-icon name="flag-outline"></ion-icon> Report
                                </button>
                                <?php if ($post['user_id'] == $userId): ?>
                                    <button class="delete-btn" onclick="showDeletePostDialog(<?php echo $post['id']; ?>)">
                                        <ion-icon name="trash-outline"></ion-icon> Delete
                                    </button>
                                <?php endif; ?>
                            </div>

                            <form action="forums.php" method="POST" class="join-convo-form" style="display: none;">
                                <input type="hidden" name="action" value="create_comment">
                                <input type="hidden" name="post_id" value="<?php echo $post['id']; ?>">
                                <textarea name="comment_message" placeholder="Write your comment..." rows="1" required></textarea>
                                <button type="submit" class="comment-submit-btn">Comment</button>
                            </form>

                            <div class="comments-section">
                                <?php foreach ($post['comments'] as $comment): ?>
                                    <div class="comment-item">
                                        <img src="<?php echo htmlspecialchars($comment['user_icon'] ?? 'img/default-user.png'); ?>" alt="User Icon" class="comment-user-icon">
                                        <div class="comment-content-wrapper">
                                            <span class="comment-display-name"><?php echo htmlspecialchars($comment['display_name']); ?></span>
                                            <span class="comment-timestamp"><?php echo (new DateTime($comment['timestamp']))->format('M j, g:i a'); ?></span>
                                            <p class="comment-message"><?php echo makeLinksClickable(nl2br(htmlspecialchars($comment['message']))); ?></p>
                                        </div>
                                        <?php if ($comment['user_id'] == $userId): ?>
                                            <button class="delete-comment-btn" onclick="showDeleteCommentDialog(<?php echo $comment['id']; ?>)">
                                                <ion-icon name="trash-outline"></ion-icon>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="sidebar-right">
            <div class="sidebar-box">
                <h3>Forum Stats</h3>
                <p>Total Posts: <?php echo count($posts); ?></p>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="report-modal-overlay" style="display:none;">
        <div class="modal">
            <div class="modal-header">
                <h2>Report Content</h2>
                <button class="close-btn" onclick="closeReportModal()">&times;</button>
            </div>
            <form id="report-form-confirm" action="forums.php" method="POST">
                <input type="hidden" name="action" value="report_post">
                
                <input type="hidden" id="report-confirm-post-id" name="post_id" value="">
                
                <input type="hidden" id="report-confirm-reported-user-id" name="reported_user_id" value="">
                
                <label for="report-confirm-type">Report Type:</label>
                <select id="report-confirm-type" name="report_type" required style="width: 100%; margin-bottom: 1rem; padding: 10px; border: 1px solid #ccc; border-radius: 4px;">
                    <option value="">-- Select Report Type --</option>
                    <option value="Spam">Spam</option>
                    <option value="Profanity">Profanity / Hate Speech</option>
                    <option value="Inappropriate">Inappropriate Content</option>
                    <option value="Other">Other</option>
                </select>
                
                <p>Please provide a detailed reason:</p>
                <textarea name="reason" rows="4" required style="width: 100%; margin-bottom: 1rem; padding: 10px; border: 1px solid #ccc; border-radius: 4px;"></textarea>
                
                <div class="dialog-buttons">
                    <button id="cancelReport" type="button" onclick="closeReportModal()">Cancel</button>
                    <button type="submit" class="post-btn" style="background: linear-gradient(to right, #5d2c69, #6a2c70);">Submit Report</button>
                </div>
            </form>
        </div>
    </div>
    
    <div id="deletePostDialog" class="logout-dialog" style="display: none;">
        <div class="logout-content">
            <h3>Confirm Post Deletion</h3>
            <p>Are you sure you want to delete this post and all its comments?</p>
            <div class="dialog-buttons">
                <button id="cancelDeletePost" type="button" onclick="closeModal('deletePostDialog')">Cancel</button>
                <form action="forums.php" method="POST" style="display: inline;">
                    <input type="hidden" name="action" value="delete_post">
                    <input type="hidden" name="post_id" id="post-to-delete-id" value="">
                    <button type="submit" style="background-color: #c82333; color: white;">Delete</button>
                </form>
            </div>
        </div>
    </div>
    
    <div id="deleteCommentDialog" class="logout-dialog" style="display: none;">
        <div class="logout-content">
            <h3>Confirm Comment Deletion</h3>
            <p>Are you sure you want to delete this comment?</p>
            <div class="dialog-buttons">
                <button id="cancelDeleteComment" type="button" onclick="closeModal('deleteCommentDialog')">Cancel</button>
                <form action="forums.php" method="POST" style="display: inline;" id="delete-comment-form">
                    <input type="hidden" name="action" value="delete_comment">
                    <input type="hidden" name="comment_id" id="comment-to-delete-id" value="">
                    <button type="submit" style="background-color: #5d2c69; color: white;">Delete</button>
                </form>
            </div>
        </div>
    </div>

    <div id="rulesModal" class="logout-dialog" style="display: none;">
        <div class="logout-content">
            <h3>Forum Rules</h3>
            <p>1. Be respectful to everyone.</p>
            <p>2. No profanity or hate speech.</p>
            <p>3. Stay on topic (self-improvement).</p>
            <button onclick="closeModal('rulesModal')">Close</button>
        </div>
    </div>

    <div id="welcomeModal" class="logout-dialog" style="display: none;">
        <div class="logout-content">
            <h3>Welcome to the Forums!</h3>
            <p>This is a safe space for mentees to ask questions and share experiences.</p>
            <button onclick="closeModal('welcomeModal')">Close</button>
        </div>
    </div>
    
    <div id="logoutDialog" class="logout-dialog" style="display: none;">
        <div class="logout-content">
            <h3>Confirm Logout</h3>
            <p>Are you sure you want to log out?</p>
            <div class="dialog-buttons">
                <button id="cancelLogout" type="button" onclick="closeModal('logoutDialog')">Cancel</button>
                <a href="../logout.php" class="logout-btn">Logout</a>
            </div>
        </div>
    </div>


    <script>
        // --- General Modal Handlers ---
        function openModal(id) {
            document.getElementById(id).style.display = 'flex';
        }

        function closeModal(id) {
            document.getElementById(id).style.display = 'none';
        }

        function confirmLogout(event) {
            event.preventDefault();
            openModal('logoutDialog');
        }

        // --- Post/Comment Handlers ---
        function toggleCommentForm(btn) {
            const form = btn.closest('.post-container').querySelector('.join-convo-form');
            form.style.display = form.style.display === 'none' ? 'flex' : 'none';
        }

        function showDeletePostDialog(postId) {
            document.getElementById('post-to-delete-id').value = postId;
            openModal('deletePostDialog');
        }

        function showDeleteCommentDialog(commentId) {
            document.getElementById('comment-to-delete-id').value = commentId;
            openModal('deleteCommentDialog');
        }

        // --- FIXED REPORT HANDLERS ---
        // NEW: Function to open the report modal and populate IDs
        function openReportModal(postId, reportedUserId) {
            document.getElementById('report-confirm-post-id').value = postId;
            document.getElementById('report-confirm-reported-user-id').value = reportedUserId;
            openModal('report-modal-overlay');
        }

        // NEW: Function to close the report modal
        function closeReportModal() {
            document.getElementById('report-form-confirm').reset();
            closeModal('report-modal-overlay');
        }

        // --- AJAX Like/Unlike Handler ---
        document.querySelectorAll('.like-form').forEach(form => {
            form.addEventListener('submit', function(e) {
                e.preventDefault();

                const postId = this.querySelector('input[name="post_id"]').value;
                const actionInput = this.querySelector('input[name="action"]');
                const btn = this.querySelector('.like-btn');
                const icon = btn.querySelector('ion-icon');
                const countSpan = btn.querySelector('.like-count');
                let currentCount = parseInt(countSpan.textContent);

                fetch('forums.php', {
                    method: 'POST',
                    body: new FormData(this)
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        if (data.action === 'liked') {
                            btn.classList.add('liked');
                            icon.setAttribute('name', 'heart');
                            countSpan.textContent = currentCount + 1;
                            actionInput.value = 'unlike_post';
                        } else if (data.action === 'unliked') {
                            btn.classList.remove('liked');
                            icon.setAttribute('name', 'heart-outline');
                            countSpan.textContent = Math.max(0, currentCount - 1);
                            actionInput.value = 'like_post';
                        }
                    }
                })
                .catch(error => console.error('Error:', error));
            });
        });
    </script>
</body>
</html>