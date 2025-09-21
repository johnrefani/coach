<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

// Standard session check for an admin user
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'Super Admin') {
    header("Location: ../login.php");
    exit();
}

// Use your standard database connection
require '../connection/db_connection.php';

// --- INITIALIZE VARIABLES ---
$currentUser = $_SESSION['username'];
$reports = [];
$adminAction = $_POST['admin_action'] ?? '';
$redirect = false;

// --- ADMIN ACTION HANDLERS FOR REPORTS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Action 1: Dismiss a report
    if ($adminAction === 'dismiss_report' && isset($_POST['report_id'])) {
        $reportId = intval($_POST['report_id']);
        $stmt = $conn->prepare("UPDATE reports SET status = 'resolved' WHERE report_id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $reportId);
            $stmt->execute();
            $stmt->close();
            $redirect = true;
        }
    }


        // Action 2: Delete the post AND dismiss the report
    if ($adminAction === 'delete_and_dismiss' && isset($_POST['post_id'], $_POST['report_id'])) { // Renamed action
        $postId = intval($_POST['post_id']);
        $reportId = intval($_POST['report_id']);
        
        $conn->begin_transaction();
        try {
            // This is the main change: from UPDATE to DELETE
            $stmt1 = $conn->prepare("DELETE FROM chat_messages WHERE id = ?");
            if ($stmt1) {
                $stmt1->bind_param("i", $postId);
                $stmt1->execute();
                $stmt1->close();
            }

            // This part remains the same, to resolve the report
            $stmt2 = $conn->prepare("UPDATE reports SET status = 'resolved' WHERE report_id = ?");
            if ($stmt2) {
                $stmt2->bind_param("i", $reportId);
                $stmt2->execute();
                $stmt2->close();
            }
            
            $conn->commit();
            $redirect = true;
        } catch (mysqli_sql_exception $exception) {
            $conn->rollback();
            error_log("Transaction failed: " . $exception->getMessage());
        }
    }

    // Action 3: Ban the user
    if ($adminAction === 'ban_user' && isset($_POST['username_to_ban'])) {
        $usernameToBan = trim($_POST['username_to_ban']);
        $banReason = trim($_POST['ban_reason'] ?? 'Violation found in reported post.');
        
        $check_stmt = $conn->prepare("SELECT ban_id FROM banned_users WHERE username = ?");
        if ($check_stmt) {
            $check_stmt->bind_param("s", $usernameToBan);
            $check_stmt->execute();
            if ($check_stmt->get_result()->num_rows == 0) {
                $stmt = $conn->prepare("INSERT INTO banned_users (username, banned_by_admin, reason) VALUES (?, ?, ?)");
                if ($stmt) {
                    $stmt->bind_param("sss", $usernameToBan, $currentUser, $banReason);
                    $stmt->execute();
                    $stmt->close();
                }
            }
            $check_stmt->close();
            $redirect = true;
        }
    }

    if ($redirect) {
        header("Location: reports.php");
        exit();
    }
}

// --- DATA FETCHING: Get all PENDING reports and the associated post content ---
$reportQuery = "SELECT
                    r.report_id, r.reported_by_username, r.reason AS report_reason, r.report_date,
                    c.id AS post_id, 
                    u.username AS post_author_username, 
                    c.display_name AS post_author_displayname, 
                    r.title, c.message, CONCAT('mentee/', c.file_path) AS file_path, c.user_icon
                FROM reports AS r
                JOIN chat_messages AS c ON r.post_id = c.id
                JOIN users AS u ON c.user_id = u.user_id
                WHERE r.status = 'pending'
                ORDER BY r.report_date DESC";

$stmt = $conn->prepare($reportQuery);
if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $reports[] = $row;
        }
    }
    $stmt->close();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Reported Content</title>
    <link rel="icon" href="../coachicon.svg" type="image/svg+xml"> 
    <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
    <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
    <link rel="stylesheet" href="css/report.css"/>
    <link rel="stylesheet" href="css/dashboard.css"/>

