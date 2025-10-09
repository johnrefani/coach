<?php
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

// Check if user has an active ban
$ban_check_stmt = $conn->prepare("SELECT ban_id, reason, unban_datetime, ban_duration_text FROM banned_users WHERE username = ?");
$ban_check_stmt->bind_param("s", $username);
$ban_check_stmt->execute();
$ban_result = $ban_check_stmt->get_result();

if ($ban_result->num_rows > 0) {
    $ban_details = $ban_result->fetch_assoc();
    
    // Check if ban has expired
    if ($ban_details['unban_datetime'] !== null) {
        $unbanTime = strtotime($ban_details['unban_datetime']);
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
        }
    } else {
        // Permanent ban
        $isBanned = true;
    }
}
$ban_check_stmt->close();

// --- FETCH USER'S BAN HISTORY COUNT (UPDATED) ---
$ban_count_stmt = $conn->prepare("SELECT COUNT(*) AS total_bans FROM ban_history WHERE user_username = ?");
$ban_count_stmt->bind_param("s", $username);
$ban_count_stmt->execute();
$ban_count_result = $ban_count_stmt->get_result();
$total_bans = 0;
if ($row_bans = $ban_count_result->fetch_assoc()) {
    $total_bans = $row_bans['total_bans'];
}
$ban_count_stmt->close();

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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isBanned) {
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
            $stmt = $conn->prepare("INSERT INTO general_forums (user_id, display_name, message, is_admin, is_mentor, chat_type, title, file_path, file_name, user_icon) VALUES (?, ?, ?, ?, ?, 'forum', ?, ?, ?, ?)");
            
            $isAdmin = 0;
            $isMentor = 0;

            $stmt->bind_param("issiissss", $userId, $displayName, $postContent, $isAdmin, $isMentor, $postTitle, $filePath, $fileName, $userIcon);
            $stmt->execute();
        }
        header("Location: forums.php");
        exit();
    }

    // Handle New Comment
    elseif ($action === 'create_comment' && isset($_POST['comment_message'], $_POST['post_id'])) {
        $commentMessage = filterProfanity(trim($_POST['comment_message']));
        $postId = intval($_POST['post_id']);
        if (!empty($commentMessage) && $postId > 0) {
            $stmt = $conn->prepare("INSERT INTO general_forums (user_id, display_name, title, message, is_admin, is_mentor, chat_type, forum_id, user_icon) VALUES (?, ?, 'User commented', ?, ?, ?, 'comment', ?, ?)");
            $isAdmin = 0;
            $isMentor = 0;
            $stmt->bind_param("issiiis", $userId, $displayName, $commentMessage, $isAdmin, $isMentor, $postId, $userIcon);
            $stmt->execute();
        }
        header("Location: forums.php");
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
    
    // Handle Report
    elseif ($action === 'report_post' && isset($_POST['post_id'], $_POST['reason'])) {
        $postId = intval($_POST['post_id']);
        $reason = trim($_POST['reason']);
        if ($postId > 0 && !empty($reason)) {
            $stmt = $conn->prepare("INSERT INTO reports (post_id, reported_by_username, reason) VALUES (?, ?, ?)");
            $stmt->bind_param("iss", $postId, $username, $reason);
            $stmt->execute();
        }
        header("Location: forums.php");
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
    $username = $_SESSION['username'];
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

// --- FUNCTION TO RENDER TOP CONTRIBUTORS ---
function render_top_contributors($conn, $baseUrl) {
    ob_start();

    $sql = "SELECT gf.user_id, gf.display_name, COUNT(gf.id) AS post_count, u.icon
            FROM general_forums gf
            LEFT JOIN users u ON gf.user_id = u.user_id
            GROUP BY gf.user_id, gf.display_name, u.icon
            ORDER BY post_count DESC
            LIMIT 3";
    $result = $conn->query($sql);

    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $avatar = '';

            if (!empty($row['icon'])) {
                $cleanedPath = str_replace("../", "", $row['icon']);
                $cleanedPath = ltrim($cleanedPath, '/');
                $fullIconUrl = $baseUrl . '/' . $cleanedPath;

                $avatar = '<img src="' . htmlspecialchars($fullIconUrl) . '" 
                           alt="User" width="35" height="35" style="border-radius:50%; object-fit: cover;">';
            } else {
                $initials = '';
                $nameParts = explode(' ', $row['display_name']);
                foreach ($nameParts as $part) {
                    $initials .= strtoupper(substr($part, 0, 1));
                }
                $initials = substr($initials, 0, 2);

                $avatar = '<div style="width:35px; height:35px; border-radius:50%; 
                                        background:#6f42c1; color:#fff; display:flex; 
                                        align-items:center; justify-content:center; 
                                        font-size:13px; font-weight:bold;">'
                                        . htmlspecialchars($initials) . 
                            '</div>';
            }
            ?>
                <div style="display:flex; align-items:center; gap:8px; margin-bottom:8px;">
                    <?php echo $avatar; ?>
                    <span><?php echo htmlspecialchars($row['display_name']); ?> 
                    (<?php echo $row['post_count']; ?>)</span>
                </div>
            <?php
        }
    } else {
        echo "<p>No contributors yet.</p>";
    }

    return ob_get_clean();
}

