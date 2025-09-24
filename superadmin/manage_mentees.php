<?php
session_start(); 

// Standard session check for an admin user
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'Super Admin') {
    header("Location: ../login.php");
    exit();
}

// Use your standard database connection
require '../connection/db_connection.php';

// Handle Create New Mentee
if (isset($_POST['create'])) {
    // All these fields are specific to a mentee profile
    $fname = $_POST['fname'];
    $lname = $_POST['lname'];
    $dob = $_POST['dob'];
    $gender = $_POST['gender'];
    $username_mentee = $_POST['username'];
    $email = $_POST['email'];
    $contact = $_POST['contact'];
    $address = $_POST['address'];
    $student = $_POST['student'];
    $grade = $_POST['grade'];
    $occupation = $_POST['occupation'];
    $learning = $_POST['learning'];
    $password = $_POST['password'];
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    // SQL now targets the unified 'users' table
    $stmt = $conn->prepare("INSERT INTO users 
        (user_type, first_name, last_name, dob, gender, username, password, email, contact_number, full_address, student, student_year_level, occupation, to_learn)
        VALUES ('Mentee', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssssssssss", $fname, $lname, $dob, $gender, $username_mentee, $hashed_password, $email, $contact, $address, $student, $grade, $occupation, $learning);
    
    if ($stmt->execute()) {
        header("Location: manage_mentees.php?success=create");
    } else {
        header("Location: manage_mentees.php?error=" . urlencode($stmt->error));
    }
    $stmt->close();
    exit();
}

// Handle Update Mentee
if (isset($_POST['update'])) {
    $id = $_POST['id'];
    $fname = $_POST['fname'];
    $lname = $_POST['lname'];
    $dob = $_POST['dob'];
    $gender = $_POST['gender'];
    $username_mentee = $_POST['username'];
    $email = $_POST['email'];
    $contact = $_POST['contact'];
    $address = $_POST['address'];
    $student = $_POST['student'];
    $grade = $_POST['grade'];
    $occupation = $_POST['occupation'];
    $learning = $_POST['learning'];
    $password = $_POST['password'];

    // Check if a new password was provided to update it
    if (!empty($password)) {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $sql = "UPDATE users SET 
                first_name=?, last_name=?, dob=?, gender=?, username=?, password=?, email=?, 
                contact_number=?, full_address=?, student=?, student_year_level=?, occupation=?, to_learn=?
                WHERE user_id=? AND user_type='Mentee'";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssssssssssssi", $fname, $lname, $dob, $gender, $username_mentee, $hashed_password, $email, $contact, $address, $student, $grade, $occupation, $learning, $id);
    } else {
        // Update without changing the password
        $sql = "UPDATE users SET 
                first_name=?, last_name=?, dob=?, gender=?, username=?, email=?, 
                contact_number=?, full_address=?, student=?, student_year_level=?, occupation=?, to_learn=?
                WHERE user_id=? AND user_type='Mentee'";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssssssssssssi", $fname, $lname, $dob, $gender, $username_mentee, $email, $contact, $address, $student, $grade, $occupation, $learning, $id);
    }
    
    if ($stmt->execute()) {
        header("Location: manage_mentees.php?success=update");
    } else {
        header("Location: manage_mentees.php?error=" . urlencode($stmt->error));
    }
    $stmt->close();
    exit();
}

