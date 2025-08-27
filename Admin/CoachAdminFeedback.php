<?php
session_start(); // Start the session

// Use the provided database connection details
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "coach";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// --- ADMIN SESSION CHECK ---
// Using the new logic for admin session
if (!isset($_SESSION['admin_username'])) {
    header("Location: loginadmin.php"); // Redirect to the admin login page
    exit();
}

// --- FETCH ADMIN NAME AND ICON ---
// Assuming an 'admins' table with columns Admin_Username, Admin_Name, Admin_Icon
$adminUsername = $_SESSION['admin_username'];
$sqlAdmin = "SELECT Admin_Name, Admin_Icon FROM admins WHERE Admin_Username = ?";
$stmtAdmin = $conn->prepare($sqlAdmin);

if ($stmtAdmin === false) {
    // Handle error - unable to prepare statement for admin details
    $_SESSION['admin_name'] = "Unknown Admin";
    $_SESSION['admin_icon'] = "img/default_pfp.png";
    // Log the error if possible: error_log("Error preparing admin stmt: " . $conn->error);
} else {
    $stmtAdmin->bind_param("s", $adminUsername);
    $stmtAdmin->execute();
    $resultAdmin = $stmtAdmin->get_result();

    if ($resultAdmin->num_rows === 1) {
        $rowAdmin = $resultAdmin->fetch_assoc();
        $_SESSION['admin_name'] = $rowAdmin['Admin_Name'];
        // Check if Admin_Icon exists and is not empty
        if (isset($rowAdmin['Admin_Icon']) && !empty($rowAdmin['Admin_Icon'])) {
             $_SESSION['admin_icon'] = $rowAdmin['Admin_Icon'];
        } else {
             $_SESSION['admin_icon'] = "img/default_pfp.png"; // Default icon if none is set
        }
    } else {
        // Admin user found in session but not in DB? Fallback defaults.
        $_SESSION['admin_name'] = "Admin User";
        $_SESSION['admin_icon'] = "img/default_pfp.png";
        // Consider logging this unexpected state
         error_log("Admin username in session but not found in DB: " . $adminUsername);
    }
    $stmtAdmin->close();
}


// --- REMOVE FILTER ---
// Fetch ALL feedback records for the admin view
$queryFeedback = "SELECT * FROM Feedback";
$result = $conn->query($queryFeedback);

// Check if the query failed
if ($result === false) {
    die("Error fetching feedback records: " . $conn->error);
}


// Ensure the database connection is closed ONLY at the very end
// $conn->close(); // REMOVED from here

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/admin_dashboardstyle.css" />
    <link rel="stylesheet" href="css/admin_menteesstyle.css"> <link rel="icon" href="coachicon.svg" type="image/svg+xml">
    <title>Admin | Manage Feedback</title>
</head>
<body>