// --- AJAX HANDLER 1: LIKES RECEIVED ---
if (isset($_GET['action']) && $_GET['action'] === 'get_likes' && $userId) {
    $sql_likes = "
        SELECT COALESCE(SUM(post_likes.like_count), 0) AS total_likes 
        FROM (
            SELECT gf.id, COUNT(pl.like_id) AS like_count
            FROM general_forums gf
            INNER JOIN post_likes pl ON gf.id = pl.post_id
            WHERE gf.user_id = ?
            GROUP BY gf.id
        ) AS post_likes
    ";
    
    $stmt_likes = $conn->prepare($sql_likes);
    $stmt_likes->bind_param("i", $userId);
    $stmt_likes->execute();
    $result_likes = $stmt_likes->get_result();
    $row_likes = $result_likes->fetch_assoc();
    
    header('Content-Type: application/json');
    echo json_encode(['total_likes' => (int)$row_likes['total_likes']]);
    $stmt_likes->close();
    $conn->close();
    exit;
}

// --- AJAX HANDLER 2: TOP CONTRIBUTORS ---
if (isset($_GET['action']) && $_GET['action'] === 'get_contributors') {
    $contributorHtml = render_top_contributors($conn, $baseUrl);

    header('Content-Type: application/json');
    echo json_encode(['html' => $contributorHtml]);
    $conn->close();
    exit;
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
        require_once '../connection/db_connection.php'; 

        $post_count = 0;
        $likes_received = 0;

        if ($userId) { 
            $sql_posts = "
                SELECT COUNT(id) AS total_posts 
                FROM general_forums 
                WHERE user_id = ?
            ";
            $stmt_posts = $conn->prepare($sql_posts);
            $stmt_posts->bind_param("i", $userId); 
            $stmt_posts->execute();
            $result_posts = $stmt_posts->get_result();
            
            if ($row_posts = $result_posts->fetch_assoc()) {
                $post_count = $row_posts['total_posts'];
            }
            $stmt_posts->close();

            $sql_likes = "
                SELECT 
                    COALESCE(SUM(post_likes.like_count), 0) AS total_likes 
                FROM (
                    SELECT gf.id, COUNT(pl.like_id) AS like_count
                    FROM general_forums gf
                    INNER JOIN post_likes pl ON gf.id = pl.post_id  
                    WHERE gf.user_id = ?
                    GROUP BY gf.id
                ) AS post_likes
            ";
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
            
            $avatarHtml = '<div class="user-icon-summary" style="
                width: ' . $avatarSize . '; 
                height: ' . $avatarSize . '; 
                border-radius: 50%;
                background: #6a2c70;
                color: #fff; 
                display: flex; 
                align-items: center; 
                justify-content: center; 
                font-size: 20px; 
                font-weight: bold;
                margin: 0 auto 10px auto;
                ">'
                . htmlspecialchars($initials) . 
                '</div>';
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
        <li class="stat-item" style="color: #c82333;">
            <span class="stat-label">Ban Record:</span>
            <span class="stat-value"><?php echo $total_bans; ?></span>
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

    <h3>💖 Recent Likes</h3>
    <ul>
      <?php
      $sql = "
        SELECT 
            u.first_name, 
            u.last_name, 
            u.icon,
            gf.title 
        FROM 
            post_likes pl
        INNER JOIN 
            general_forums gf ON pl.post_id = gf.id
        INNER JOIN 
            users u ON pl.user_id = u.user_id
        WHERE 
            gf.user_id = {$userId} 
        ORDER BY 
            pl.like_id DESC  
        LIMIT 4
      ";

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
                  $likerAvatar = '<img src="' . htmlspecialchars($likerIconPath) . '" 
                                   alt="Liker Icon" 
                                   style="width:' . $avatarSize . '; height:' . $avatarSize . '; border-radius:50%; margin-right: ' . $avatarMargin . ';">';
              } else {
                  $initials = '';
                  if (!empty($firstName)) $initials .= strtoupper(substr($firstName, 0, 1));
                  if (!empty($lastName)) $initials .= strtoupper(substr($lastName, 0, 1));
                  $initials = substr($initials, 0, 2);
                  if (empty($initials)) $initials = '?';
              
                  $likerAvatar = '<div style="width:' . $avatarSize . '; height:' . $avatarSize . '; border-radius:50%; background:#6a2c70; color:#fff; display:inline-flex; align-items:center; justify-content:center; font-size:10px; font-weight:bold; margin-right: ' . $avatarMargin . ';">'
                                 . htmlspecialchars($initials) . 
                                 '</div>';
              }

              if (strlen($postTitle) > 30) {
                  $postTitle = substr($postTitle, 0, 30) . '...';
              }
              
              echo '<li style="display: flex; align-items: center;">'
                   . $likerAvatar 
                   . '<div style="flex: 1; min-width: 0; line-height: 1.3; font-size: 14px;">'
                   . '<strong>' . $likerName . '</strong> liked your post: <em>' . $postTitle . '</em>'
                   . '</div>'
                   . '</li>';
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
                <div class="ban-reason">
                    <strong>Reason:</strong> <?php echo htmlspecialchars($ban_details['reason']); ?>
                </div>
                <?php if ($ban_details['unban_datetime']): ?>
                    <p class="ban-duration">
                        Your ban will be lifted on:<br>
                        <?php echo date("F j, Y, g:i a", strtotime($ban_details['unban_datetime'])); ?>
                    </p>
                    <p class="ban-duration" style="margin-top: 5px;">
                        Remaining time: <strong id="countdown-timer">Calculating...</strong>
                    </p>
                    <p style="margin-top: 10px; color: #721c24;">
                        Ban Duration: <?php echo htmlspecialchars($ban_details['ban_duration_text']); ?>
                    </p>
                <?php else: ?>
                    <p class="ban-duration">This is a permanent ban.</p>
                <?php endif; ?>
                <p style="margin-top: 20px; font-size: 14px;">
                    If you believe this is a mistake, please contact an administrator.
                </p>
            </div>
        <?php endif; ?>

        <?php if (empty($posts)): ?>
            <p>No posts yet. Be the first to create one!</p>
        <?php else: ?>
            <?php foreach ($posts as $post): ?>
                <div class="post-container">
                    <div class="post-header">
                       <?php 
        $iconPath = $post['user_icon'] ?? ''; 
        $postDisplayName = $post['display_name'] ?? 'Guest';

        if (!empty($iconPath) && $iconPath !== 'img/default-user.png') {
            ?>
            <img src="<?php echo htmlspecialchars($iconPath); ?>" alt="<?php echo htmlspecialchars($postDisplayName); ?> Icon" class="user-avatar">
            <?php
        } else {
            $initials = '';
            $nameParts = explode(' ', $postDisplayName);
            
            foreach ($nameParts as $part) {
                if (!empty($part)) {
                     $initials .= strtoupper(substr($part, 0, 1));
                }
                if (strlen($initials) >= 2) break;
            }
            
            if (empty($initials)) {
                $initials = '?';
            }
            ?>
            <div class="user-avatar" style="
                width: 40px; 
                height: 40px; 
                border-radius: 50%;
                background: #6a2c70;
                color: #fff; 
                display: flex; 
                align-items: center; 
                justify-content: center; 
                font-size: 16px; 
                font-weight: bold;
                ">
                <?php echo htmlspecialchars($initials); ?>
            </div>
            <?php
        }
        ?>
                        <div class="header-content">
                            <div class="post-author-details">
                                <div class="post-author"><?php echo htmlspecialchars($post['display_name']); ?></div>
                                <div class="post-date"><?php echo date("F j, Y, g:i a", strtotime($post['timestamp'])); ?></div>
                            </div>
                            <?php if ($post['user_id'] == $userId): ?>
                                <div class="post-options">
                                    <button class="options-button" type="button" aria-label="Post options">
                                        <i class="fa-solid fa-ellipsis"></i>
                                    </button>
                                    <form class="delete-post-form" action="forums.php" method="POST">
                                        <input type="hidden" name="action" value="delete_post">
                                        <input type="hidden" name="post_id" value="<?php echo $post['id']; ?>">
                                        <button type="button" class="delete-post-button open-delete-post-dialog">Delete post</button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="post-title"><?php echo htmlspecialchars($post['title']); ?></div>
                    <div class="post-content">
                        <?php 
                            $formattedMessage = makeLinksClickable($post['message']);
                            echo $formattedMessage; 
                        ?>
                        <br>
                        <?php if (!empty($post['file_path'])): ?>
                            <img src="<?php echo htmlspecialchars($post['file_path']); ?>" alt="Post Image">
                        <?php endif; ?>
                    </div>
                    <div class="post-actions">
                        <button class="action-btn like-btn <?php echo $post['has_liked'] ? 'liked' : ''; ?>" 
                                data-post-id="<?php echo $post['id']; ?>" 
                                <?php if($isBanned) echo 'disabled'; ?>>
                            ❤️ <span class="like-count"><?php echo $post['likes']; ?></span>
                        </button>
                        <button class="action-btn" onclick="toggleCommentForm(this)" <?php if($isBanned) echo 'disabled'; ?>>💬 Comment</button>
                        <button class="report-btn" onclick="openReportModal(<?php echo $post['id']; ?>)" <?php if($isBanned) echo 'disabled'; ?>>
                             <i class="fa fa-flag"></i> Report 
                        </button>
                    </div>
                    <form class="join-convo-form" style="display:none;" action="forums.php" method="POST">
                        <input type="hidden" name="action" value="create_comment">
                        <input type="hidden" name="post_id" value="<?php echo $post['id']; ?>">
                        <input type="text" name="comment_message" placeholder="Join the conversation" required <?php if($isBanned) echo 'disabled'; ?>>
                        <button type="submit" <?php if($isBanned) echo 'disabled'; ?>>Post</button>
                    </form>
                    <div class="comment-section">
                        <?php 
                        $current_user_id = $userId;
                        $commentAvatarSize = '30px';
                        $commentFontSize = '14px';
                        foreach ($post['comments'] as $comment): ?>
                            <div class="comment" data-comment-id="<?php echo $comment['id']; ?>">
                                <?php 
                                $commentAvatarHtml = '';
                                $commenterIcon = $comment['user_icon'];
                                $commenterName = $comment['display_name'];
                                
                                if (!empty($commenterIcon) && $commenterIcon !== 'img/default-user.png') {
                                    $commentAvatarHtml = '<img src="' . htmlspecialchars($commenterIcon) . '" alt="' . htmlspecialchars($commenterName) . ' Icon" class="user-avatar" style="width: ' . $commentAvatarSize . '; height: ' . $commentAvatarSize . ';">';
                                } else {
                                    $initials = '';
                                    $nameParts = explode(' ', $commenterName);
                                    foreach ($nameParts as $part) {
                                        if (!empty($part)) {
                                             $initials .= strtoupper(substr($part, 0, 1));
                                        }
                                        if (strlen($initials) >= 2) break;
                                    }
                                    if (empty($initials)) {
                                        $initials = '?';
                                    }
                                    $commentAvatarHtml = '<div class="user-avatar" style="
                                        width: ' . $commentAvatarSize . '; 
                                        height: ' . $commentAvatarSize . '; 
                                        border-radius: 50%;
                                        background: #6a2c70;
                                        color: #fff; 
                                        display: flex; 
                                        align-items: center; 
                                        justify-content: center; 
                                        font-size: ' . $commentFontSize . '; 
                                        font-weight: bold;
                                        line-height: 1;
                                        ">';
                                    $commentAvatarHtml .= htmlspecialchars($initials);
                                    $commentAvatarHtml .= '</div>';
                                }
                                echo $commentAvatarHtml; 
                                ?>
                                <div class="comment-author-details">
                                    <div class="comment-bubble">
                                        <strong><?php echo htmlspecialchars($commenterName); ?></strong> 
                                        <?php echo htmlspecialchars($comment['message']); ?>
                                    </div>
                                    <div class="comment-timestamp">
                                        <?php echo date("F j, Y, g:i a", strtotime($comment['timestamp'])); ?>
                                        <?php if ($current_user_id && $current_user_id == $comment['user_id'] && !$isBanned): ?>
                                            <button class="delete-btn" onclick="deleteComment(<?php echo htmlspecialchars($comment['id']); ?>)" title="Delete Comment"> 🗑️ </button>
                                        <?php endif; ?>
                                        <?php if (!$isBanned): ?>
                                            <button class="report-btn" onclick="openReportModal(<?php echo htmlspecialchars($comment['id']); ?>)" title="Report Comment">
                                                <i class="fa fa-flag"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    
    <?php if (!$isBanned): ?>
        <button class="create-post-btn">+</button>
    <?php endif; ?>

    <div class="modal-overlay" id="create-post-modal-overlay">
        <div class="modal">
            <div class="modal-header">
                <h2>Create a post</h2>
                <button class="close-btn">&times;</button>
            </div>
            <form id="post-form" action="forums.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="create_post">
                <input type="text" name="post_title" placeholder="Title" class="title-input" required>
                <div class="content-editor">
                    <div class="toolbar">
                        <button type="button" class="btn" data-element="bold"><i class="fa fa-bold"></i></button>
                        <button type="button" class="btn" data-element="italic"><i class="fa fa-italic"></i></button>
                        <button type="button" class="btn" data-element="underline"><i class="fa fa-underline"></i></button>
                        <button type="button" class="btn" data-element="insertUnorderedList"><i class="fa fa-list-ul"></i></button>
                        <button type="button" class="btn" data-element="insertOrderedList"><i class="fa fa-list-ol"></i></button>
                        <button type="button" class="btn" data-element="link"><i class="fa fa-link"></i></button>
                    </div>
                    <div class="text-content" contenteditable="true"></div>
                </div>
                <input type="hidden" name="post_content" id="post-content-input">
                <div id="image-upload-container">
                    <label for="post_image" class="image-upload-area" id="initial-upload-box">
                        <span id="upload-text"><i class="fa fa-cloud-upload"></i> Upload an Image (optional)</span>
                    </label>
                    <input type="file" name="post_image" id="post_image" accept="image/*" style="display: none;">
                </div>
                <button type="submit" class="post-btn">Post</button>
            </form>
        </div>
    </div>
    
    <div class="modal-overlay" id="report-modal-overlay" style="display:none;">
        <div class="modal">
            <div class="modal-header">
                <h2>Report Content</h2>
                <button class="close-btn" onclick="closeReportModal()">&times;</button>
            </div>
            <form action="forums.php" method="POST" onsubmit="return confirm('Are you sure you want to report this content?');">
                <input type="hidden" name="action" value="report_post">
                <input type="hidden" id="report-post-id" name="post_id" value="">
                <p>Please provide a reason for reporting this content:</p>
                <textarea name="reason" rows="4" required style="width: 100%; margin-bottom: 1rem; padding: 10px; border: 1px solid #ccc; border-radius: 4px;"></textarea>
                <div class="dialog-buttons">
                    <button id="cancelReport" type="button">Cancel</button>
                    <button type="submit" class="post-btn" style="background: linear-gradient(to right, #5d2c69, #6a2c70);">Submit Report</button>
                </div>
            </form>
        </div>
    </div>

    <div id="deleteCommentDialog" class="logout-dialog" style="display: none;">
        <div class="logout-content">
            <h3>Confirm Comment Deletion</h3>
            <p>Are you sure you want to delete this comment?</p>
            <div class="dialog-buttons">
                <button id="cancelDeleteComment" type="button">Cancel</button>
                <button id="confirmDeleteCommentBtn" type="button" style="background-color: #5d2c69; color: white;">Delete</button>
            </div>
        </div>
        <input type="hidden" id="comment-to-delete-id" value="">
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
</section>

<script src="js/post_interactions.js"></script>
<script src="js/logout_dialog.js"></script>

<script>
// Toggle sub-menu
document.getElementById('profile-icon').addEventListener('click', function(e) {
    e.preventDefault();
    document.getElementById('profile-menu').classList.toggle('hide');
});

document.addEventListener('click', function(e) {
    if (!e.target.closest('.nav-profile') && !e.target.closest('#profile-menu')) {
        document.getElementById('profile-menu').classList.add('hide');
    }
});

function confirmLogout(event) {
    event.preventDefault(); 
    document.getElementById('logoutDialog').style.display = 'flex';
}

document.getElementById('cancelLogout').onclick = function() {
    document.getElementById('logoutDialog').style.display = 'none';
}

document.getElementById('confirmLogoutBtn').onclick = function() {
    window.location.href = '../logout.php';
}

// Post Options/Delete Logic
document.querySelectorAll('.post-options .options-button').forEach(button => {
    button.addEventListener('click', function() {
        const postOptionsDiv = this.closest('.post-options');
        const deleteForm = postOptionsDiv.querySelector('.delete-post-form');
        const deleteButton = deleteForm.querySelector('.open-delete-post-dialog');
        
        // Toggle visibility of the delete button
        deleteButton.style.display = deleteButton.style.display === 'block' ? 'none' : 'block';
    });
});

document.addEventListener('click', function(e) {
    if (!e.target.closest('.post-options')) {
        document.querySelectorAll('.post-options .open-delete-post-dialog').forEach(btn => {
            btn.style.display = 'none';
        });
    }
});


document.querySelectorAll('.open-delete-post-dialog').forEach(button => {
    button.addEventListener('click', function(e) {
        e.preventDefault(); 
        const form = this.closest('.delete-post-form');
        const postId = form.querySelector('input[name="post_id"]').value;
        
        // Show confirmation dialog (reusing logoutDialog structure for consistency)
        const deleteDialog = document.getElementById('logoutDialog');
        deleteDialog.querySelector('h3').textContent = 'Confirm Post Deletion';
        deleteDialog.querySelector('p').textContent = 'Are you sure you want to delete this post and all its comments?';
        deleteDialog.style.display = 'flex';

        const confirmButton = document.getElementById('confirmLogoutBtn');
        confirmButton.textContent = 'Delete';
        
        // Temporarily change the confirm button's action
        const oldConfirmAction = confirmButton.onclick;
        
        confirmButton.onclick = function() {
            // Submit the form
            form.submit();
            // Restore original content and action (optional, but good practice)
            deleteDialog.style.display = 'none';
            confirmButton.onclick = oldConfirmAction; 
        };

        document.getElementById('cancelLogout').onclick = function() {
            deleteDialog.style.display = 'none';
            // Restore original cancel action
            document.getElementById('cancelLogout').onclick = function() {
                deleteDialog.style.display = 'none';
                confirmButton.onclick = oldConfirmAction; 
            };
        };

        // Reset to original logout action if cancelled
        const originalCancelAction = document.getElementById('cancelLogout').onclick;
        document.getElementById('cancelLogout').onclick = function() {
            originalCancelAction();
            confirmButton.onclick = oldConfirmAction;
            confirmButton.textContent = 'Logout';
            deleteDialog.querySelector('h3').textContent = 'Confirm Logout';
            deleteDialog.querySelector('p').textContent = 'Are you sure you want to log out?';
        };
    });
});


// Comment Delete Logic
function deleteComment(commentId) {
    // Show confirmation dialog
    document.getElementById('comment-to-delete-id').value = commentId;
    document.getElementById('deleteCommentDialog').style.display = 'flex';
}

document.getElementById('cancelDeleteComment').onclick = function() {
    document.getElementById('deleteCommentDialog').style.display = 'none';
}

document.getElementById('confirmDeleteCommentBtn').onclick = function() {
    const commentId = document.getElementById('comment-to-delete-id').value;
    
    fetch('forums.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=delete_comment&comment_id=${commentId}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Remove the comment element from the DOM
            const commentElement = document.querySelector(`.comment[data-comment-id="${commentId}"]`);
            if (commentElement) {
                commentElement.remove();
            }
        } else {
            alert('Failed to delete comment: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred during comment deletion.');
    })
    .finally(() => {
        document.getElementById('deleteCommentDialog').style.display = 'none';
    });
}

