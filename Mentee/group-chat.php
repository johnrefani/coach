<?php
session_start();

// Database connection
require 'connection/db_connection.php';

// SESSION CHECK
if (!isset($_SESSION['username']) && !isset($_SESSION['admin_username'])) {
    header("Location: login_mentee.php");
    exit();
}

// Determine if user is admin or mentee
$isAdmin = isset($_SESSION['admin_username']);
$currentUser = $isAdmin ? $_SESSION['admin_username'] : $_SESSION['username'];
$isMentor = isset($_SESSION['mentor_username']);

// Get user's full name for display
if ($isAdmin) {
    $stmt = $conn->prepare("SELECT Admin_Name FROM admins WHERE Admin_Username = ?");
    $stmt->bind_param("s", $currentUser);
    $stmt->execute();
    $result = $stmt->get_result();
    $displayName = $result->fetch_assoc()['Admin_Name'];
} elseif ($isMentor) {
    $stmt = $conn->prepare("SELECT First_Name, Last_Name FROM applications WHERE Applicant_Username = ?");
    $stmt->bind_param("s", $currentUser);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $displayName = $row['First_Name'] . ' ' . $row['Last_Name'];
} else {
    $stmt = $conn->prepare("SELECT First_Name, Last_Name FROM mentee_profiles WHERE Username = ?");
    $stmt->bind_param("s", $currentUser);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $displayName = $row['First_Name'] . ' ' . $row['Last_Name'];
}

// Filter profanity
function filterProfanity($text) {
    $profaneWords = [
    // English
    'fuck','shit','bitch','asshole','bastard','slut','whore',
    'dick','pussy','faggot','cunt','motherfucker','cock',
    'prick','jerkoff','cum','fuckshit','shithead','dumbass',
    'jackass','sonofabitch',

    // Tagalog / Filipino
    'putangina','tangina','pakshet','gago','ulol','leche',
    'bwisit','pucha','punyeta','hinayupak','lintik',
    'tarantado','inutil','siraulo','bobo','tanga', "pakyu",

    // Bisaya / Cebuano
    'yawa','yati','pisti','piste','buang','gagoha',
    'lintian','atay',

    // Spanish / Latinx
    'pendejo','cabron','maricon','chingada','mierda',
    'hijo de puta','culero','puta','pinche'
];


    foreach ($profaneWords as $word) {
        // Create a case-insensitive regex pattern for the word
        $pattern = '/\b' . preg_quote($word, '/') . '\b/i';
        // Replace the matched word with asterisks
        $text = preg_replace($pattern, '****', $text);
    }

    return $text;
}

// Handle like/unlike submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && ($_POST['action'] === 'like_post' || $_POST['action'] === 'unlike_post') && isset($_POST['post_id'])) {
    $postId = intval($_POST['post_id']);
    $response = ['success' => false, 'message' => '', 'action' => ''];

    if ($postId > 0) {
        // Check if the user has already liked this post
        $stmt = $conn->prepare("SELECT COUNT(*) FROM post_likes WHERE post_id = ? AND username = ?");
        $stmt->bind_param("is", $postId, $currentUser);
        $stmt->execute();
        $stmt->bind_result($likeCount);
        $stmt->fetch();
        $stmt->close();

        if ($likeCount == 0) {
            // User has not liked this post yet, so add the like
            $conn->begin_transaction();
            try {
                // 1. Add a record to the post_likes table
                $stmt = $conn->prepare("INSERT INTO post_likes (post_id, username) VALUES (?, ?)");
                $stmt->bind_param("is", $postId, $currentUser);
                $stmt->execute();

                // 2. Increment the likes count in the chat_messages table
                $stmt = $conn->prepare("UPDATE chat_messages SET likes = likes + 1 WHERE id = ?");
                $stmt->bind_param("i", $postId);
                $stmt->execute();

                $conn->commit();
                $response['success'] = true;
                $response['action'] = 'liked';
                $response['message'] = 'Like added successfully.';
            } catch (mysqli_sql_exception $e) {
                $conn->rollback();
                $response['message'] = 'Error adding like.';
            }
        } else {
            // User has already liked this post, so remove the like
            $conn->begin_transaction();
            try {
                // 1. Remove the record from the post_likes table
                $stmt = $conn->prepare("DELETE FROM post_likes WHERE post_id = ? AND username = ?");
                $stmt->bind_param("is", $postId, $currentUser);
                $stmt->execute();

                // 2. Decrement the likes count in the chat_messages table
                $stmt = $conn->prepare("UPDATE chat_messages SET likes = GREATEST(0, likes - 1) WHERE id = ?");
                $stmt->bind_param("i", $postId);
                $stmt->execute();

                $conn->commit();
                $response['success'] = true;
                $response['action'] = 'unliked';
                $response['message'] = 'Like removed successfully.';
            } catch (mysqli_sql_exception $e) {
                $conn->rollback();
                $response['message'] = 'Error removing like.';
            }
        }
    }

    header('Content-Type: application/json');
    echo json_encode($response);
    exit(); 
}