<nav>
    <div class="nav-top">
        <div class="logo">
            <div class="logo-image"><img src="img/logo.png" alt="Logo"></div>
            <div class="logo-name">COACH</div>
        </div>

        <div class="admin-profile">
            <img src="<?php echo htmlspecialchars($_SESSION['admin_icon']); ?>" alt="Admin Profile Picture" />
            <div class="admin-text">
                <span class="admin-name">
                    <?php echo htmlspecialchars($_SESSION['admin_name']); ?>
                </span>
                <span class="admin-role">Moderator</span> </div>
            <a href="CoachAdminPFP.php?username=<?= urlencode($_SESSION['admin_username']) ?>" class="edit-profile-link" title="Edit Profile">
                <ion-icon name="create-outline" class="verified-icon"></ion-icon>
            </a>
        </div>
    </div>

    <div class="menu-items">
        <ul class="navLinks">
            <li class="navList">
                <a href="CoachAdmin.php"> <ion-icon name="home-outline"></ion-icon>
                    <span class="links">Home</span>
                </a>
            </li>
            <li class="navList">
                <a href="CoachAdminCourses.php"> <ion-icon name="book-outline"></ion-icon>
                    <span class="links">Courses</span>
                </a>
            </li>
            <li class="navList">
                <a href="CoachAdminMentees.php"> <ion-icon name="person-outline"></ion-icon>
                    <span class="links">Mentees</span>
                </a>
            </li>
             <li class="navList">
                <a href="CoachAdminMentors.php"> <ion-icon name="people-outline"></ion-icon>
                    <span class="links">Mentors</span>
                </a>
            </li>
             <li class="navList">
                <a href="CoachAdminSession.php"> <ion-icon name="calendar-outline"></ion-icon>
                    <span class="links">Sessions</span>
                </a>
            </li>
            <li class="navList active"> <a href="CoachAdminFeedback.php"> <ion-icon name="star-outline"></ion-icon>
                    <span class="links">Feedback</span>
                </a>
            </li>
            <li class="navList">
                <a href="admin-sessions.php"> <ion-icon name="chatbubbles-outline"></ion-icon>
                    <span class="links">Channels</span>
                </a>
            </li>
             <li class="navList">
                <a href="CoachAdminActivities.php"> <ion-icon name="clipboard"></ion-icon>
                    <span class="links">Activities</span>
                </a>
            </li>
             <li class="navList">
                <a href="CoachAdminResource.php"> <ion-icon name="library-outline"></ion-icon>
                    <span class="links">Resource Library</span>
                </a>
            </li>
         </ul>

        <ul class="bottom-link">
            <li class="logout-link" style="padding-top: 280px;">
                <a href="#" onclick="confirmLogout()" style="color: white; text-decoration: none; font-size: 18px;">
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
        <img src="img/logo.png" alt="Logo">
    </div>

    <h1>Manage Feedback</h1>

    <div id="tableContainer">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Time Slot</th>
                    <th>Experience Star</th>
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
                        <td colspan="5">No feedback records found.</td>
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
                 <div class="form-group"><label>Mentee Username (from DB):</label><input type="text" id="mentee_from_db" readonly></div>
                <div class="form-group"><label>Mentee Experience:</label><textarea id="mentee_experience" rows="4" readonly></textarea></div>
                <div class="form-group"><label>Experience Star Rating:</label><input type="text" id="experience_star_detail" readonly></div>
                <div class="form-group"><label>Mentor Reviews:</label><textarea id="mentor_reviews" rows="4" readonly></textarea></div>
                <div class="form-group"><label>Mentor Star Rating:</label><input type="text" id="mentor_star_detail" readonly></div>

            </form>
        </div>
    </div>

    <script type="module" src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.esm.js"></script>
    <script nomodule src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.js"></script>
    <script>
        function searchFeedback() {
            const input = document.getElementById('searchInput').value.toLowerCase();
            const rows = document.querySelectorAll('#tableContainer table tbody tr.data-row');

            rows.forEach(row => {
                // Get text content from searchable columns
                const id = row.querySelector('td:nth-child(1)').innerText.toLowerCase(); // ID
                const timeSlot = row.querySelector('.time-slot').innerText.toLowerCase(); // Time Slot
                const menteeStar = row.querySelector('.mentee-star').innerText.toLowerCase(); // Mentee Star
                const mentorStar = row.querySelector('.mentor-star').innerText.toLowerCase(); // Mentor Star
                // Add Session Mentor to search for admin? Optional
                // const sessionMentor = row.querySelector('.session-mentor-col').innerText.toLowerCase(); // Needs a class added to the table cell if searching

                // Check if input is present in any of the searchable columns
                if (id.includes(input) || timeSlot.includes(input) || menteeStar.includes(input) || mentorStar.includes(input) /* || sessionMentor.includes(input) */) {
                    row.style.display = ''; // Show the row
                } else {
                    row.style.display = 'none'; // Hide the row
                }
            });
        }


        function viewFeedback(button) {
            // Parse the JSON data stored in the button's data-info attribute
            const data = JSON.parse(button.getAttribute('data-info'));

            // Populate the detail view form fields with the feedback data
            document.getElementById('feedback_id').value = data.Feedback_ID || '';
            document.getElementById('session').value = data.Session || '';
            document.getElementById('forum_id').value = data.Forum_ID || '';
            document.getElementById('session_date').value = data.Session_Date || '';
            document.getElementById('time_slot_detail').value = data.Time_Slot || ''; // Use detail ID
            document.getElementById('session_mentor').value = data.Session_Mentor || '';
            document.getElementById('mentee_from_db').value = data.Mentee || ''; // Populate the Mentee field in detail view
            document.getElementById('mentee_experience').value = data.Mentee_Experience || '';
            document.getElementById('experience_star_detail').value = data.Experience_Star || ''; // Use detail ID
            document.getElementById('mentor_reviews').value = data.Mentor_Reviews || '';
            document.getElementById('mentor_star_detail').value = data.Mentor_Star || ''; // Use detail ID


            // Ensure all fields are readonly
             document.querySelectorAll('#feedbackDetails input, #feedbackDetails textarea').forEach(el => {
                el.setAttribute('readonly', true);
            });


            // Toggle views: hide table, show detail view
            document.getElementById('tableContainer').style.display = 'none';
            document.getElementById('detailView').style.display = 'block';
        }

        function goBack() {
            // Toggle views back: hide detail view, show table
            document.getElementById('detailView').style.display = 'none';
            document.getElementById('tableContainer').style.display = 'block';
        }

        function confirmLogout() {
            var confirmation = confirm("Are you sure you want to log out?");
            if (confirmation) {
                // If the user clicks "OK", redirect to logout.php
                // Make sure logout.php handles clearing the 'admin_username' session
                window.location.href = "logout.php";
            } else {
                // If the user clicks "Cancel", do nothing
                return false;
            }
        }

    </script>

</section>

</body>
</html>

<?php
// Ensure the database connection is closed at the very end of the script
$conn->close();
?>