// Like/Unlike Logic
document.querySelectorAll('.like-btn').forEach(button => {
    button.addEventListener('click', function() {
        if (this.disabled) return;
        const postId = this.dataset.postId;
        const action = this.classList.contains('liked') ? 'unlike_post' : 'like_post';
        const buttonElement = this;
        const countElement = this.querySelector('.like-count');
        
        fetch('forums.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=${action}&post_id=${postId}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                let currentLikes = parseInt(countElement.textContent);
                if (data.action === 'liked') {
                    buttonElement.classList.add('liked');
                    countElement.textContent = currentLikes + 1;
                } else if (data.action === 'unliked') {
                    buttonElement.classList.remove('liked');
                    countElement.textContent = Math.max(0, currentLikes - 1);
                }
            } else {
                alert('An error occurred while liking/unliking.');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred during the request.');
        });
    });
});

// Comment form toggle
function toggleCommentForm(button) {
    const postContainer = button.closest('.post-container');
    const form = postContainer.querySelector('.join-convo-form');
    form.style.display = form.style.display === 'flex' ? 'none' : 'flex';
    if (form.style.display === 'flex') {
        form.querySelector('input[name="comment_message"]').focus();
    }
}

// Post Modal Logic
const createPostBtn = document.querySelector('.create-post-btn');
const postModalOverlay = document.getElementById('create-post-modal-overlay');
const closeBtn = postModalOverlay.querySelector('.close-btn');