$admin_icon = !empty($_SESSION['user_icon']) ? $_SESSION['user_icon'] : '../uploads/img/default_pfp.png';
// Fetch all mentees from the 'users' table
$result = $conn->query("SELECT * FROM users WHERE user_type = 'Mentee'");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/dashboard.css"/>
    <link rel="stylesheet" href="css/mentees.css">
     <link rel="icon" href="../uploads/img/coachicon.svg" type="image/svg+xml">
    <title>Manage Mentees</title>
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
        <li class="navList active">
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
        <h1>Manage Mentees</h1>

        <div class="top-bar">
            <button onclick="showCreateForm()" class="create-btn">+ Create</button>
            <div class="search-box">
                <input type="text" id="searchInput" onkeyup="searchMentees()" placeholder="Search by name or ID...">
            </div>
        </div>

        <!-- Create Mentee Form -->
        <div class="form-container" id="createForm" style="display:none;">
            <h2>Create New Mentee</h2>
            <form method="POST">
                <input type="hidden" name="create" value="1">
                <div class="form-group"><label>First Name</label><input type="text" name="fname" required></div>
                <div class="form-group"><label>Last Name</label><input type="text" name="lname" required></div>
                <div class="form-group"><label>Date of Birth</label><input type="date" name="dob" required></div>
                <div class="form-group"><label>Gender</label><input type="text" name="gender" required></div>
                <div class="form-group"><label>Username</label><input type="text" name="username" required></div>
                <div class="form-group"><label>Password</label><input type="password" name="password" required></div>
                <div class="form-group"><label>Email</label><input type="email" name="email" required></div>
                <div class="form-group"><label>Contact Number</label><input type="text" name="contact" required></div>
                <div class="form-group"><label>Full Address</label><input type="text" name="address" required></div>
                <div class="form-group"><label>Are you a Student?</label><input type="text" name="student" required></div>
                <div class="form-group"><label>Year Level</label><input type="text" name="grade"></div>
                <div class="form-group"><label>Occupation</label><input type="text" name="occupation"></div>
                <div class="form-group"><label>What they want to learn</label><textarea name="learning" rows="3" required></textarea></div>
                <div class="form-buttons">
                    <button type="submit" class="update-btn">Save</button>
                    <button type="button" onclick="hideCreateForm()" class="cancel-btn">Cancel</button>
                </div>
            </form>
        </div>

        <!-- Mentee Details/Edit Form -->
        <div id="menteeDetails" class="form-container" style="display: none;">
            <h2>View / Edit Mentee Details</h2>
            <form method="POST" id="menteeForm">
                <input type="hidden" name="id" id="mentee_id">
                <div class="form-group"><label>First Name</label><input type="text" name="fname" id="fname" required readonly></div>
                <div class="form-group"><label>Last Name</label><input type="text" name="lname" id="lname" required readonly></div>
                <div class="form-group"><label>Date of Birth</label><input type="date" name="dob" id="dob" required readonly></div>
                <div class="form-group"><label>Gender</label><input type="text" name="gender" id="gender" required readonly></div>
                <div class="form-group"><label>Username</label><input type="text" name="username" id="username" required readonly></div>
                <div class="form-group"><label>New Password</label><input type="password" name="password" id="password" placeholder="Leave blank to keep current password" readonly></div>
                <div class="form-group"><label>Email</label><input type="email" name="email" id="email" required readonly></div>
                <div class="form-group"><label>Contact Number</label><input type="text" name="contact" id="contact" required readonly></div>
                <div class="form-group"><label>Full Address</label><input type="text" name="address" id="address" required readonly></div>
                <div class="form-group"><label>Are you a Student?</label><input type="text" name="student" id="student" required readonly></div>
                <div class="form-group"><label>Year Level</label><input type="text" name="grade" id="grade" readonly></div>
                <div class="form-group"><label>Occupation</label><input type="text" name="occupation" id="occupation" readonly></div>
                <div class="form-group"><label>What they want to learn</label><textarea name="learning" id="learning" rows="3" required readonly></textarea></div>
                <div class="form-buttons">
                    <button type="button" id="editButton" class="update-btn" onclick="toggleEditMode()">Edit</button>
                    <button type="submit" name="update" id="updateButton" class="update-btn" style="display: none;">Update</button>
                    <button type="button" onclick="backToTable()" class="cancel-btn">Back</button>
                </div>
            </form>
        </div>

        <!-- Mentees Table -->
        <table id="menteesTable">
            <thead>
            <tr>
                <th>ID</th>
                <th>First Name</th>
                <th>Last Name</th>
                <th>Action</th>
            </tr>
            </thead>
            <tbody>
            <?php while($row = $result->fetch_assoc()): ?>
            <tr class="data-row">
                <td><?= $row['user_id'] ?></td>
                <td class="first-name"><?= htmlspecialchars($row['first_name']) ?></td>
                <td class="last-name"><?= htmlspecialchars($row['last_name']) ?></td>
                <td>
                    <button class="view-btn" onclick='viewMentee(this)' data-info='<?= json_encode($row, JSON_HEX_QUOT | JSON_HEX_APOS) ?>'>View</button>
                </td>
            </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</section>

