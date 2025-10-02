<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

// Standard session check for an Admin (Moderator) user
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'Admin') {
    header("Location: ../login.php");
    exit();
}

// Use your standard database connection
require '../connection/db_connection.php';

// Load admin data for sidebar
$admin_icon = !empty($_SESSION['user_icon']) ? $_SESSION['user_icon'] : '../uploads/img/default_pfp.png';
$admin_name = !empty($_SESSION['user_full_name']) ? $_SESSION['user_full_name'] : 'Admin';

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
    // Set status to 'Approved' for direct creation by admin
    $stmt = $conn->prepare("INSERT INTO users 
        (user_type, first_name, last_name, dob, gender, username, password, email, contact_number, full_address, student, student_year_level, occupation, to_learn, status)
        VALUES ('Mentee', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Approved')");
    
    $stmt->bind_param("ssssssssssssss", $fname, $lname, $dob, $gender, $username_mentee, $hashed_password, $email, $contact, $address, $student, $grade, $occupation, $learning);

    if ($stmt->execute()) {
        $message = "New mentee created successfully!";
    } else {
        $error = "Error creating mentee: " . $stmt->error;
    }
    $stmt->close();
}

// Handle Update Mentee
if (isset($_POST['update'])) {
    $mentee_id = $_POST['mentee_id'];
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

    $sql = "UPDATE users SET 
        first_name=?, last_name=?, dob=?, gender=?, username=?, email=?, contact_number=?, full_address=?, student=?, student_year_level=?, occupation=?, to_learn=?";
    
    $params = [$fname, $lname, $dob, $gender, $username_mentee, $email, $contact, $address, $student, $grade, $occupation, $learning];
    $types = "ssssssssssss";
    
    if (!empty($password)) {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $sql .= ", password=?";
        $params[] = $hashed_password;
        $types .= "s";
    }
    
    $sql .= " WHERE user_id=? AND user_type='Mentee'";
    $params[] = $mentee_id;
    $types .= "i";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);

    if ($stmt->execute()) {
        $message = "Mentee details updated successfully!";
    } else {
        $error = "Error updating mentee: " . $stmt->error;
    }
    $stmt->close();
}

// Handle Delete Mentee
if (isset($_GET['delete'])) {
    $mentee_id = $_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM users WHERE user_id=? AND user_type='Mentee'");
    $stmt->bind_param("i", $mentee_id);
    
    if ($stmt->execute()) {
        // Redirect to clear the GET parameter
        header("Location: manage_mentees.php?status=deleted");
        exit();
    } else {
        $error = "Error deleting mentee: " . $stmt->error;
    }
    $stmt->close();
}

// Fetch all mentees data
$sql = "SELECT user_id, first_name, last_name, dob, gender, username, email, contact_number, full_address, student, student_year_level, occupation, to_learn FROM users WHERE user_type = 'Mentee'";
$result = $conn->query($sql);

$mentees_data = [];
if ($result && $result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $mentees_data[] = $row;
    }
}

$conn->close();