if (createPostBtn) {
    createPostBtn.addEventListener('click', () => {
        postModalOverlay.style.display = 'flex';
    });
}

closeBtn.addEventListener('click', () => {
    postModalOverlay.style.display = 'none';
});

// Rich Text Editor Logic
const textContent = document.querySelector('.text-content');
const postContentInput = document.getElementById('post-content-input');
const toolbarButtons = document.querySelectorAll('.toolbar .btn');

toolbarButtons.forEach(button => {
    button.addEventListener('mousedown', (e) => {
        e.preventDefault(); // Prevent text area from losing focus
        const element = button.getAttribute('data-element');
        
        if (element === 'link') {
            const url = prompt('Enter the URL:');
            if (url) {
                document.execCommand('createLink', false, url);
            }
        } else {
            document.execCommand(element, false, null);
        }
        
        // Ensure the content is kept up to date
        postContentInput.value = textContent.innerHTML;
        textContent.focus();
    });
});

textContent.addEventListener('input', () => {
    postContentInput.value = textContent.innerHTML;
});

// Image Upload Preview Logic
const postImageInput = document.getElementById('post_image');
const initialUploadBox = document.getElementById('initial-upload-box');
const imageUploadContainer = document.getElementById('image-upload-container');

if (initialUploadBox) {
    initialUploadBox.addEventListener('click', () => {
        postImageInput.click();
    });
}

