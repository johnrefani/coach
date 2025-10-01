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

// --- FETCH USER DETAILS FROM 'users' TABLE ---
$currentUsername = $_SESSION['username'];
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
    // Ensure the user is an 'Admin' or 'Super Admin'
    if ($user['user_type'] !== 'Admin' && $user['user_type'] !== 'Super Admin') {
        // If not an admin, redirect to an unauthorized page or log them out
        header("Location: ../login.php");
        exit();
    }

    // Set session variables for display
    $_SESSION['user_id'] = $user['user_id'];
    $_SESSION['admin_name'] = trim($user['first_name'] . ' ' . $user['last_name']);
    $_SESSION['admin_icon'] = !empty($user['icon']) ? $user['icon'] : "../uploads/img/default_pfp.png";

} else {
    // User in session not found in DB, destroy session and redirect to login
    session_destroy();
    header("Location: ../login.php");
    exit();
}
$stmtUser->close();


// --- FETCH ALL FEEDBACK RECORDS ---
// The 'feedback' table structure is unchanged, so this query remains the same.
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
     <link rel="stylesheet" href="css/dashboard.css" />
    <link rel="icon" href="../uploads/img/coachicon.svg" type="image/svg+xml">
    <title>Feedback | Superadmin</title>
    <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
    <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>

    <style>
/* ========================================
COLORS (Based on the image)
========================================
*/

:root {
   
    --accent-color: #995BCC; /* Lighter Purple/Active Link */
    --text-color: #333;
    --body-bg: #F7F7F7;
    --table-row-hover: #F0F0F0;
    --action-btn-color: #4CAF50; /* Green */
    --detail-view-bg: white;
    --header-color: #444;
    --nav-icon-color: white;
}



body {
    background-color: var(--body-bg);
    display: flex;
     overflow-x: hidden;
}

a {
    text-decoration: none;
    color: inherit;
}

header h1 {
            color: #562b63;

            font-size: 28px;
            margin-top: 50px;
        }

.logo {
    display: flex;
    align-items: center;
    margin-bottom: 20px;
}

.logo-image img {
    width: 30px; 
    margin-right: 10px;
}

.logo-name {
    font-size: 1.5rem;
    font-weight: 700;
}


.edit-profile-link {
    margin-left: auto;
    color: var(--nav-icon-color);
}

.menu-items {
    display: flex;
    flex-direction: column;
    flex-grow: 1;
}


.bottom-link {
    padding-top: 5px;
    border-top: 1px solid rgba(255, 255, 255, 0.1);
}

/* ========================================
    MAIN CONTENT (DASHBOARD)
    ======================================== */
.dashboard {
  
    width: calc(100% - 250px);
    padding: 20px;
}

.dashboard .top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    /* Ensure it is a block element and takes full width */
    width: 100%;
    /* Add a clear margin/padding to separate it from the title */
    padding-bottom: 10px; 
}

.dashboard .top img {
    width: 40px;
}

.dashboard h1 {
    font-size: 2em;
    color: var(--header-color);
    margin-bottom: 20px;
    /* Add padding to the top of the content area to prevent overlap with the top bar/menu button */
    padding-top: 10px; 
}

/* ========================================
    TABLE STYLES
    ======================================== */

#tableContainer {
    background-color: white;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    border-radius: 5px;
    overflow-x: auto;
    
}

#tableContainer table {
    table-layout: fixed;
    width: 100%; /* or a fixed pixel value */
}


#tableContainer thead {
    background-color: var(--sidebar-bg-color); /* Dark purple header */
    color: white;
}

#tableContainer th {
    padding: 12px 15px;
    text-align: left;
    font-weight: 600;
    background-color: #562b63;
}

#tableContainer td {
    padding: 12px 15px;
    border-bottom: 1px solid #eee;
    color: var(--text-color);
      text-align: center; 
}

#tableContainer tbody tr:last-child td {
    border-bottom: none;
}

#tableContainer tbody tr:hover {
    background-color: var(--table-row-hover);
}