// Handle new post submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['post_title']) && isset($_POST['post_content'])) {
    $postTitle = trim($_POST['post_title']);
    $postContent = $_POST['post_content']; // No trim here to preserve rich text formatting

    // Filter profanity from post title and content
    $postTitle = filterProfanity($postTitle);
    $postContent = filterProfanity($postContent);
    
    if (!empty($postTitle) && !empty($postContent)) {
        // Insert new post into the chat_messages table
        $stmt = $conn->prepare("INSERT INTO chat_messages (username, display_name, message, is_admin, is_mentor, chat_type, title) VALUES (?, ?, ?, ?, ?, 'forum', ?)");
        $stmt->bind_param("sssibs", $currentUser, $displayName, $postContent, $isAdmin, $isMentor, $postTitle);
        $stmt->execute();
    }
    header("Location: group-chat.php");
    exit();
}

// Handle comment submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['comment_message']) && isset($_POST['post_id'])) {
    $commentMessage = trim($_POST['comment_message']);
    $postId = intval($_POST['post_id']);

    // Filter profanity from the comment
    $commentMessage = filterProfanity($commentMessage);

    if (!empty($commentMessage) && $postId > 0) {
        // Insert the comment into the chat_messages table, setting chat_type to 'comment' and linking it via forum_id
        $stmt = $conn->prepare("INSERT INTO chat_messages (username, display_name, message, is_admin, is_mentor, chat_type, forum_id) VALUES (?, ?, ?, ?, ?, 'comment', ?)");
        $stmt->bind_param("sssibi", $currentUser, $displayName, $commentMessage, $isAdmin, $isMentor, $postId);
        $stmt->execute();
    }
    header("Location: group-chat.php");
    exit();
}

// Fetch all posts from the chat_messages table
$posts = [];
$postsResult = $conn->query("SELECT * FROM chat_messages WHERE chat_type = 'forum' ORDER BY timestamp DESC");
if ($postsResult && $postsResult->num_rows > 0) {
    while ($row = $postsResult->fetch_assoc()) {
        $posts[] = $row;
    }
}

// Fetch comments for each post
foreach ($posts as &$post) {
    $comments = [];
    $commentsResult = $conn->query("SELECT * FROM chat_messages WHERE chat_type = 'comment' AND forum_id = " . $post['id'] . " ORDER BY timestamp ASC");
    if ($commentsResult && $commentsResult->num_rows > 0) {
        while ($row = $commentsResult->fetch_assoc()) {
            $comments[] = $row;
        }
    }
    $post['comments'] = $comments;
    
    // Check if the current user has liked this post for initial UI state
    $hasLiked = false;
    $likeCheckStmt = $conn->prepare("SELECT COUNT(*) FROM post_likes WHERE post_id = ? AND username = ?");
    $likeCheckStmt->bind_param("is", $post['id'], $currentUser);
    $likeCheckStmt->execute();
    $likeCheckStmt->bind_result($userLikesCount);
    $likeCheckStmt->fetch();
    $likeCheckStmt->close();
    if ($userLikesCount > 0) {
        $post['has_liked'] = true;
    } else {
        $post['has_liked'] = false;
    }
}

// Get mentee's name and icon for the nav bar
$username = $_SESSION['username'];
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