<script type="module" src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.esm.js"></script>
<script nomodule src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.js"></script>
<script>
// General UI Functions
const navBar = document.querySelector("nav");
const navToggle = document.querySelector(".navToggle");
if (navToggle) {
    navToggle.addEventListener('click', () => navBar.classList.toggle('close'));
}

function confirmLogout() {
    if (confirm("Are you sure you want to log out?")) {
        window.location.href = "../login.php";
    }
}

// Form Visibility Controls
function showCreateForm() {
    document.getElementById('createForm').style.display = 'block';
    document.getElementById('menteesTable').style.display = 'none';
    document.querySelector('.top-bar').style.display = 'none';
}

function hideCreateForm() {
    document.getElementById('createForm').style.display = 'none';
    document.getElementById('menteesTable').style.display = 'table';
    document.querySelector('.top-bar').style.display = 'flex';
}

function backToTable() {
    document.getElementById('menteeDetails').style.display = 'none';
    document.getElementById('menteesTable').style.display = 'table';
    document.querySelector('.top-bar').style.display = 'flex';
    // Reset edit mode
    document.querySelectorAll('#menteeForm input, #menteeForm textarea').forEach(el => el.readOnly = true);
    document.getElementById('editButton').style.display = 'inline-block';
    document.getElementById('updateButton').style.display = 'none';
}

// View Mentee Details
function viewMentee(button) {
    document.getElementById('menteesTable').style.display = 'none';
    document.querySelector('.top-bar').style.display = 'none';
    document.getElementById('menteeDetails').style.display = 'block';

    const data = JSON.parse(button.getAttribute('data-info'));
    
    // Populate form fields using data from the new 'users' table structure
    document.getElementById('mentee_id').value = data.user_id;
    document.getElementById('fname').value = data.first_name;
    document.getElementById('lname').value = data.last_name;
    document.getElementById('dob').value = data.dob;
    document.getElementById('gender').value = data.gender;
    document.getElementById('username').value = data.username;
    document.getElementById('email').value = data.email;
    document.getElementById('contact').value = data.contact_number;
    document.getElementById('address').value = data.full_address;
    document.getElementById('student').value = data.student;
    document.getElementById('grade').value = data.student_year_level;
    document.getElementById('occupation').value = data.occupation;
    document.getElementById('learning').value = data.to_learn;
    document.getElementById('password').value = ''; // Clear password field for security
}

// Toggle Edit Mode for Mentee Details
function toggleEditMode() {
    document.querySelectorAll('#menteeForm input, #menteeForm textarea').forEach(el => {
        el.removeAttribute('readonly');
    });
    document.getElementById('editButton').style.display = 'none';
    document.getElementById('updateButton').style.display = 'inline-block';
}

// Search Functionality
function searchMentees() {
    const input = document.getElementById('searchInput').value.toLowerCase();
    const rows = document.querySelectorAll('#menteesTable tbody tr.data-row');

    rows.forEach(row => {
        const id = row.cells[0].innerText.toLowerCase();
        const firstName = row.cells[1].innerText.toLowerCase();
        const lastName = row.cells[2].innerText.toLowerCase();

        if (id.includes(input) || firstName.includes(input) || lastName.includes(input)) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}
</script>

</body>
</html>
