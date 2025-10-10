<?php
session_start();

// Standard session check for an admin user
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'Super Admin') {
    header("Location: ../login.php");
    exit();
}

// Use your standard database connection
require '../connection/db_connection.php';

// --- ADMIN ACTION HANDLER: UNBAN USER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $adminAction = $_POST['admin_action'] ?? '';
    if ($adminAction === 'unban_user' && isset($_POST['username_to_unban'])) {
        $usernameToUnban = $_POST['username_to_unban'];
        $stmt = $conn->prepare("DELETE FROM banned_users WHERE username = ?");
        $stmt->bind_param("s", $usernameToUnban);
        $stmt->execute();
        header("Location: banned-users.php"); // Refresh the page to see the change
        exit();
    }
}

// --- DATA FETCHING: GET ALL BANNED USERS ---
$banned_users = [];
// NOTE: I'm selecting all columns from your table to ensure all data is available.
// 'ban_until' will be used for the ban date, and new columns are 'ban_type' and 'ban_duration_text'.
$bannedQuery = "SELECT ban_id, username, banned_by_admin, reason, unban_datetime, ban_duration_text, ban_type, ban_until FROM banned_users ORDER BY ban_until DESC";
$bannedResult = $conn->query($bannedQuery);
if ($bannedResult) {
    while ($row = $bannedResult->fetch_assoc()) {
        $banned_users[] = $row;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Banned Users | SuperAdmin</title>
     <link rel="icon" href="../uploads/img/coachicon.svg" type="image/svg+xml">
    <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
    <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
    <link rel="stylesheet" href="css/banned-users.css"/>
    <link rel="stylesheet" href="css/dashboard.css"/>
    <link rel="stylesheet" href="css/navigation.css"/>
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
        <li class="navList">
            <a href="reports.php"><ion-icon name="folder-outline"></ion-icon>
              <span class="links">Reported Posts</span>
            </a>
        </li>
        <li class="navList active">
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
        <img src="../uploads/img/logo.png" alt="Logo"> </div>

    <div class="admin-container">
        <div class="admin-controls-header">
            <h2>Banned Users List</h2>
        </div>

        <?php if (empty($banned_users)): ?>
            <p>No users are currently banned.</p>
        <?php else: ?>
            <table class="banned-users-table">
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Reason</th>
                        <th>Banned By</th>
                        <th>Type</th> 
                        <th>Duration</th> 
                        <th>Ban Until</th> 
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($banned_users as $user): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($user['username']); ?></td>
                            <td><?php echo htmlspecialchars($user['reason'] ?: 'No reason provided'); ?></td>
                            <td><?php echo htmlspecialchars($user['banned_by_admin']); ?></td>
                            <td><?php echo htmlspecialchars($user['ban_type'] ?: 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($user['ban_duration_text'] ?: 'Indefinite'); ?></td>
                            <td>
                                <?php 
                                    if ($user['ban_until'] === NULL) {
                                        echo 'Permanent';
                                    } else {
                                        echo date("F j, Y, g:i a", strtotime($user['ban_until']));
                                    }
                                ?>
                            </td>
                            <td>
                                <form action="banned-users.php" method="POST" onsubmit="return confirm('Are you sure you want to unban this user?');">
                                    <input type="hidden" name="username_to_unban" value="<?php echo htmlspecialchars($user['username']); ?>">
                                    <input type="hidden" name="admin_action" value="unban_user">
                                    <button type="submit" class="unban-btn">
                                        <i class="fa fa-unlock"></i> Unban
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <script src="js/navigation.js"></script>
<script>

</script>
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