</head>
<body>

    <nav>
    <div class="nav-top">
      <div class="logo">
        <div class="logo-image"><img src="../uploads/img/logo.png" alt="Logo"></div>
        <div class="logo-name">COACH</div>
      </div>

      <div class="admin-profile">
        <img src="<?php echo htmlspecialchars($_SESSION['superadmin_icon']); ?>" alt="SuperAdmin Profile Picture" />
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
        <li class="navList">
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
        <li class="navList active">
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
    <a href="#" onclick="confirmLogout()">
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
        <img src="../uploads/img/logo.png" alt="Logo"> </div>



    <div class="admin-container">
        <div class="admin-controls-header">
            <h2>Pending Reports</h2>
        </div>

        <?php if (empty($reports)): ?>
            <p>There are no pending reports to review. Good job!</p>
        <?php else: ?>
            <?php foreach ($reports as $report): ?>
                <div class="report-card">
                    <div class="report-info">
                        <p><strong>Reported By:</strong> <?php echo htmlspecialchars($report['reported_by_username']); ?></p>
                        <p><strong>Date:</strong> <?php echo date("F j, Y, g:i a", strtotime($report['report_date'])); ?></p>
                        <p><strong>Reason:</strong> <span class="report-reason"><?php echo htmlspecialchars($report['report_reason']); ?></span></p>
                    </div>

                    <div class="reported-content-wrapper">
                        <strong>Content in Question:</strong>
                        <div class="post-container">
                            <div class="post-header">
                                <img src="<?php echo htmlspecialchars(!empty($report['user_icon']) ? $report['user_icon'] : '../img/default-user.png'); ?>" alt="Author Icon" class="user-avatar">
                                <div class="post-author-details">
                                    <div class="post-author"><?php echo htmlspecialchars($report['post_author_displayname']); ?></div>
                                </div>
                            </div>
                            <?php if (!empty($report['title'])): ?>
                                <div class="post-title"><?php echo htmlspecialchars($report['title']); ?></div>
                            <?php endif; ?>
                            <div class="post-content">
                                <?php echo htmlspecialchars($report['message']); ?>
                                <br>
                                <?php if (!empty($report['file_path'])): ?>
                                    <img src="<?php echo '../' . htmlspecialchars($report['file_path']); ?>" alt="Post Image">
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="report-actions">
                        <form action="reports.php" method="POST" onsubmit="return confirm('Are you sure you want to dismiss this report?');" style="display:inline;">
                            <input type="hidden" name="report_id" value="<?php echo htmlspecialchars($report['report_id']); ?>">
                            <input type="hidden" name="admin_action" value="dismiss_report">
                            <button type="submit" class="action-btn dismiss"><i class="fa fa-check"></i> Dismiss Report</button>
                        </form>
                        <form action="reports.php" method="POST" onsubmit="return confirm('This will PERMANENTLY DELETE the post and dismiss the report. This action cannot be undone. Are you sure?');" style="display:inline;">
                            <input type="hidden" name="report_id" value="<?php echo htmlspecialchars($report['report_id']); ?>">
                            <input type="hidden" name="post_id" value="<?php echo htmlspecialchars($report['post_id']); ?>">
                            <input type="hidden" name="admin_action" value="delete_and_dismiss">
                            <button type="submit" class="action-btn archive"><i class="fa fa-trash"></i> Delete Post</button>
                        </form>
                        <button class="action-btn ban" onclick="openBanModal('<?php echo htmlspecialchars($report['post_author_username']); ?>')">
                            <i class="fa fa-ban"></i> Ban User
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    
    <div class="modal-overlay" id="ban-modal-overlay" style="display:none;">
        <div class="modal">
            <div class="modal-header">
                <h2>Ban User</h2>
                <button class="close-btn" onclick="closeBanModal()">&times;</button>
            </div>
            <form action="reports.php" method="POST">
                <input type="hidden" name="admin_action" value="ban_user">
                <input type="hidden" id="ban-username" name="username_to_ban" value="">
                <p>You are about to ban <strong id="ban-username-display"></strong>.</p>
                <label for="ban_reason">Reason for ban (defaults to post violation):</label>
                <textarea id="ban_reason" name="ban_reason" class="ban-modal-reason" rows="3"></textarea>
                <button type="submit" class="post-btn" style="background-color: #d9534f;">Confirm Ban</button>
            </form>
        </div>
    </div>
</section>
<script>

    // Nav Toggle
    const navBar = document.querySelector("nav");
    const navToggle = document.querySelector(".navToggle");
    if(navToggle) {
        navToggle.addEventListener('click', () => navBar.classList.toggle('close'));
    }

    function openBanModal(username) {
        document.getElementById('ban-username').value = username;
        document.getElementById('ban-username-display').innerText = username;
        document.getElementById('ban-modal-overlay').style.display = 'flex';
    }
    function closeBanModal() {
        document.getElementById('ban-modal-overlay').style.display = 'none';
    }
    
  function confirmLogout() {
    if (confirm("Are you sure you want to log out?")) {
      window.location.href = "../logout.php";
    }
  }

</script>

</body>
</html>