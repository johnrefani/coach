<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();

// --- ACCESS CONTROL ---
// Check if the user is logged in and if their user_type is 'Mentee'
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'Mentee') {
    // If not a Mentee, redirect to the login page.
    header("Location: login.php");
    exit();
}

// --- FETCH USER ACCOUNT ---
require '../connection/db_connection.php';

$username = $_SESSION['username'];
$displayName = '';
$userIcon = 'img/default-user.png'; // Default icon path
$userId = null; // New variable for user_id

$stmt = $conn->prepare("SELECT user_id, first_name, last_name, icon FROM users WHERE username = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $userId = $row['user_id'];
    $displayName = $row['first_name'] . ' ' . $row['last_name'];
    if (!empty($row['icon'])) $userIcon = $row['icon'];
}
$stmt->close(); // Close the statement after use

// Check if user ID was found
if ($userId === null) {
    // Handle the case where the user's ID couldn't be found (e.g., redirect to login)
    header("Location: login.php");
    exit();
}

// --- BAN CHECK ---
$isBanned = false;
$ban_check_stmt = $conn->prepare("SELECT reason, ban_until FROM banned_users WHERE username = ? AND (ban_until IS NULL OR ban_until > NOW())");
$ban_check_stmt->bind_param("s", $username);
$ban_check_stmt->execute();
$ban_result = $ban_check_stmt->get_result();
if ($ban_result->num_rows > 0) {
    $isBanned = true;
    $ban_details = $ban_result->fetch_assoc();
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
    
    // Initialize admin/mentor flags as this page is for Mentees
    $isAdmin = 0;
    $isMentor = 0;

    // CORRECTED: The type string now matches the variables, and the variable $userIcon is spelled correctly.
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
            // Delete the comment only if the current user is the author
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
        exit(); // Crucial: Stop execution for the AJAX request
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
    // Handle Delete Post
    elseif ($action === 'delete_post' && isset($_POST['post_id'])) {
        $postId = intval($_POST['post_id']);
        if ($postId > 0) {
            // Start a database transaction
            $conn->begin_transaction();

            try {
                // Step 1: Delete all comments linked to the post's ID
                $comments_stmt = $conn->prepare("DELETE FROM general_forums WHERE forum_id = ? AND chat_type = 'comment'");
                $comments_stmt->bind_param("i", $postId);
                $comments_stmt->execute();
                $comments_stmt->close();

                // Step 2: Delete the main post (includes security check for ownership)
                $post_stmt = $conn->prepare("DELETE FROM general_forums WHERE id = ? AND user_id = ?");
                $post_stmt->bind_param("ii", $postId, $userId);
                $post_stmt->execute();
                $post_stmt->close();

                // If both queries succeed, commit the changes
                $conn->commit();

            } catch (mysqli_sql_exception $exception) {
                // If any part fails, roll back all changes
                $conn->rollback();
                // You can add error logging here if needed
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
    // Handle the SQL preparation error
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

if ($userId === null) {
    // Handle the case where the user's ID couldn't be found (e.g., redirect to login)
    header("Location: login.php");
    exit();
}

// NOTE: Define the base URL early here so the function can use it.
// Define it WITHOUT the trailing slash for maximum compatibility.
$baseUrl = "http://localhost/coachlocal"; 

// --- FUNCTION TO RENDER TOP CONTRIBUTORS ---
function render_top_contributors($conn, $baseUrl) {
    ob_start(); // Start output buffering to capture HTML output

    // Fetch top 3 contributors by post count
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

            // --- AVATAR LOGIC ---
            if (!empty($row['icon'])) {
                
                // 1. Clean the path: Safely remove all leading '../' strings.
                $cleanedPath = str_replace("../", "", $row['icon']);
                
                // 2. Remove any extra leading slash from the path.
                $cleanedPath = ltrim($cleanedPath, '/');
                
                // 3. Construct the FINAL URL: Base URL + single slash + Cleaned Path.
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
            // --- END AVATAR LOGIC ---
            
            // ... (Rest of your HTML output logic goes here) ...
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

    return ob_get_clean(); // Return the captured HTML output
}
// --- AJAX HANDLER 1: LIKES RECEIVED (From our previous fix) ---
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
    exit; // Critical: Stops execution for AJAX request
}

// --- AJAX HANDLER 2: TOP CONTRIBUTORS ---
if (isset($_GET['action']) && $_GET['action'] === 'get_contributors') {
    $contributorHtml = render_top_contributors($conn, $baseUrl);

    header('Content-Type: application/json');
    echo json_encode(['html' => $contributorHtml]);
    $conn->close(); // Close connection
    exit; // Critical: Stops execution for AJAX request
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
                <li><a href="#" onclick="confirmLogout()">Logout</a></li>
              </ul>
            </div>
          </div>
        </nav>
    </section>

    <div class="forum-layout">
  
 <div class="sidebar-left">
<div class="sidebar-box user-stats-box">
    <h3>My Activity</h3>
    <ul>
        <?php
        // Ensure connection is available
        // NOTE: This line is redundant if already included at the top, but safe to keep.
        require_once '../connection/db_connection.php'; 

        // Assume $userId, $displayName, and $userIcon are already fetched at the top of forums.php.

        // --- 1. INITIALIZE COUNTS (CRITICAL: Must be done before use) ---
        $post_count = 0;
        $likes_received = 0;

        if ($userId) { 
            // --- 2. COUNT TOTAL POSTS ---
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

            // --- 3. SUM TOTAL LIKES RECEIVED ---
            $sql_likes = "
                SELECT 
                    COALESCE(SUM(post_likes.like_count), 0) AS total_likes 
                FROM (
                    -- Subquery: Counts likes per post by the user's posts
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

        // --- 4. AVATAR LOGIC (CRITICAL: Must be done after $displayName and $userIcon are set) ---
        $avatarHtml = '';
        $avatarSize = '50px'; // Set a size for the summary icon
        
        if (!empty($userIcon) && $userIcon !== 'img/default-user.png') {
            // A. User has an icon. Output the standard image tag.
            $avatarHtml = '<img src="' . htmlspecialchars($userIcon) . '" alt="' . htmlspecialchars($displayName) . ' Icon" class="user-icon-summary">';
        } else {
            // B. User is missing an icon. Generate initials avatar.
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
            
            // Use inline styles consistent with other initial avatars on the page
            $avatarHtml = '<div class="user-icon-summary" style="
                width: ' . $avatarSize . '; 
                height: ' . $avatarSize . '; 
                border-radius: 50%;
                background: #6a2c70; /* Use a consistent color */ 
                color: #fff; 
                display: flex; 
                align-items: center; 
                justify-content: center; 
                font-size: 20px; 
                font-weight: bold;
                margin: 0 auto 10px auto; /* Center it and add bottom margin */
                ">'
                . htmlspecialchars($initials) . 
                '</div>';
        }
        // --- END AVATAR LOGIC ---
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

  <!-- New Advertisement Box -->
    <h3>💖 Recent Likes</h3>
    <ul>
      <?php
      // Assuming $userId is available and connection is established
      
      $sql = "
        SELECT 
            u.first_name, 
            u.last_name, 
            u.icon,  /* Fetch the liker's icon path */
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
        LIMIT 4  /* Displays exactly 4 recent likes */
      ";

      $result = $conn->query($sql);

      if ($result && $result->num_rows > 0) {
          while ($row = $result->fetch_assoc()) {
              
              // Only use the first name for the display text
              $likerName = htmlspecialchars($row['first_name']); 
              
              $postTitle = htmlspecialchars($row['title']);
              $likerIconPath = $row['icon'] ?? ''; 
              $firstName = $row['first_name'] ?? '';
              $lastName = $row['last_name'] ?? '';
              
              $avatarSize = '25px'; 
              $avatarMargin = '4px'; // Compact spacing
              $likerAvatar = '';
              
              // --- Conditional Avatar Logic (Image or Initials) ---
              if (!empty($likerIconPath)) {
                  // A. User has an icon: use IMG tag
                  $likerAvatar = '<img src="' . htmlspecialchars($likerIconPath) . '" 
                                   alt="Liker Icon" 
                                   style="width:' . $avatarSize . '; height:' . $avatarSize . '; border-radius:50%; margin-right: ' . $avatarMargin . ';">';
              } else {
                  // B. User is missing an icon: generate initials
                  $initials = '';
                  if (!empty($firstName)) $initials .= strtoupper(substr($firstName, 0, 1));
                  if (!empty($lastName)) $initials .= strtoupper(substr($lastName, 0, 1));
                  $initials = substr($initials, 0, 2);
                  if (empty($initials)) $initials = '?';
              
                  // Initial avatar DIV
                  $likerAvatar = '<div style="width:' . $avatarSize . '; height:' . $avatarSize . '; border-radius:50%; background:#6a2c70; color:#fff; display:inline-flex; align-items:center; justify-content:center; font-size:10px; font-weight:bold; margin-right: ' . $avatarMargin . ';">'
                                 . htmlspecialchars($initials) . 
                                 '</div>';
              }

              // Truncate title
              if (strlen($postTitle) > 30) {
                  $postTitle = substr($postTitle, 0, 30) . '...';
              }
              
              // FINAL OUTPUT: Uses a flex container on <li> and a wrapper DIV (flex: 1) on the text for perfect vertical alignment and neat wrapping.
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

  <!-- MAIN FORUM CONTENT -->
  <div class="chat-container">
        <?php if ($isBanned): ?>
            <div class="banned-message" style="text-align: center; background-color: #f8d7da; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
                <h2 style="color: #721c24;">You have been banned.</h2>
                <p><strong>Reason:</strong> <?php echo htmlspecialchars($ban_details['reason']); ?></p>
                <?php if ($ban_details['ban_until']): ?>
                    <p>Your ban will be lifted on <?php echo date("F j, Y, g:i a", strtotime($ban_details['ban_until'])); ?>.</p>
                <?php else: ?>
                    <p>This is a permanent ban.</p>
                <?php endif; ?>
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
        // 🔑 FIX: Use the reliable display_name field from the post array
        $postDisplayName = $post['display_name'] ?? 'Guest'; 

        if (!empty($iconPath) && $iconPath !== 'img/default-user.png') {
            // A. User has an icon. Output the standard image tag.
            ?>
            <img src="<?php echo htmlspecialchars($iconPath); ?>" alt="<?php echo htmlspecialchars($postDisplayName); ?> Icon" class="user-avatar">
            <?php
        } else {
            // B. User is missing an icon. Generate initials avatar using the proven logic.
            $initials = '';
            $nameParts = explode(' ', $postDisplayName);
            
            // Collect initials from each word (up to two letters)
            foreach ($nameParts as $part) {
                if (!empty($part)) {
                     $initials .= strtoupper(substr($part, 0, 1));
                }
                if (strlen($initials) >= 2) break;
            }
            
            // Final check for a fallback if the name was truly empty
            if (empty($initials)) {
                $initials = '?';
            }

            // Output the custom initial avatar DIV. 
            ?>
            <div class="user-avatar" style="
                /* Use the same inline styles for consistency with your sidebar */
                width: 40px; /* Use the size defined by your .user-avatar CSS */
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
                                    <form class="delete-post-form" action="forums.php" method="POST" onsubmit="return confirm('Are you sure you want to permanently delete this post?');">
                                        <input type="hidden" name="action" value="delete_post">
                                        <input type="hidden" name="post_id" value="<?php echo $post['id']; ?>">
                                        <button type="submit" class="delete-post-button">Delete post</button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="post-title"><?php echo htmlspecialchars($post['title']); ?></div>

                    <div class="post-content">
                        <?php
                            // This part displays the text and makes links clickable
                            $formattedMessage = makeLinksClickable($post['message']);
                            echo $formattedMessage;
                        ?>
                        <br>
                        <?php if (!empty($post['file_path'])): ?>
                            <img src="<?php echo htmlspecialchars($post['file_path']); ?>" alt="Post Image">
                        <?php endif; ?>
                    </div>

                    <div class="post-actions">
                        <button class="action-btn like-btn <?php echo $post['has_liked'] ? 'liked' : ''; ?>" data-post-id="<?php echo $post['id']; ?>">
                            ❤️ <span class="like-count"><?php echo $post['likes']; ?></span>
                        </button>
                        <button class="action-btn" onclick="toggleCommentForm(this)">💬 Comment</button>
                        <button class="report-btn" onclick="openReportModal(<?php echo $post['id']; ?>)">
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
    // Option 2 Fix: Define the variable used in the conditional check.
    // This assigns the current logged-in user's ID ($userId) to $current_user_id.
    $current_user_id = $userId; 
    
    foreach ($post['comments'] as $comment): 
    ?>
        <div class="comment" data-comment-id="<?php echo $comment['id']; ?>">
            <img src="<?php echo htmlspecialchars(!empty($comment['user_icon']) ? $comment['user_icon'] : 'img/default-user.png'); ?>" alt="Commenter Icon" class="user-avatar" style="width: 30px; height: 30px;">
            <div class="comment-author-details">
                <div class="comment-bubble">
                    <strong><?php echo htmlspecialchars($comment['display_name']); ?></strong>
                    <?php echo htmlspecialchars($comment['message']); ?>
                </div>
                <div class="comment-timestamp">
                    <?php echo date("F j, Y, g:i a", strtotime($comment['timestamp'])); ?>
                    
                 <?php if ($current_user_id && $current_user_id == $comment['user_id']): ?>
                <button class="delete-btn" onclick="deleteComment(<?php echo htmlspecialchars($comment['id']); ?>)" title="Delete Comment">
                  🗑️ </button>
                    <?php endif; ?>
                    
                    <button class="report-btn" onclick="openReportModal(<?php echo htmlspecialchars($comment['id']); ?>)" title="Report Comment">
                        <i class="fa fa-flag"></i>
                    </button>
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
                <textarea name="reason" rows="4" required></textarea>
                <button type="submit" class="post-btn">Submit Report</button>
            </form>
        </div>
    </div>

    <div class="modal-overlay" id="ban-modal-overlay" style="display:none;">
        <div class="modal">
            <div class="modal-header">
                <h2>Ban User</h2>
                <button class="close-btn" onclick="closeBanModal()">&times;</button>
            </div>
            <form action="forums.php" method="POST">
                <input type="hidden" name="admin_action" value="ban_user">
                <input type="hidden" id="ban-username" name="username_to_ban" value="">
                <p>You are about to ban <strong id="ban-username-display"></strong>.</p>
                <label for="ban_reason">Reason for ban (optional):</label>
                <textarea id="ban_reason" name="ban_reason" class="ban-modal-reason" rows="3"></textarea>
                <button type="submit" class="post-btn" style="background-color: #d9534f;">Confirm Ban</button>
            </form>
        </div>
    </div>



<div class="sidebar-right">
<div class="sidebar-box ad-box" style="
    /* Reduced Padding and a simpler look */
    background-color: #f4e4fcff; /* Light pink background */
    border: 1px solid #4e036fff;
    padding: 10px; /* Reduced padding */
    border-radius: 6px; /* Slightly smaller radius */
    text-align: center;
    margin-bottom: 15px;
">
    
      <h3 style="font-size: 14px; margin-bottom: 5px;">🏆 Level Up Your Skills Today!</h3>
    
    <p style="font-size: 12px; margin-bottom: 10px; color: #4a148c; font-weight: 500;">
        Explore our curated collection of online courses.
    </p>
    
    <a href="course.php" style="
        display: block;
        padding: 8px; /* Reduced padding */
        background-color: #6f2c9fff;
        color: white;
        text-decoration: none;
        border-radius: 4px;
        font-size: 13px; /* Smaller text */
        font-weight: 600;
    " onmouseover="this.style.backgroundColor='#4a148c'" onmouseout="this.style.backgroundColor='#4a148c'">
        Check Now!
    </a>
    
</div>

<h3>⭐ Top Contributors</h3>
<div class="contributors">
    <?php
    // The previous require is sufficient, no need to include it again if it's already at the top.
    // require '../connection/db_connection.php'; 

    // Base URL for image paths
    // IMPORTANT: Make sure this URL is correct. It should point to the root folder
    // where your 'uploads' directory is accessible. We ensure no trailing slash.
    $baseUrl = "http://localhost/coachlocal"; 

    // Fetch top 3 contributors by post count
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
            
    // Inside the contributors div, replace the block:
    if (!empty($row['icon'])) {
                
        $iconPath = $row['icon'];
        // --- REMOVE THE NEXT 9 LINES OF PATH MANIPULATION AND $baseUrl CONCATENATION ---
        // 1. Clean the path: Remove *any* leading '../' or './' 
        // to make the path relative to the web root.
        while (str_starts_with($iconPath, '../') || str_starts_with($iconPath, './')) {
            if (str_starts_with($iconPath, '../')) {
                $iconPath = substr($iconPath, 3);
            } elseif (str_starts_with($iconPath, './')) {
                $iconPath = substr($iconPath, 2);
            }
        }
        
        // 2. Ensure the path does not have a leading slash, as the $baseUrl doesn't have a trailing slash.
        $iconPath = ltrim($iconPath, '/');

        // 3. Construct the full absolute URL.
        $fullIconUrl = htmlspecialchars($baseUrl . '/' . $iconPath);
        // --- END REMOVED BLOCK ---
        
        // --- NEW LINE: USE THE ORIGINAL DATABASE PATH ---
        $fullIconUrl = htmlspecialchars($row['icon']); 
        
        $avatar = '<img src="' . $fullIconUrl . '" 
                   alt="User Avatar" width="35" height="35" 
                   style="border-radius:50%; object-fit: cover;">'; 
    } 
// ... (rest of the code)
            // --- FINAL ROBUST ICON FETCHING LOGIC END ---
            else {
                // Generate initials from display_name (fallback)
                $initials = '';
                $nameParts = explode(' ', $row['display_name']);
                foreach ($nameParts as $part) {
                    $initials .= strtoupper(substr($part, 0, 1));
                }
                $initials = substr($initials, 0, 2);

                // Purple initials avatar
                $avatar = '<div style="width:35px; height:35px; border-radius:50%; 
                                        background:#6a2c70; color:#fff; display:flex; 
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
    ?>
</div>

<div class="sidebar-box updates-box">
  <h3>📋 Latest Updates</h3>

  <?php
  // Your PHP variables for sizing (from previous context)
  $avatarSize = '30px'; 
  $fontSize = '12px'; 
  $spacing = '8px'; 

  // Fetch the latest 3 posts with user avatars
  $sql = "SELECT gf.display_name, gf.title, gf.message, gf.timestamp, u.icon
          FROM general_forums gf
          LEFT JOIN users u ON gf.user_id = u.user_id
          WHERE gf.chat_type = 'forum'
          ORDER BY gf.timestamp DESC 
          LIMIT 3";
  $result = $conn->query($sql);

  if ($result && $result->num_rows > 0) {
      while ($row = $result->fetch_assoc()) {
          $avatar = '';
          // If user uploaded an icon, use it
          if (!empty($row['icon'])) {
              // 🔑 FIX: Use the icon path directly from the database.
              $iconPath = $row['icon'];

              $avatar = '<img src="' . htmlspecialchars($iconPath) . '" 
                          alt="User" width="' . $avatarSize . '" height="' . $avatarSize . '" style="border-radius:50%; object-fit: cover;">';
          } else {
              // Generate initials from display_name (FALLBACK LOGIC)
              $initials = '';
              $nameParts = explode(' ', $row['display_name']);
              foreach ($nameParts as $part) {
                  $initials .= strtoupper(substr($part, 0, 1));
              }
              $initials = substr($initials, 0, 2); // Limit to 2 chars

              // Purple circle avatar with initials
              $avatar = '<div style="width:' . $avatarSize . '; height:' . $avatarSize . '; border-radius:50%; 
                                   background:#6f42c1; color:#fff; display:flex; 
                                   align-items:center; justify-content:center; 
                                   font-size:' . $fontSize . '; font-weight:bold;">'
                                   . htmlspecialchars($initials) . 
                        '</div>';
          }

          // Format time
          $timeAgo = date("M d, Y H:i", strtotime($row['timestamp']));
  ?>
      <div class="update" style="display:flex; gap:<?php echo $spacing; ?>; align-items:flex-start; margin-bottom:<?php echo $spacing; ?>;">
        <?php echo $avatar; ?>
        <div style="flex: 1; min-width: 0; line-height: 1.3;">
          <p style="margin:0;"><strong><?php echo htmlspecialchars($row['display_name']); ?></strong> 
             posted "<?php echo htmlspecialchars($row['title']); ?>"</p>
          <span class="time" style="font-size:12px; color:#666;"><?php echo $timeAgo; ?></span>
        </div>
      </div>
  <?php
      }
  } else {
      echo "<p>No recent updates.</p>";
  }
  ?>
</div>


<div id="rulesModal" class="modal-overlay"> 
    <div class="modal-content-box"> 
        <span class="close" onclick="closeModal('rulesModal')">&times;</span>
        <h2>📌 Forum Rules: Community Guidelines</h2>
        <div class="modal-body">
            <p>Welcome to our community! To ensure a positive and productive environment for everyone, please adhere to these core rules:</p>
            
            <h3>1. Be Respectful and Professional</h3>
            <ul>
                <li><strong>No Harassment:</strong> Do not attack, insult, or harass other members. Keep criticism constructive and focused on the topic, not the person.</li>
                <li><strong>Respect Privacy:</strong> Do not share personal information (yours or others') without explicit consent.</li>
            </ul>

            <h3>2. Keep Content Relevant</h3>
            <ul>
                <li><strong>Stay on Topic:</strong> Posts should relate to the subject matter of the forum (e.g., mentorship, career, technology, etc.).</li>
                <li><strong>No Spam or Self-Promotion:</strong> Excessive self-promotion, repeated posting of the same content, or link-dropping is prohibited.</li>
            </ul>

            <h3>3. Maintain Integrity</h3>
            <ul>
                <li><strong>Honesty:</strong> Do not post false or misleading information.</li>
                <li><strong>Report Issues:</strong> If you see a post that violates these rules, please use the 'Report Post' function instead of engaging in an argument.</li>
            </ul>
        </div>
    </div>
</div>

<div id="welcomeModal" class="modal-overlay"> 
    <div class="modal-content-box"> 
        <span class="close" onclick="closeModal('welcomeModal')">&times;</span>
        <h2>📣 Welcome to the COACH Forum!</h2>
        <div class="modal-body">
            <p>We're thrilled to have you join the <strong>COACH Forum</strong>, your dedicated hub for <strong>guidance, mentorship, and professional development</strong>. This space is designed to foster valuable connections, offer actionable advice, and support your journey towards personal and professional growth.</p>

            <h3>What You'll Find Here:</h3>
            <ul>
                <li><strong>Expert Guidance:</strong> Connect with experienced coaches and mentors across various industries who are ready to share their insights and perspectives.</li>
                <li><strong>Goal Setting & Strategy:</strong> Discuss career roadmaps, personal challenges, and effective strategies for achieving your long-term objectives.</li>
                <li><strong>Peer Support:</strong> Engage with a community of ambitious individuals who are facing similar challenges and celebrating successes together.</li>
                <li><strong>Resource Sharing:</strong> Access curated articles, tools, and recommended readings shared by members to enhance your skills and knowledge base.</li>
            </ul>

            <p>Remember to check the <strong>Forum Rules</strong> before posting. Let's start achieving your goals!</p>
        </div>
    </div>
</div>

<script src="mentee.js"></script>
<script>
    // --- NEW: MODAL FUNCTIONS (REPORT & BAN) ---
    function openReportModal(postId) {
        document.getElementById('report-post-id').value = postId;
        document.getElementById('report-modal-overlay').style.display = 'flex';
    }
    function closeReportModal() {
        document.getElementById('report-modal-overlay').style.display = 'none';
    }
    function openBanModal(username) {
        document.getElementById('ban-username').value = username;
        document.getElementById('ban-username-display').innerText = username;
        document.getElementById('ban-modal-overlay').style.display = 'flex';
    }
    function closeBanModal() {
        document.getElementById('ban-modal-overlay').style.display = 'none';
    }

    // --- ORIGINAL FUNCTIONS (LOGOUT & COMMENT) ---
    function confirmLogout() {
        if (confirm("Are you sure you want to log out?")) {
            window.location.href = "../login.php";
        }
    }
    function toggleCommentForm(btn) {
        const form = btn.closest('.post-container').querySelector('.join-convo-form');
        form.style.display = form.style.display === 'none' ? 'flex' : 'none';
    }

    // This runs after the entire page is loaded to prevent errors
    document.addEventListener("DOMContentLoaded", function () {
        // --- NEW: FILE NAME DISPLAY LOGIC ---
        const postImageInput = document.getElementById('post_image');
        const uploadText = document.getElementById('upload-text');
        let defaultUploadText = '';
        if (uploadText) {
            defaultUploadText = uploadText.innerHTML; // Save the original text
            postImageInput.addEventListener('change', function(event) {
                if (event.target.files.length > 0) {
                    const fileName = event.target.files[0].name;
                    uploadText.innerHTML = `<i class="fa fa-check-circle"></i> ${fileName}`;
                } else {
                    uploadText.innerHTML = defaultUploadText;
                }
            });
        }
        
        // --- MERGED "CREATE POST" MODAL LOGIC ---
        const createPostBtn = document.querySelector('.create-post-btn');
        const createPostModal = document.querySelector('#create-post-modal-overlay'); // Specific ID for this modal
        if (createPostBtn && createPostModal) {
            const closeBtn = createPostModal.querySelector('.close-btn');

            createPostBtn.addEventListener('click', () => {
                // Reset form fields
                const titleInput = createPostModal.querySelector('.title-input');
                const contentDiv = createPostModal.querySelector('.text-content');
                if (titleInput) titleInput.value = '';
                if (contentDiv) contentDiv.innerHTML = '';
                
                // MERGED: Reset file upload text
                if (uploadText) {
                    postImageInput.value = ''; 
                    uploadText.innerHTML = defaultUploadText; 
                }
                
                // Show modal
                createPostModal.style.display = 'flex';
            });

            closeBtn.addEventListener('click', () => {
                createPostModal.style.display = 'none';
            });

            createPostModal.addEventListener('click', (e) => {
                if (e.target === createPostModal) {
                    createPostModal.style.display = 'none';
                }
            });
        }

        // --- ORIGINAL TEXT FORMATTING ---
        const formatBtns = document.querySelectorAll('.modal .toolbar .btn');
        const contentDiv = document.querySelector('.modal .text-content');
        if (contentDiv) {
            formatBtns.forEach(element => {
                element.addEventListener('click', () => {
                    let command = element.dataset['element'];
                    contentDiv.focus();
                    if (command === 'link') {
                        let url = prompt('Enter the link here:', 'https://');
                        if (url) document.execCommand('createLink', false, url);
                    } else {
                        document.execCommand(command, false, null);
                    }
                });
            });
        }

        // --- ORIGINAL FORM SUBMISSION FOR RICH TEXT ---
        const postForm = document.getElementById('post-form');
        const contentInput = document.getElementById('post-content-input');
        if (postForm && contentDiv && contentInput) {
            postForm.addEventListener('submit', function() {
                contentInput.value = contentDiv.innerHTML;
            });
        }

        // --- ORIGINAL PROFILE MENU ---
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

        // --- ORIGINAL LIKE/UNLIKE FUNCTIONALITY ---
        document.querySelectorAll('.like-btn').forEach(button => {
            button.addEventListener('click', function() {
                const postId = this.getAttribute('data-post-id');
                const likeCountElement = this.querySelector('.like-count');
                const hasLiked = this.classList.contains('liked');
                let action = hasLiked ? 'unlike_post' : 'like_post';

                fetch('forums.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: `action=${action}&post_id=${postId}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        let currentLikes = parseInt(likeCountElement.textContent);
                        if (data.action === 'liked') {
                            likeCountElement.textContent = currentLikes + 1;
                            this.classList.add('liked');
                        } else if (data.action === 'unliked') {
                            likeCountElement.textContent = currentLikes - 1;
                            this.classList.remove('liked');
                        }
                    }
                })
                .catch(error => console.error('Error handling like:', error));
            });
        });
    });

        // --- NEW: POST OPTIONS MENU LOGIC ---
        document.querySelectorAll('.options-button').forEach(button => {
            button.addEventListener('click', function (event) {
                event.stopPropagation(); // Prevents the window click event from firing immediately
                const deleteForm = this.nextElementSibling;

                // Close all other open delete buttons first
                document.querySelectorAll('.delete-post-form').forEach(form => {
                    if (form !== deleteForm) {
                        form.classList.remove('show');
                    }
                });

                // Toggle the current delete button
                deleteForm.classList.toggle('show');
            });
        });

        // Close the delete button if clicking anywhere else on the page
        window.addEventListener('click', function (event) {
            document.querySelectorAll('.delete-post-form.show').forEach(form => {
                // Hide if the click is outside the form and its sibling kebab button
                if (!form.contains(event.target) && !form.previousElementSibling.contains(event.target)) {
                    form.classList.remove('show');
                }
            });
        });

function openModal(id) {
  document.getElementById(id).style.display = 'flex'; 
}

function closeModal(id) {
  document.getElementById(id).style.display = 'none';
}

// Backdrop click: needs to look for the correct class
window.onclick = function(event) {
  let modals = document.querySelectorAll(".modal-overlay"); // 🔑 This must be .modal-overlay
  modals.forEach(m => {
    if (event.target == m) {
      m.style.display = "none";
    }
  });

function refreshSidebarLikes() {
    // Calls forums.php with ?action=get_likes, hitting the AJAX handler
    fetch('forums.php?action=get_likes') 
        .then(response => {
            if (!response.ok) {
                 throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(data => {
            if (data.total_likes !== undefined) {
                const likesElement = document.getElementById('likes-received-count');
                if (likesElement) {
                    // Update the sidebar element with the new count
                    likesElement.textContent = data.total_likes;
                }
            }
        })
        .catch(error => console.error('Error refreshing sidebar likes:', error));
}

// --- MODIFY EXISTING LIKE BUTTON LOGIC ---

document.querySelectorAll('.like-button').forEach(button => {
    button.addEventListener('click', function (event) {
        // ... (Your existing code to prepare data for handle_like.php) ...

        fetch('handle_like.php', { /* ... your existing fetch parameters ... */ })
            .then(response => response.json())
            .then(data => {
                // ... (Your existing code to update the current post's like count) ...

                if (data.success) {
                    // 🔑 CRITICAL FIX: Call the function to update the sidebar here!
                    refreshSidebarLikes(); 
                }
            })
            .catch(error => console.error('Error handling like:', error));
    });
});
}

// Add this function to your <script> block
function deleteComment(commentId) {
    if (!confirm("Are you sure you want to delete this comment?")) {
        return;
    }

    const formData = new FormData();
    formData.append('action', 'delete_comment');
    formData.append('comment_id', commentId);

    fetch('forums.php', { // Sending the request back to forums.php
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Find and remove the comment element from the DOM
            const commentElement = document.querySelector(`.comment[data-comment-id="${commentId}"]`);
            if (commentElement) {
                commentElement.remove();
                // Optionally show a small success message
            }
        } else {
            alert("Error: " + (data.message || "Could not delete comment."));
        }
    })
    .catch(error => {
        console.error('Error deleting comment:', error);
        alert("An error occurred while trying to delete the comment.");
    });
}

</script>
</body>
</html>