postImageInput.addEventListener('change', function() {
    if (this.files && this.files[0]) {
        const file = this.files[0];
        const reader = new FileReader();
        
        // Simple client-side validation for image type
        const allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
        if (!allowedTypes.includes(file.type)) {
            alert("Only JPG, PNG, and GIF image files are allowed.");
            this.value = ''; // Reset the input
            return;
        }

        reader.onload = function(e) {
            // Remove previous content and add the preview/filename
            imageUploadContainer.innerHTML = `
                <div class="image-preview-box">
                    <img src="${e.target.result}" alt="Image Preview" class="uploaded-image-preview">
                    <p class="uploaded-filename">${file.name}</p>
                    <button type="button" class="remove-image-btn">&times;</button>
                </div>
            `;
            
            // Re-attach listener for removal
            imageUploadContainer.querySelector('.remove-image-btn').addEventListener('click', function() {
                postImageInput.value = ''; // Clear the file input
                imageUploadContainer.innerHTML = `
                    <label for="post_image" class="image-upload-area" id="initial-upload-box">
                        <span id="upload-text"><i class="fa fa-cloud-upload"></i> Upload an Image (optional)</span>
                    </label>
                    <input type="file" name="post_image" id="post_image" accept="image/*" style="display: none;">
                `;
                // Must re-attach the click listener to the new label
                document.getElementById('initial-upload-box').addEventListener('click', () => {
                    document.getElementById('post_image').click();
                });
            });
        };
        reader.readAsDataURL(file);
    }
});

