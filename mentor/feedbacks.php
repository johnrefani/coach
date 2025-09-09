<?php
session_start(); 

require '../connection/db_connection.php';

// Check for a valid database connection immediately after inclusion
if ($conn->connect_error) {
    // Log the error for the administrator, but don't show details to the user.
    error_log("Database connection failed: " . $conn->connect_error);
    // Display a generic error message and stop the script.
    die("A database connection error occurred. Please try again later.");
}

// SESSION CHECK: Verify user is logged in and is a Mentor
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'Mentor') {
    header("Location: ../login.php"); 
    exit();
}

// --- MODIFICATION STARTS HERE (WITH FIXES) ---

$mentor_id = $_SESSION['user_id'];
$mentor_username = $_SESSION['username'];

// Fetch current Mentor's details from the `users` table to ensure session data is accurate
$user_sql = "SELECT CONCAT(first_name, ' ', last_name) AS Mentor_Name, icon FROM users WHERE user_id = ?";
$stmt = $conn->prepare($user_sql);

// **FIX ADDED**: Check if the prepare statement failed for the user query
if ($stmt === false) {
    error_log("Error preparing user details statement: " . $conn->error);
    die("An error occurred while fetching user data. Please contact support.");
}

$stmt->bind_param("i", $mentor_id);
$stmt->execute();
$user_result = $stmt->get_result();

if ($user_result->num_rows === 1) {
    $user_row = $user_result->fetch_assoc();
    $_SESSION['mentor_name'] = $user_row['Mentor_Name'];
    $_SESSION['mentor_icon'] = (!empty($user_row['icon'])) ? $user_row['icon'] : "../uploads/img/default_pfp.png";
} else {
    // Fallback if mentor details are not found
    $_SESSION['mentor_name'] = "Unknown Mentor";
    $_SESSION['mentor_icon'] = "../uploads/img/default_pfp.png";
}
$stmt->close();

// Get the logged-in mentor's name from the session
$loggedInMentorName = $_SESSION['mentor_name'];

// Prepare the SQL query to fetch feedback records ONLY for the logged-in mentor
$query = "SELECT * FROM Feedback WHERE Session_Mentor = ?";
$stmt = $conn->prepare($query);

// This error check was already correctly in place
if ($stmt === false) {
    error_log("Error preparing feedback statement: " . $conn->error);
    die("Error preparing statement: " . $conn->error);
}

// Bind the mentor's name parameter and execute
$stmt->bind_param("s", $loggedInMentorName);
$stmt->execute();
$result = $stmt->get_result();
$stmt->close();

// --- MODIFICATION ENDS HERE ---
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/dashboard.css" />
    <link rel="stylesheet" href="css/mentees.css">
    <link rel="icon" href="../uploads/coachicon.svg" type="image/svg+xml">
    <title>Manage Feedback</title>
</head>
<body>

<nav>
  <div class="nav-top">
    <div class="logo">
      <div class="logo-image"><img src="../uploads/img/logo.png" alt="Logo"></div>
      <div class="logo-name">COACH</div>
    </div>

    <div class="admin-profile">
      <img src="<?php echo htmlspecialchars($_SESSION['mentor_icon']); ?>" alt="Mentor Profile Picture" />
      <div class="admin-text">
        <span class="admin-name">
          <?php echo htmlspecialchars($_SESSION['mentor_name']); ?>
        </span>
        <span class="admin-role">Mentor</span>
      </div>
      <a href="edit_profile.php?username=<?= urlencode($_SESSION['username']) ?>" class="edit-profile-link" title="Edit Profile">
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
        <a href="courses.php">
          <ion-icon name="book-outline"></ion-icon>
          <span class="links">Course</span>
        </a>
      </li>
      <li class="navList">
        <a href="sessions.php">
          <ion-icon name="calendar-outline"></ion-icon>
          <span class="links">Sessions</span>
        </a>
      </li>
      <li class="navList active">
        <a href="feedbacks.php">
          <ion-icon name="star-outline"></ion-icon>
          <span class="links">Feedbacks</span>
        </a>
      </li>
      <li class="navList">
        <a href="activities.php">
          <ion-icon name="clipboard"></ion-icon>
          <span class="links">Activities</span>
        </a>
      </li>
      <li class="navList">
        <a href="resource.php">
          <ion-icon name="library-outline"></ion-icon>
          <span class="links">Resource Library</span>
        </a>
      </li>
    </ul>

    <ul class="bottom-link">
      <li class="logout-link">
        <a href="#" onclick="confirmLogout()">
          <ion-icon name="log-out-outline"></ion-icon>
          Logout
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
                            <td class="time-slot"><?= htmlspecialchars($row['Time_Slot']) ?></td>
                            <td class="mentee-star"><?= htmlspecialchars($row['Experience_Star']) ?></td>
                            <td class="mentor-star"><?= htmlspecialchars($row['Mentor_Star']) ?></td>
                            <td>
                                <button class="view-btn" onclick='viewFeedback(this)' data-info='<?= json_encode($row, JSON_HEX_APOS | JSON_HEX_QUOT) ?>'>View</button>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5">No feedback records found for <?php echo htmlspecialchars($loggedInMentorName); ?>.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div id="detailView">
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
                <div class="form-group"><label>Mentee Username:</label><input type="text" id="mentee_from_db" readonly></div>
                <div class="form-group"><label>Mentee Experience:</label><textarea id="mentee_experience" rows="4" readonly></textarea></div>
                <div class="form-group"><label>Experience Star Rating:</label><input type="text" id="experience_star_detail" readonly></div>
                <div class="form-group"><label>Mentor Reviews:</label><textarea id="mentor_reviews" rows="4" readonly></textarea></div>
                <div class="form-group"><label>Mentor Star Rating:</label><input type="text" id="mentor_star_detail" readonly></div>
            </form>
        </div>
    </div>

    <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
    <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
    <script>
        function viewFeedback(button) {
            const data = JSON.parse(button.getAttribute('data-info'));
            
            // Populate the detail view form fields
            document.getElementById('feedback_id').value = data.Feedback_ID || '';
            document.getElementById('session').value = data.Session || '';
            document.getElementById('forum_id').value = data.Forum_ID || '';
            document.getElementById('session_date').value = data.Session_Date || '';
            document.getElementById('time_slot_detail').value = data.Time_Slot || '';
            document.getElementById('session_mentor').value = data.Session_Mentor || '';
            document.getElementById('mentee_from_db').value = data.Mentee || '';
            document.getElementById('mentee_experience').value = data.Mentee_Experience || '';
            document.getElementById('experience_star_detail').value = data.Experience_Star || '';
            document.getElementById('mentor_reviews').value = data.Mentor_Reviews || '';
            document.getElementById('mentor_star_detail').value = data.Mentor_Star || '';

            // Toggle views
            document.getElementById('tableContainer').style.display = 'none';
            document.getElementById('detailView').style.display = 'block';
        }

        function goBack() {
            document.getElementById('detailView').style.display = 'none';
            document.getElementById('tableContainer').style.display = 'block';
        }

        function confirmLogout() {
            if (confirm("Are you sure you want to log out?")) {
                window.location.href = "../logout.php";
            }
        }
    </script>
</section>

</body>
</html>

<?php
// Close the database connection at the end of the script
$conn->close();
?>