// Check for status messages from redirect
if (isset($_GET['status']) && $_GET['status'] === 'deleted') {
    $message = "Mentee deleted successfully!";
} else if (isset($_GET['success']) && $_GET['success'] === 'create') {
    $message = "New mentee created successfully!";
} else if (isset($_GET['success']) && $_GET['success'] === 'update') {
    $message = "Mentee details updated successfully!";
} else if (isset($_GET['error'])) {
    $error = "An error occurred: " . htmlspecialchars($_GET['error']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/dashboard.css"/> 
    <link rel="icon" href="../uploads/img/coachicon.svg" type="image/svg+xml">
    <title>Manage Mentees | Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* General Layout */
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f4f4f4;
            display: flex; /* Use flexbox for main layout */
            min-height: 100vh;
        }

        /* Sidebar/Navbar Styles (Copied from Super Admin File for consistency) */
        .sidebar {
            width: 250px;
           background-color: #562b63; /* Deep Purple */
            color: #e0e0e0;
            padding: 20px 0; /* Adjusted padding for internal links */
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.5);
            display: flex;
            flex-direction: column;
            overflow-y: auto;
        }
        .sidebar-header {
            text-align: center;
            padding: 0 20px;
            margin-bottom: 30px;
        }
        .sidebar-header img {
            width: 70px; /* Slightly smaller */
            height: 70px;
            border-radius: 50%;
            object-fit: cover;
           border: 3px solid #7a4a87;
            margin-bottom: 8px;
        }
        .sidebar-header h4 {
            margin: 0;
            font-weight: 500;
            color: #fff;
        }
        .sidebar nav ul {
            list-style: none;
            padding: 0;
            margin: 0;
            flex-grow: 1; /* Allow navigation list to grow */
        }
        .sidebar nav ul li a {
            display: block;
            color: #e0e0e0;
            text-decoration: none;
            padding: 12px 20px; /* Uniform padding */
            margin: 5px 0;
            border-radius: 0; /* No rounded corners on links */
            transition: background-color 0.2s, border-left-color 0.2s;
            display: flex;
            align-items: center;
            border-left: 5px solid transparent; /* Prepare for active indicator */
        }
        .sidebar nav ul li a i {
            margin-right: 12px;
            font-size: 18px;
        }
        .sidebar nav ul li a:hover {
            background-color: #37474f; /* Slightly lighter dark color on hover */
            color: #fff;
        }
        .sidebar nav ul li a.active {
             background-color: #7a4a87; /* Active background */
            border-left: 5px solid #00bcd4; /* Vibrant blue/cyan left border */
            color: #00bcd4; /* Active text color */
        }
        .logout-container {
            margin-top: auto;
            padding: 20px;
            border-top: 1px solid #37474f;
        }
        .logout-btn {
            background-color: #e53935; /* Red logout button */
            color: white;
            border: none;
            padding: 10px;
            border-radius: 5px;
            width: 100%;
            cursor: pointer;
            transition: background-color 0.3s;
            font-weight: bold;
        }
        .logout-btn:hover {
            background-color: #c62828;
        }

        /* Main Content Area */
        .main-content {
            flex-grow: 1;
            padding: 20px 30px;
        }
        
        header {
            padding: 10px 0;
            border-bottom: 2px solid #562b63;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        header h1 {
             color: #562b63;
            margin: 0;
            font-size: 28px;
            margin-top: 30px;
        }
        
        /* Action Buttons (New Mentee) */
        .new-mentee-btn {
            background-color: #28a745;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            transition: background-color 0.3s, transform 0.1s;
            font-weight: 600;
            margin-top: 35px;
        }
        .new-mentee-btn:hover {
            background-color: #218838;
            transform: translateY(-1px);
        }
        
        /* Table Styles (Matching Mentors page) */
        .table-container {
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
        
        /* Search Bar & Controls */
        .controls {
            display: flex;
            justify-content: flex-start; /* Aligned to match the image, with space between search and table */
            align-items: center;
            margin-bottom: 20px;
        }
        .search-box input {
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 5px;
            width: 300px;
            font-size: 16px;
        }
        .search-box {
            position: relative;
        }
        .search-box i {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: #aaa;
        }
        .search-box input {
            padding-left: 35px; /* Make space for the icon */
        }


        /* Details View & Form Styles */
        .details-view, .form-container {
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 8px;
            background-color: #fff;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            margin-top: 20px;
        }
        .details-view h3, .form-container h3 {
            color: #562b63;
            border-bottom: 1px solid #ccc;
            padding-bottom: 10px;
            margin-top: 5px;
            margin-bottom: 15px;
        }
        .details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px 30px; /* Space between rows and columns */
            margin-bottom: 20px;
        }
        .details-grid p {
            margin: 0;
            display: flex;
            flex-direction: column;
        }
        .details-grid p strong {
            font-weight: 600;
            color: #555;
            margin-bottom: 5px;
            font-size: 0.95em;
        }

        /* Input, Select, and Textarea general styling */
        input[type="text"],
        input[type="email"],
        input[type="password"],
        input[type="date"],
        select,
        textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ced4da; /* Light border */
            border-radius: 6px;
            box-sizing: border-box; 
            transition: border-color 0.3s, box-shadow 0.3s;
            font-size: 1em;
            color: #495057;
            background-color: #f8f9fa; /* Very light gray background */
        }
        
        /* Readonly/Disabled styles */
        .details-view input[readonly], 
        .details-view textarea[readonly], 
        .details-view select[disabled] {
            background-color: #e9ecef !important; 
            cursor: default !important;
        }


        /* Action Buttons */
        .action-buttons {
            margin-top: 20px;
            text-align: right;
            border-top: 1px solid #eee;
            padding-top: 15px;
            display: flex;
            justify-content: space-between;
        }
        .btn { 
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
            transition: background-color 0.3s;
            margin-left: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .back-btn { 
            background-color: #6c757d;
            color: white;
        }
        .back-btn:hover {
            background-color: #5a6268;
        }
        .edit-btn {
            background-color: #00bcd4;
            color: white;
        }
        .edit-btn:hover {
            background-color: #0097a7;
        }
        .update-btn {
            background-color: #007bff; /* Blue for Save Changes */
            color: white;
        }
        .update-btn:hover {
            background-color: #0056b3;
        }
        .delete-btn {
            background-color: #dc3545;
            color: white;
        }
        .delete-btn:hover {
            background-color: #c82333;
        }
        .create-btn {
            background-color: #28a745;
            color: white;
        }
        .create-btn:hover {
            background-color: #218838;
        }
        .view-btn { /* Matches the gray View Details button in the image/Super Admin file */
            background-color: #6c757d; 
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 5px;
            cursor: pointer;
            transition: background-color 0.3s;
            font-weight: 600;
        }
        .view-btn:hover {
            background-color: #5a6268;
        }

        .hidden {
            display: none;
        }
        
        /* Message/Error display */
        .message-box {
            padding: 10px;
            margin-bottom: 20px;
            border-radius: 5px;
            font-weight: bold;
        }
        .success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
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
        <img src="<?php echo htmlspecialchars($admin_icon); ?>" alt="Admin Profile Picture" />
        <div class="admin-text">
          <span class="admin-name"><?php echo htmlspecialchars($admin_name); ?></span>
          <span class="admin-role">Admin</span> </div>
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
      <img src="../uploads/img/logo.png" alt="Logo"> </div>
<div class="main-content">
    <header>
        <h1>Manage Mentees</h1>
        <button class="new-mentee-btn" onclick="showCreateForm()"><i class="fas fa-plus-circle"></i> Create New Mentee</button>
    </header>

    <?php if (isset($message)): ?>
        <div class="message-box success"><?php echo $message; ?></div>
    <?php endif; ?>
    <?php if (isset($error)): ?>
        <div class="message-box error"><?php echo $error; ?></div>
    <?php endif; ?>

    <section id="menteesListView">
        <div class="controls">
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" id="searchInput" onkeyup="searchMentees()" placeholder="Search by Name or ID...">
            </div>
        </div>

        <div class="table-container">
            <table id="menteesTable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>First Name</th>
                        <th>Last Name</th>
                        <th>Email</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($mentees_data) > 0): ?>
                        <?php foreach ($mentees_data as $mentee): ?>
                            <tr class="data-row">
                                <td><?php echo htmlspecialchars($mentee['user_id']); ?></td>
                                <td><?php echo htmlspecialchars($mentee['first_name']); ?></td>
                                <td><?php echo htmlspecialchars($mentee['last_name']); ?></td>
                                <td><?php echo htmlspecialchars($mentee['email']); ?></td>
                                <td>
                                     <button class="btn view-btn" onclick="viewDetails(<?php echo htmlspecialchars(json_encode($mentee), ENT_QUOTES, 'UTF-8'); ?>)">View Details</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr class="no-data"><td colspan="5" style="text-align: center; padding: 20px;">No mentees found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section id="menteeDetailsView" class="details-view hidden">
        <h3>Mentee Details</h3>
        <form id="menteeForm" method="POST">
            <input type="hidden" name="mentee_id" id="mentee_id">
            <input type="hidden" name="update" value="1">
            <div class="details-grid">
                <p><strong>First Name:</strong> <input type="text" name="fname" id="fname" required readonly></p>
                <p><strong>Last Name:</strong> <input type="text" name="lname" id="lname" required readonly></p>
                <p><strong>Username:</strong> <input type="text" name="username" id="username" required readonly></p>
                <p><strong>Email:</strong> <input type="email" name="email" id="email" required readonly></p>
                <p><strong>DOB:</strong> <input type="date" name="dob" id="dob" required readonly></p>
                <p><strong>Gender:</strong> 
                    <select name="gender" id="gender" required disabled>
                        <option value="">-- Select Gender --</option>
                        <option value="Male">Male</option>
                        <option value="Female">Female</option>
                        <option value="Other">Other</option>
                    </select>
                </p>
                <p><strong>Contact:</strong> <input type="text" name="contact" id="contact" required readonly></p>
                <p><strong>Password:</strong> <input type="password" name="password" id="password" placeholder="Leave blank to keep current password" readonly></p>
                <p><strong>Is Student:</strong> 
                    <select name="student" id="student" required disabled>
                        <option value="Yes">Yes</option>
                        <option value="No">No</option>
                    </select>
                </p>
                <p><strong>Grade/Year Level:</strong> <input type="text" name="grade" id="grade" placeholder="N/A if not student" readonly></p>
                <p><strong>Occupation:</strong> <input type="text" name="occupation" id="occupation" placeholder="N/A if student" readonly></p>
            </div>
            <p style="grid-column: 1 / -1; margin-top: 15px;">
                <strong>Address:</strong> 
                <textarea name="address" id="address" required readonly></textarea>
            </p>
            <p style="grid-column: 1 / -1; margin-top: 15px;">
                <strong>What to Learn:</strong> 
                <textarea name="learning" id="learning" required readonly></textarea>
            </p>
             <div class="action-buttons">
                <button type="button" class="btn back-btn" onclick="backToList()">
                    <i class="fas fa-arrow-left"></i> Back
                </button>
                
                <div style="display: flex; gap: 10px;">
                    <button type="button" class="btn edit-btn" id="editButton" onclick="toggleEditMode()">
                        <i class="fas fa-edit"></i> Edit
                    </button>
                    <button type="submit" class="btn update-btn hidden" id="updateButton">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                    <button type="button" class="btn delete-btn" onclick="confirmDelete()">
                        <i class="fas fa-trash"></i> Delete
                    </button>
                </div>
            </div>
        </form>
    </section>

    <section id="createMenteeForm" class="form-container hidden">
        <h3>Create New Mentee</h3>
        <form method="POST">
            <input type="hidden" name="create" value="1">
            <div class="details-grid">
                <p><strong>First Name:</strong> <input type="text" name="fname" required></p>
                <p><strong>Last Name:</strong> <input type="text" name="lname" required></p>
                <p><strong>Username:</strong> <input type="text" name="username" required></p>
                <p><strong>Email:</strong> <input type="email" name="email" required></p>
                <p><strong>DOB:</strong> <input type="date" name="dob" required></p>
                <p><strong>Gender:</strong> 
                    <select name="gender" required>
                        <option value="">-- Select Gender --</option>
                        <option value="Male">Male</option>
                        <option value="Female">Female</option>
                        <option value="Other">Other</option>
                    </select>
                </p>
                <p><strong>Contact:</strong> <input type="text" name="contact" required></p>
                <p><strong>Password:</strong> <input type="password" name="password" required></p>
                <p><strong>Is Student:</strong> 
                    <select name="student" required>
                        <option value="Yes">Yes</option>
                        <option value="No">No</option>
                    </select>
                </p>
                <p><strong>Grade/Year Level:</strong> <input type="text" name="grade" placeholder="N/A if not student"></p>
                <p><strong>Occupation:</strong> <input type="text" name="occupation" placeholder="N/A if student"></p>
                <p><strong>&nbsp;</strong></p>
            </div>
            <p style="grid-column: 1 / -1; margin-top: 15px;">
                <strong>Address:</strong> 
                <textarea name="address" required></textarea>
            </p>
            <p style="grid-column: 1 / -1; margin-top: 15px;">
                <strong>What to Learn:</strong> 
                <textarea name="learning" required></textarea>
            </p>
            <div class="action-buttons" style="justify-content: flex-end;">
                <button type="button" class="btn back-btn" onclick="backToList()"><i class="fas fa-times"></i> Cancel</button>
                <button type="submit" class="btn create-btn"><i class="fas fa-user-plus"></i> Create Mentee</button>
            </div>
        </form>
    </section>