// Report Modal Logic
const reportModalOverlay = document.getElementById('report-modal-overlay');
const reportPostIdInput = document.getElementById('report-post-id');

function openReportModal(postId) {
    reportPostIdInput.value = postId;
    reportModalOverlay.style.display = 'flex';
}

function closeReportModal() {
    reportModalOverlay.style.display = 'none';
    reportPostIdInput.value = '';
}

document.getElementById('cancelReport').onclick = closeReportModal;


// Modals for Pinned Items
function openModal(id) {
    const modal = document.getElementById(id);
    if (modal) {
        modal.style.display = 'flex';
    }
}

function closeModal(id) {
    const modal = document.getElementById(id);
    if (modal) {
        modal.style.display = 'none';
    }
}

// Pinned Modals (simple placeholders)
document.write(`
    <div class="modal-overlay" id="rulesModal" style="display:none;">
        <div class="modal" style="max-width: 500px;">
            <div class="modal-header">
                <h2>Forum Rules</h2>
                <button class="close-btn" onclick="closeModal('rulesModal')">&times;</button>
            </div>
            <div class="modal-body">
                <p><strong>1. Be Respectful:</strong> Treat everyone with kindness.</p>
                <p><strong>2. No Hate Speech:</strong> Zero tolerance for harassment or discrimination.</p>
                <p><strong>3. Stay on Topic:</strong> Keep discussions relevant to mentorship and local business.</p>
                <p><strong>4. No Spam/Self-Promotion:</strong> Posts must provide value.</p>
                <p><strong>5. Use the Report Button:</strong> Help moderators by reporting inappropriate content.</p>
            </div>
        </div>
    </div>
    <div class="modal-overlay" id="welcomeModal" style="display:none;">
        <div class="modal" style="max-width: 500px;">
            <div class="modal-header">
                <h2>Welcome to the Community!</h2>
                <button class="close-btn" onclick="closeModal('welcomeModal')">&times;</button>
            </div>
            <div class="modal-body">
                <p>Welcome, Mentees! This forum is a safe space to share experiences, ask questions, and network with fellow local entrepreneurs. Don't be shy—introduce yourself!</p>
                <p>If you need one-on-one help, remember to use the 'Sessions' tab to connect with your Mentor.</p>
                <p>Happy posting!</p>
            </div>
        </div>
    </div>
`);


