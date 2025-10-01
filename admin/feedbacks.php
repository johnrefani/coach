<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();


// Standard session check for a Super Admin user.
// ADDED CHECK: Ensure 'username' session variable is also set.
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'Super Admin' || !isset($_SESSION['username'])) {
    header("Location: ../login.php");
    exit();
}

// Use your standard database connection
require '../connection/db_connection.php';

// --- FETCH USER DETAILS FROM 'users' TABLE ---
$currentUsername = $_SESSION['username'];

// Secondary check for empty username (in case the session variable was set but empty)
if (empty($currentUsername)) {
    session_destroy();
    header("Location: ../login.php");
    exit();
}

$sqlUser = "SELECT user_id, first_name, last_name, icon, user_type FROM users WHERE username = ?";
$stmtUser = $conn->prepare($sqlUser);

if ($stmtUser === false) {
    // Handle error - unable to prepare statement
    die("Error preparing statement: " . $conn->error);
} 

$stmtUser->bind_param("s", $currentUsername);
$stmtUser->execute();
$resultUser = $stmtUser->get_result();

if ($resultUser->num_rows === 1) {
    $user = $resultUser->fetch_assoc();
    
    // --- AUTHORIZATION CHECK ---
    // We keep this check, but it should only redirect, not destroy the session unless user_type is the only issue.
    if ($user['user_type'] !== 'Admin' && $user['user_type'] !== 'Super Admin') {
        // If the user's role changed to non-admin/super-admin since login, destroy session
        session_destroy();
        header("Location: ../login.php");
        exit();
    }

    // Define profile display variables based on the fetched data
    $superadmin_icon = !empty($user['icon']) ? $user['icon'] : "../uploads/img/default_pfp.png";
    $superadmin_name = trim($user['first_name'] . ' ' . $user['last_name']);
    $user_type = $user['user_type']; // Store the actual type
    
} else {
    // If the database query failed to find the user (e.g., user was deleted mid-session),
    // we must destroy the session and redirect. This block is correct.
    session_destroy();
    header("Location: ../login.php");
    exit();
}
$stmtUser->close();


// --- FETCH ALL FEEDBACK RECORDS ---
$queryFeedback = "SELECT * FROM feedback ORDER BY feedback_id DESC";
$result = $conn->query($queryFeedback);