.view-btn {
    background-color: var(--action-btn-color); /* Green button */
    color: white;
    border: none;
    padding: 8px 12px;
    border-radius: 5px;
    cursor: pointer;
    font-weight: 500;
    transition: background-color 0.2s;
}

.view-btn:hover {
    background-color: #449D48;
}

/* ========================================
    DETAIL VIEW STYLES
    ======================================== */
#detailView {
    padding: 20px;
    max-width: 700px;
    margin: 0 auto;
}

.form-container {
    background-color: var(--detail-view-bg);
    padding: 30px;
    border-radius: 8px;
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}

.form-container h2 {
    color: var(--sidebar-bg-color);
    margin-top: 0;
    margin-bottom: 20px;
    border-bottom: 2px solid #eee;
    padding-bottom: 10px;
}

.form-group {
    margin-bottom: 15px;
}

.form-group label {
    display: block;
    font-weight: 600;
    margin-bottom: 5px;
    color: var(--sidebar-bg-color);
}

.form-group input[type="text"],
.form-group textarea {
    width: 100%;
    padding: 10px;
    border: 1px solid #ccc;
    border-radius: 4px;
    background-color: #f9f9f9;
    font-size: 1em;
}

.form-group textarea {
    resize: vertical;
}

.form-buttons {
    display: flex;
    justify-content: flex-start;
    margin-bottom: 20px; /* Space between buttons and form fields */
}

.cancel-btn {
    background-color: #6c757d; /* Gray color for back/cancel */
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: 5px;
    cursor: pointer;
    font-weight: 500;
    transition: background-color 0.2s;
}

.cancel-btn:hover {
    background-color: #5a6268;
}

nav {
    /* Assuming a fixed nav with full height, typically set here */
    display: flex; /* Ensures content is laid out vertically */
    flex-direction: column; 
    height: 100vh; /* Full viewport height */
    /* ... other nav styles ... */
}

.menu-items {
    /* This makes the main link area take all remaining space */
    flex-grow: 1; /* Already present in feedbacks.php */
    
    /* ADD these two properties: */
    overflow-y: auto; /* Adds a scrollbar when content is too long */
    display: flex; /* Make it a flex container */
    flex-direction: column; /* Stack the nav links and bottom links vertically */
    justify-content: space-between; /* Pushes the bottom-link to the bottom */
}

.navLinks {
    /* ADD: This allows the main links to take up space and push the bottom link down */
    margin-bottom: auto; 
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
                        <td colspan="6">No feedback records found.</td>
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
                    <button type="button" onclick="goBack()" class="cancel-btn">Back</button>
                </div>

                <div class="form-group"><label>Feedback ID:</label><input type="text" id="feedback_id" readonly></div>
                <div class="form-group"><label>Session:</label><input type="text" id="session" readonly></div>
                <div class="form-group"><label>Forum ID:</label><input type="text" id="forum_id" readonly></div>
                <div class="form-group"><label>Session Date:</label><input type="text" id="session_date" readonly></div>
                <div class="form-group"><label>Time Slot:</label><input type="text" id="time_slot_detail" readonly></div>
                <div class="form-group"><label>Session Mentor:</label><input type="text" id="session_mentor" readonly></div>
                 <div class="form-group"><label>Mentee Username (from DB):</label><input type="text" id="mentee_from_db" readonly></div>
                <div class="form-group"><label>Mentee Experience:</label><textarea id="mentee_experience" rows="4" readonly></textarea></div>
                <div class="form-group"><label>Experience Star Rating:</label><input type="text" id="experience_star_detail" readonly></div>
                <div class="form-group"><label>Mentor Reviews:</label><textarea id="mentor_reviews" rows="4" readonly></textarea></div>
                <div class="form-group"><label>Mentor Star Rating:</label><input type="text" id="mentor_star_detail" readonly></div>

            </form>
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
            document.getElementById('mentee_from_db').value = data.Mentee || 'N/A'; // Show N/A if empty
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

        function confirmLogout() {
            if (confirm("Are you sure you want to log out?")) {
                window.location.href = "../login.php";
            }
        }
    </script>

</section>

</body>
</html>

<?php
// Close the database connection at the very end of the script
$conn->close();
?>