// --- NEW: Live Ban Countdown Logic (UPDATED) ---
<?php if ($isBanned && $ban_details['unban_datetime']): ?>
    // Use the unban datetime from PHP to calculate the end time in milliseconds
    var unbanTimestamp = <?php echo strtotime($ban_details['unban_datetime']); ?> * 1000;
    
    function updateCountdown() {
        var now = new Date().getTime();
        var distance = unbanTimestamp - now;
        
        var countdownElement = document.getElementById('countdown-timer');

        if (distance < 0) {
            clearInterval(countdownInterval);
            countdownElement.innerHTML = "Ban Expired. Refreshing page...";
            // Small delay before refreshing to ensure user sees the message
            setTimeout(function() {
                window.location.reload(); 
            }, 2000);
            return;
        }

        var days = Math.floor(distance / (1000 * 60 * 60 * 24));
        var hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
        var minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
        var seconds = Math.floor((distance % (1000 * 60)) / 1000);

        var countdownText = '';
        if (days > 0) countdownText += days + "d ";
        if (days > 0 || hours > 0) countdownText += hours + "h ";
        if (days > 0 || hours > 0 || minutes > 0) countdownText += minutes + "m ";
        countdownText += seconds + "s";
        
        countdownElement.innerHTML = countdownText;
    }

    updateCountdown(); // Run immediately
    var countdownInterval = setInterval(updateCountdown, 1000);
<?php endif; ?>

</script>
</body>
</html>