</div> </section>

<script>
    // Global function to confirm logout
    function confirmLogout() {
        if (confirm("Are you sure you want to log out?")) {
            window.location.href = "../login.php";
        }
    }

    // --- View and Navigation Functions ---
    const menteesListView = document.getElementById('menteesListView');
    const menteeDetailsView = document.getElementById('menteeDetailsView');
    const createMenteeForm = document.getElementById('createMenteeForm');
    let currentMenteeId = null;

    function backToList() {
        menteeDetailsView.classList.add('hidden');
        createMenteeForm.classList.add('hidden');
        menteesListView.classList.remove('hidden');
    }

    function showCreateForm() {
        menteesListView.classList.add('hidden');
        menteeDetailsView.classList.add('hidden');
        createMenteeForm.classList.remove('hidden');
    }

    // View Details (Populate form and show details view)
    function viewDetails(data) {
        currentMenteeId = data.user_id;

        // Populate fields
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

        // Set all fields to readonly/disabled and show Edit button
        document.querySelectorAll('#menteeForm input, #menteeForm textarea').forEach(el => {
            el.setAttribute('readonly', 'readonly');
            el.style.backgroundColor = '';
            el.style.cursor = '';
        });
        document.getElementById('gender').setAttribute('disabled', 'disabled');
        document.getElementById('student').setAttribute('disabled', 'disabled');
        
        document.getElementById('editButton').classList.remove('hidden');
        document.getElementById('updateButton').classList.add('hidden');

        // Show view
        menteesListView.classList.add('hidden');
        createMenteeForm.classList.add('hidden');
        menteeDetailsView.classList.remove('hidden');
    }

    // Toggle Edit Mode for Mentee Details
    function toggleEditMode() {
        document.querySelectorAll('#menteeForm input:not(#password), #menteeForm textarea').forEach(el => {
            el.removeAttribute('readonly');
            el.style.backgroundColor = '#fff';
            el.style.cursor = 'text';
        });
        // Special handling for password field (only remove readonly, keep placeholder logic)
        document.getElementById('password').removeAttribute('readonly');
        document.getElementById('password').style.backgroundColor = '#fff';
        document.getElementById('password').style.cursor = 'text';

        // Enable selects
        document.getElementById('gender').removeAttribute('disabled');
        document.getElementById('student').removeAttribute('disabled');
        
        document.getElementById('editButton').classList.add('hidden');
        document.getElementById('updateButton').classList.remove('hidden');
    }

    // Search Functionality
    function searchMentees() {
        const input = document.getElementById('searchInput').value.toLowerCase();
        const rows = document.querySelectorAll('#menteesTable tbody tr.data-row');

        let found = false;
        rows.forEach(row => {
            const id = row.cells[0].innerText.toLowerCase();
            const firstName = row.cells[1].innerText.toLowerCase();
            const lastName = row.cells[2].innerText.toLowerCase();
            const email = row.cells[3].innerText.toLowerCase();


            if (id.includes(input) || firstName.includes(input) || lastName.includes(input) || email.includes(input)) {
                row.style.display = '';
                found = true;
            } else {
                row.style.display = 'none';
            }
        });
        
        // Handle no data row visibility
        const noDataRow = document.querySelector('#menteesTable tbody tr.no-data');
        if (noDataRow) {
            noDataRow.style.display = found ? 'none' : (rows.length === 0 ? '' : 'none');
        }
    }
    
    // Delete Confirmation
    function confirmDelete() {
        if (currentMenteeId && confirm(`Are you sure you want to permanently delete the mentee with ID ${currentMenteeId}? This action cannot be undone.`)) {
            window.location.href = `manage_mentees.php?delete=${currentMenteeId}`;
        }
    }
</script>
<script type="module" src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.esm.js"></script>
<script nomodule src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.js"></script>
</body>
</html>