//Handle link
function makeLinksClickable($text) {
    // Regex to find URLs: starts with http://, https://, or www.
    $urlRegex = '/(https?:\/\/[^\s<]+|www\.[^\s<]+)/i';

    // Use preg_replace_callback to find all matches and apply a custom replacement
    return preg_replace_callback($urlRegex, function($matches) {
        $url = $matches[0];
        // Prepend http:// if the URL doesn't already have a protocol
        $protocol = (strpos($url, '://') === false) ? 'http://' : '';
        // Create the anchor tag and make sure to sanitize the URL again just in case
        return '<a href="' . htmlspecialchars($protocol . $url) . '" target="_blank">' . htmlspecialchars($url) . '</a>';
    }, $text); // <-- Pass the original $text here, not the escaped one.
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Group Chat - COACH</title>
    <link rel="icon" href="coachicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="css/mentee_navbarstyle.css" />
    <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
    <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" xintegrity="sha512-SnH5WK+bZxgPHs44uWIX+LLJAJ9/2PkPKZ5QiAj6Ta86w+fsb2TkcmfRyVX3pBnMFcV7oQPJkl9QevSCWr3W6A==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="stylesheet" href="css/group-chat.css"/>
</head>

<body>
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
          <li><a href="CoachMenteeActivities.php">Activities</a></li>
          <li><a href="forum-chat.php">Sessions</a></li>
          <li><a href="group-chat.php">Forums</a></li>
        </ul>
      </div>

      <div class="nav-profile">
  <a href="#" id="profile-icon">
    <?php if (!empty($menteeIcon)): ?>
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
        <?php if (!empty($menteeIcon)): ?>
          <img src="<?php echo htmlspecialchars($menteeIcon); ?>" alt="User Icon" style="width: 40px; height: 40px; border-radius: 50%;">
        <?php else: ?>
          <ion-icon name="person-circle-outline" style="font-size: 40px;"></ion-icon>
        <?php endif; ?>
      </div>
      <div class="user-name"><?php echo htmlspecialchars($firstName); ?></div>
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

    <div class="chat-container">
            <?php if (empty($posts)): ?>
            <p>No posts yet. Be the first to create one!</p>
        <?php else: ?>
            <?php foreach ($posts as $post): ?>
                <div class="post-container">
                    <div class="post-header">
                        <div class="post-title"><?php echo htmlspecialchars($post['title']); ?></div>
                        <div class="post-author">By: <?php echo htmlspecialchars($post['display_name']); ?></div>
                        <div class="post-date"><?php echo date("F j, Y", strtotime($post['timestamp'])); ?></div>
                    </div>
                    <div class="post-content">
                        <?php
                        // Make sure the variable here is $post['message']
                        $rawMessage = $post['message']; 
                        $allowedTags = '<b><i><u><a><p><ul><ol><li><br>';
                        $cleanMessage = strip_tags($rawMessage, $allowedTags);
                        $formattedMessage = makeLinksClickable($cleanMessage);

                        echo nl2br($formattedMessage);
                        ?>
                    </div>
                    <div class="post-actions">
                        <button class="action-btn like-btn <?php echo $post['has_liked'] ? 'liked' : ''; ?>" data-post-id="<?php echo htmlspecialchars($post['id']); ?>">
                            ❤️ <span class="like-count"><?php echo htmlspecialchars($post['likes']); ?></span>
                        </button>
                        <button class="action-btn" onclick="toggleCommentForm(this)">💬 Comment</button>
                    </div>
                    <form class="join-convo-form" style="display:none;" action="group-chat.php" method="POST">
                        <input type="hidden" name="post_id" value="<?php echo htmlspecialchars($post['id']); ?>">
                        <input type="text" name="comment_message" placeholder="Join the conversation" required>
                        <button type="submit">Post</button>
                    </form>
                    <div class="comment-section">
                        <?php foreach ($post['comments'] as $comment): ?>
                            <div class="comment">
                                <div class="comment-bubble">
                                    <strong><?php echo htmlspecialchars($comment['display_name']); ?></strong>
                                    <?php echo htmlspecialchars($comment['message']); ?>
                                </div>
                                <div class="comment-timestamp">
                                    <?php echo date("F j, Y, g:i a", strtotime($comment['timestamp'])); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

    </div>
        
    <button class="create-post-btn">+</button>

        <div class="modal-overlay">
        <div class="modal">
            <div class="modal-header">
            <h2>Create a post</h2>
            <button class="close-btn">&times;</button>
            </div>
            <form id="post-form" action="group-chat.php" method="POST">
                <input type="text" name="post_title" placeholder="Title" class="title-input">
                <div class="content-editor">
                    <div class="toolbar">
                        <button type="button" class="btn" data-element="bold">
                            <i class="fa fa-bold" aria-hidden="true"></i>
                        </button>
                        <button type="button" class="btn" data-element="italic">
                            <i class="fa fa-italic" aria-hidden="true"></i>
                        </button>
                        <button type="button" class="btn" data-element="underline">
                            <i class="fa fa-underline" aria-hidden="true"></i>
                        </button>
                        <button type="button" class="btn" data-element="insertUnorderedList">
                            <i class="fa fa-list-ul" aria-hidden="true"></i>
                        </button>
                        <button type="button" class="btn" data-element="insertOrderedList">
                            <i class="fa fa-list-ol" aria-hidden="true"></i>
                        </button>
                        <button type="button" class="btn" data-element="link">
                            <i class="fa fa-link" aria-hidden="true"></i>
                        </button>
                    </div>
                    <div class="text-content" contenteditable="true"></div>
                </div>
                <input type="hidden" name="post_content" id="post-content-input">
                <button type="submit" class="post-btn">Post</button>
            </form>
        </div>
    </div>

    <script src="mentee.js"></script>
  <script>
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
    //Comment
    function toggleCommentForm(btn) {
        const form = btn.closest('.post-container').querySelector('.join-convo-form');
        form.style.display = form.style.display === 'none' ? 'flex' : 'none';
    }
    //Post Modal
    const createPostBtn = document.querySelector('.create-post-btn');
    const modalOverlay = document.querySelector('.modal-overlay');
    const modal = document.querySelector('.modal');
    const closeBtn = document.querySelector('.close-btn');

    createPostBtn.addEventListener('click', () => {
        // Find the form elements within the modal
        const titleInput = document.querySelector('.title-input');
        const contentDiv = document.querySelector('.text-content');
        const hiddenContentInput = document.getElementById('post-content-input');

        // Reset the form fields to be empty
        if (titleInput) titleInput.value = '';
        if (contentDiv) contentDiv.innerHTML = '';
        if (hiddenContentInput) hiddenContentInput.value = '';

        // Now, display the modal
        modalOverlay.style.display = 'flex';
        modal.style.display = 'block';
    });

    closeBtn.addEventListener('click', () => {
        modalOverlay.style.display = 'none';
        modal.style.display = 'none';
    });

    modalOverlay.addEventListener('click', (e) => {
        if (e.target === modalOverlay) {
            modalOverlay.style.display = 'none';
            modal.style.display = 'none';
        }
    });

    //Text formatting
    const elements = document.querySelectorAll('.btn');

    elements.forEach(element => {
        element.addEventListener('click', () => {
            let command = element.dataset['element'];
            const textContentDiv = document.querySelector('.text-content');
            
            // Focus on the content-editable div to ensure the selection is active
            textContentDiv.focus();

            if (command === 'link') {
                let url = prompt('Enter the link here:', 'https://');
                if (url) {
                    const selection = window.getSelection();
                    if (selection.rangeCount > 0) {
                        const range = selection.getRangeAt(0);
                        const selectedText = range.toString();

                        if (selectedText) {
                            // Create the link element
                            const link = document.createElement('a');
                            link.href = url;
                            link.textContent = selectedText;
                            
                            // Replace the selected text with the new link element
                            range.deleteContents();
                            range.insertNode(link);
                        } else {
                            // If no text is selected, just insert the URL as plain text
                            const linkText = document.createTextNode(url);
                            range.insertNode(linkText);
                        }
                    }
                }
            } else if (command === 'bold' || command === 'italic' || command === 'underline' || command === 'insertUnorderedList' || command === 'insertOrderedList') {
                // For other commands, execCommand might still work for now, but should also be replaced eventually
                document.execCommand(command, false, null);
            } else {
                console.error('Unknown command:', command);
            }
        });
    });

    // Handle form submission to include rich text content
    const postForm = document.getElementById('post-form');
    const contentDiv = document.querySelector('.text-content');
    const contentInput = document.getElementById('post-content-input');
    postForm.addEventListener('submit', function(event) {
        event.preventDefault();
        contentInput.value = contentDiv.innerHTML;
        this.submit();
    });

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

        // Like/Unlike functionality
        document.querySelectorAll('.like-btn').forEach(button => {
            button.addEventListener('click', function() {
                const postId = this.getAttribute('data-post-id');
                const likeCountElement = this.querySelector('.like-count');
                const hasLiked = this.classList.contains('liked');

                let action = hasLiked ? 'unlike_post' : 'like_post';

                fetch('group-chat.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
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
                    } else {
                        console.error(data.message);
                    }
                })
                .catch(error => {
                    console.error('Error handling like:', error);
                });
            });
        });
    });

    </script>
</body>
</html>