// Check if the query failed
if ($result === false) {
    die("Error fetching feedback records: " . $conn->error);
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="../uploads/img/coachicon.svg" type="image/svg+xml">
    <title>Feedback | SuperAdmin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* General Layout */
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f4f4f4;
            display: flex; 
            min-height: 100vh;
        }

        /* Sidebar/Navbar Styles (Copied from Super Admin File for consistent design) */
        nav {
            width: 250px;
            background-color: #562b63; /* Deep Purple */
            color: #e0e0e0;
            padding: 20px 0;
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.5);
            display: flex;
            flex-direction: column;
            overflow-y: auto;
            position: fixed;
            height: 100%;
            transition: all 0.3s ease;
        }
        nav.close {
            width: 70px; /* Collapsed width */
        }
        .nav-top {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 0 20px;
        }
        .logo {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
            color: #fff;
            font-size: 24px;
            font-weight: bold;
            text-decoration: none;
            width: 100%;
        }
        .logo-image img {
            width: 40px;
            height: 40px;
            margin-right: 10px;
            object-fit: contain;
        }
        nav.close .logo-name {
            display: none;
        }
        .admin-profile {
            text-align: center;
            padding: 15px 0;
            border-top: 1px solid #7a4a87;
            border-bottom: 1px solid #7a4a87;
            margin-bottom: 30px;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .admin-profile img {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #00bcd4;
            margin-right: 10px;
        }
        .admin-text {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
        }
        .admin-name {
            font-weight: 500;
            color: #fff;
        }
        .admin-role {
            font-size: 0.8em;
            color: #ccc;
        }
        nav.close .admin-text, nav.close .edit-profile-link {
            display: none;
        }
        .edit-profile-link {
            color: #fff;
            margin-left: 10px;
            font-size: 1.2em;
        }
        
        .menu-items {
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            overflow-y: auto;
        }
        .navLinks {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .navLinks li a {
            display: flex;
            align-items: center;
            color: #e0e0e0;
            text-decoration: none;
            padding: 12px 20px; 
            margin: 5px 0;
            transition: background-color 0.2s, border-left-color 0.2s;
            border-left: 5px solid transparent; 
        }
        .navLinks li a ion-icon {
            margin-right: 12px;
            font-size: 20px;
            min-width: 25px;
        }
        .navLinks li a:hover {
            background-color: #7a4a87; 
            color: #fff;
        }
        .navLinks li.active a {
             background-color: #7a4a87;
            border-left: 5px solid #00bcd4; 
            color: #00bcd4; 
        }
        nav.close .links {
            display: none;
        }
        
        .bottom-link {
            list-style: none;
            padding: 0;
            margin: 0;
            margin-top: auto;
            border-top: 1px solid #7a4a87;
        }
        .logout-link a {
            color: #f8d7da !important;
        }
        .logout-link a:hover {
            background-color: #dc3545;
        }
        
        /* Dashboard/Main Content Area */
        .dashboard {
            flex-grow: 1;
            margin-left: 250px; /* Initial offset for fixed sidebar */
            transition: margin-left 0.3s ease;
            width: calc(100% - 250px);
        }
        nav.close ~ .dashboard {
            margin-left: 70px;
            width: calc(100% - 70px);
        }
        
        .top {
            display: flex;
            align-items: center;
            justify-content: flex-start;
            padding: 10px 30px;
            background-color: #fff;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        .navToggle {
            font-size: 28px;
            color: #562b63;
            cursor: pointer;
            margin-right: 20px;
        }
        .top img {
            height: 30px;
        }
        
        .main-content {
            padding: 20px 30px;
        }
        
        h1 {
            color: #562b63;
            margin: 0;
            font-size: 28px;
            border-bottom: 2px solid #562b63;
            padding-bottom: 10px;
            margin-bottom: 20px;
            margin-top: 30px;
        }
        
        /* Table Styles */
        #tableContainer {
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.05);
            background-color: #fff;
            margin-top: 20px;
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
        tr:hover:not(.no-data) {
            background-color: #f1f1f1;
        }
        .view-btn {
            background-color: #00bcd4;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 5px;
            cursor: pointer;
            transition: background-color 0.3s;
            font-weight: 600;
        }
        .view-btn:hover {
            background-color: #0097a7;
        }
        
        /* Detail View Styles (Form Container) */
        #detailView {
            background-color: #fff;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            max-width: 800px;
            margin: 20px auto;
        }
        #detailView h2 {
            color: #562b63;
            border-bottom: 2px solid #ccc;
            padding-bottom: 10px;
            margin-top: 0;
            margin-bottom: 20px;
        }
        .form-group {
            display: flex;
            align-items: flex-start;
            margin-bottom: 15px;
        }
        .form-group label {
            font-weight: 600;
            color: #333;
            flex-basis: 200px; /* Fixed width for labels */
            padding-top: 8px;
        }
        .form-group input[type="text"], .form-group textarea {
            flex-grow: 1;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            background-color: #f9f9f9;
            box-sizing: border-box;
            font-family: inherit;
            font-size: 14px;
        }
        .form-group textarea {
            resize: vertical;
        }
        .form-group input[readonly], .form-group textarea[readonly] {
            cursor: default;
            background-color: #eee;
            color: #555;
        }
        .form-buttons {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 20px;
        }
        .cancel-btn {
            background-color: #6c757d;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
            transition: background-color 0.3s;
        }
        .cancel-btn:hover {
            background-color: #5a6268;
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
        <img src="<?php echo htmlspecialchars($superadmin_icon); ?>" alt="SuperAdmin Profile Picture" />
        <div class="admin-text">
          <span class="admin-name"><?php echo htmlspecialchars($superadmin_name); ?></span>
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
        <li class="navList active"> 
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
        <img src="../uploads/img/logo.png" alt="Logo">
    </div>

    <div class="main-content">
        <h1>Manage Feedback</h1>

        <div id="tableContainer">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Session Mentor</th>
                        <th>Time Slot</th>
                        <th>Mentee Star</th>
                        <th>Mentor Star</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result->num_rows > 0): ?>
                        <?php while($row = $result->fetch_assoc()): ?>
                            <tr class="data-row">
                                <td><?= htmlspecialchars($row['Feedback_ID']) ?></td>
                                <td><?= htmlspecialchars($row['Session_Mentor']) ?></td>
                                <td class="time-slot"><?= htmlspecialchars($row['Time_Slot']) ?></td>
                                <td class="mentee-star"><?= htmlspecialchars($row['Experience_Star']) ?>⭐</td>
                                <td class="mentor-star"><?= htmlspecialchars($row['Mentor_Star']) ?>⭐</td>
                                <td>
                                    <button class="view-btn" onclick='viewFeedback(this)' data-info='<?= json_encode($row, JSON_HEX_APOS | JSON_HEX_QUOT) ?>'>View</button>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" style="text-align: center;">No feedback records found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div id="detailView" style="display: none;">
            <div id="feedbackDetails" class="form-container">
                <h2>View Feedback Details</h2>
                <form id="feedbackForm">
                    <div class="form-buttons">
                        <button type="button" onclick="goBack()" class="cancel-btn"><i class="fas fa-arrow-left"></i> Back</button>
                    </div>

                    <div class="form-group"><label>Feedback ID:</label><input type="text" id="feedback_id" readonly></div>
                    <div class="form-group"><label>Session:</label><input type="text" id="session" readonly></div>
                    <div class="form-group"><label>Forum ID:</label><input type="text" id="forum_id" readonly></div>
                    <div class="form-group"><label>Session Date:</label><input type="text" id="session_date" readonly></div>
                    <div class="form-group"><label>Time Slot:</label><input type="text" id="time_slot_detail" readonly></div>
                    <div class="form-group"><label>Session Mentor:</label><input type="text" id="session_mentor" readonly></div>
                    <div class="form-group"><label>Mentee Username (from DB):</label><input type="text" id="mentee_from_db" readonly></div>
                    <div class="form-group"><label>Experience Star Rating:</label><input type="text" id="experience_star_detail" readonly></div>
                    <div class="form-group"><label>Mentee Experience:</label><textarea id="mentee_experience" rows="4" readonly></textarea></div>
                    <div class="form-group"><label>Mentor Star Rating:</label><input type="text" id="mentor_star_detail" readonly></div>
                    <div class="form-group"><label>Mentor Reviews:</label><textarea id="mentor_reviews" rows="4" readonly></textarea></div>

                </form>
            </div>
        </div>
    </div>

    <script>
        function viewFeedback(button) {
            const data = JSON.parse(button.getAttribute('data-info'));

            document.getElementById('feedback_id').value = data.Feedback_ID || '';
            document.getElementById('session').value = data.Session || '';
            document.getElementById('forum_id').value = data.Forum_ID || '';
            document.getElementById('session_date').value = data.Session_Date || '';
            document.getElementById('time_slot_detail').value = data.Time_Slot || '';
            document.getElementById('session_mentor').value = data.Session_Mentor || '';
            document.getElementById('mentee_from_db').value = data.Mentee || 'N/A';
            document.getElementById('mentee_experience').value = data.Mentee_Experience || '';
            document.getElementById('experience_star_detail').value = (data.Experience_Star || '0') + '⭐';
            document.getElementById('mentor_reviews').value = data.Mentor_Reviews || '';
            document.getElementById('mentor_star_detail').value = (data.Mentor_Star || '0') + '⭐';

            document.querySelectorAll('#feedbackDetails input, #feedbackDetails textarea').forEach(el => {
                el.setAttribute('readonly', true);
            });

            document.getElementById('tableContainer').style.display = 'none';
            document.getElementById('detailView').style.display = 'block';
        }

        function goBack() {
            document.getElementById('detailView').style.display = 'none';
            document.getElementById('tableContainer').style.display = 'block';
        }
        
        // Toggle sidebar
        document.querySelector('.navToggle').addEventListener('click', function() {
            document.querySelector('nav').classList.toggle('close');
            document.querySelector('.dashboard').classList.toggle('close');
        });

        function confirmLogout() {
            if (confirm("Are you sure you want to log out?")) {
                window.location.href = "../login.php";
            }
        }
    </script>
    <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
    <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
</section>

</body>
</html>

<?php
// Close the database connection at the very end of the script
$conn->close();
?>