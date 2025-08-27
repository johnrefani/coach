<?php
session_start();
$conn = new mysqli("localhost", "root", "", "coach");

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$updated = false;
$error = '';
$mentor = null;
$imageUploaded = false;

// Determine username
$username = $_GET['username'] ?? ($_POST['original_username'] ?? '');

if (empty($username)) {
    die("No username provided.");
}

// If form was submitted, process update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_username = $_POST['username'] ?? '';
    $first_name = $_POST['first_name'] ?? '';
    $last_name = $_POST['last_name'] ?? '';
    $email = $_POST['email'] ?? '';
    $contact_number = $_POST['contact_number'] ?? '';
    $password = $_POST['password'] ?? '';
    $original_username = $_POST['original_username'] ?? '';

    // Check if profile form was submitted
    if (isset($_POST['update_profile'])) {
        if (empty($new_username) || empty($first_name) || empty($last_name) || empty($email) || empty($contact_number) || empty($password)) {
            $error = "All fields are required.";
        } else {
            // Check if the password has been changed by comparing with the stored hash
            $check_sql = "SELECT Applicant_Password FROM Applications WHERE Applicant_Username = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param("s", $original_username);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            $current_data = $check_result->fetch_assoc();
            $check_stmt->close();
            
            // If password has changed, hash the new password
            if ($password !== $current_data['Applicant_Password']) {
                // Password has been changed, hash it
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                
                $sql = "UPDATE Applications SET Applicant_Username = ?, First_Name = ?, Last_Name = ?, Email = ?, Contact_Number = ?, Applicant_Password = ? WHERE Applicant_Username = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("sssssss", $new_username, $first_name, $last_name, $email, $contact_number, $hashed_password, $original_username);
            } else {
                // Password hasn't changed, use original values
                $sql = "UPDATE Applications SET Applicant_Username = ?, First_Name = ?, Last_Name = ?, Email = ?, Contact_Number = ? WHERE Applicant_Username = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ssssss", $new_username, $first_name, $last_name, $email, $contact_number, $original_username);
            }

            if ($stmt->execute()) {
                $updated = true;
                $username = $new_username; // for re-fetching updated data
            } else {
                $error = "Error updating profile.";
            }

            $stmt->close();
        }
    }
    
    // Check if image was uploaded
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        $filename = $_FILES['profile_image']['name'];
        $filetype = $_FILES['profile_image']['type'];
        $filesize = $_FILES['profile_image']['size'];
        
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        
        if (!in_array(strtolower($ext), $allowed)) {
            $error = "Error: Please select a valid image format (JPG, JPEG, PNG, GIF).";
        } else if ($filesize > 5000000) { // 5MB max
            $error = "Error: File size must be less than 5MB.";
        } else {
            // Create a unique filename to prevent overwriting
            $new_filename = uniqid('mentor_') . '.' . $ext;
            $destination = 'uploads/' . $new_filename;
            
            // Make sure the uploads directory exists
            if (!file_exists('uploads')) {
                mkdir('uploads', 0777, true);
            }
            
            // Try to move the uploaded file
            if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $destination)) {
                $sql = "UPDATE Applications SET Mentor_Icon = ? WHERE Applicant_Username = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ss", $destination, $username);
                
                if ($stmt->execute()) {
                    $imageUploaded = true;
                } else {
                    $error = "Error updating profile image in database.";
                }
                
                $stmt->close();
            } else {
                $error = "Error uploading image. Please try again.";
            }
        }
    }
}

// Fetch latest mentor data
$sql = "SELECT Applicant_Username, First_Name, Last_Name, DOB, Gender, Email, Contact_Number, Applicant_Password, Mentor_Icon FROM Applications WHERE Applicant_Username = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();
$mentor = $result->fetch_assoc();

if (!$mentor) {
    die("Mentor not found.");
}

// Set default icon if missing
$mentor['Mentor_Icon'] = !empty($mentor['Mentor_Icon']) ? $mentor['Mentor_Icon'] : 'img/default_pfp.png';

// Display password as masked for security
$masked_password = "••••••••";

// FETCH Mentor name and icon based on username
$mentorUsername = $_SESSION['mentor_username'];
$sql = "SELECT First_Name, Last_Name, Mentor_Icon FROM Applications WHERE Applicant_Username = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $mentorUsername);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 1) {
  $row = $result->fetch_assoc();
  $_SESSION['mentor_first_name'] = $row['First_Name'];
  $_SESSION['mentor_last_name'] = $row['Last_Name'];
  
  // Check if Mentor_Icon exists and is not empty
  if (isset($row['Mentor_Icon']) && !empty($row['Mentor_Icon'])) {
    $_SESSION['mentor_icon'] = $row['Mentor_Icon'];
  } else {
    $_SESSION['mentor_icon'] = "img/default_pfp.png";
  }
} else {
  $_SESSION['mentor_first_name'] = "Unknown";
  $_SESSION['mentor_last_name'] = "Mentor";
  $_SESSION['mentor_icon'] = "img/default_pfp.png";
}

// FETCH Mentor_Name AND Mentor_Icon BASED ON Applicant_Username
$applicantUsername = $_SESSION['applicant_username'];
$sql = "SELECT CONCAT(First_Name, ' ', Last_Name) AS Mentor_Name, Mentor_Icon FROM applications WHERE Applicant_Username = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $applicantUsername);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 1) {
  $row = $result->fetch_assoc();
  $_SESSION['mentor_name'] = $row['Mentor_Name'];

  // Check if Mentor_Icon exists and is not empty
  if (isset($row['Mentor_Icon']) && !empty($row['Mentor_Icon'])) {
    $_SESSION['mentor_icon'] = $row['Mentor_Icon'];
  } else {
    $_SESSION['mentor_icon'] = "img/default_pfp.png";
  }
} else {
  $_SESSION['mentor_name'] = "Unknown Mentor";
  $_SESSION['mentor_icon'] = "img/default_pfp.png";
}

$stmt->close();
$conn->close();
?>

<!DOCTYPE html>

<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <link rel="stylesheet" href="css/admin_dashboardstyle.css" />
  <link rel="stylesheet" href="css/pfp.css" />
  <link rel="icon" href="coachicon.svg" type="image/svg+xml">
  <title>Mentor Profile</title>
</head>
<body>
<nav>
<div class="nav-top">
    <div class="logo">
      <div class="logo-image"><img src="img/logo.png" alt="Logo"></div>
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
      <a href="CoachMentorPFP.php?username=<?= urlencode($_SESSION['applicant_username']) ?>" class="edit-profile-link" title="Edit Profile">
        <ion-icon name="create-outline" class="verified-icon"></ion-icon>
      </a>
    </div>

  <div class="menu-items">
    <ul class="navLinks">
      <li class="navList">
        <a href="#" onclick="window.location='CoachMentor.php'">
          <ion-icon name="home-outline"></ion-icon>
          <span class="links">Home</span>
        </a>
      </li>
      <li class="navList">
        <a href="#" onclick="window.location='CoachMentorCourses.php'">
          <ion-icon name="book-outline"></ion-icon>
          <span class="links">Course</span>
        </a>
      </li>
      <li class="navList">
        <a href="#" onclick="window.location='mentor-sessions.php'">
          <ion-icon name="calendar-outline"></ion-icon>
          <span class="links">Sessions</span>
        </a>
      </li>
      <li class="navList">
        <a href="#" onclick="window.location='CoachMentorFeedback.php'">
          <ion-icon name="star-outline"></ion-icon>
          <span class="links">Feedbacks</span>
        </a>
      </li>
      <li class="navList">
        <a href="#" onclick="window.location='CoachMentorActivities.php'">
          <ion-icon name="clipboard"></ion-icon>
          <span class="links">Activities</span>
        </a>
      </li>
      <li class="navList">
        <a href="#" onclick="window.location='CoachMentorResource.php'">
          <ion-icon name="library-outline"></ion-icon>
          <span class="links">Resource Library</span>
        </a>
      </li>
    </ul>

<ul class="bottom-link">
  <li class="logout-link">
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
      <img src="img/logo.png" alt="Logo"> </div>

<div class="main-content">
    <h1 class="page-title">Mentor Profile Settings</h1>

<div class="profile-container">
    <div class="profile-image-section">
        <div class="profile-img-container">
            <img src="<?= htmlspecialchars($mentor['Mentor_Icon']) ?>" class="profile-img" alt="Profile Picture" id="profileImage">
            <div class="edit-icon" onclick="document.getElementById('profileImageUpload').click()">
                <ion-icon name="camera"></ion-icon>
            </div>
        </div>
        
        <form id="imageUploadForm" method="post" action="CoachMentorPFP.php" enctype="multipart/form-data">
            <input type="file" name="profile_image" id="profileImageUpload" class="hidden-file-input" accept="image/*" onchange="submitImageForm()">
            <input type="hidden" name="original_username" value="<?= htmlspecialchars($mentor['Applicant_Username']) ?>">
        </form>
        
        <?php if ($imageUploaded): ?>
            <div class="image-upload-message">Profile image updated successfully!</div>
        <?php endif; ?>
    </div>

    <div class="profile-info">
        <?php if ($updated): ?>
            <div class="message">Profile updated successfully!</div>
        <?php elseif ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" action="CoachMentorPFP.php" id="profileForm">
            <div class="form-group">
                <label>Username:</label>
                <input type="text" name="username" id="username" value="<?= htmlspecialchars($mentor['Applicant_Username']) ?>" class="disabled-input" readonly>
            </div>

            <div class="form-group">
                <label>First Name:</label>
                <input type="text" name="first_name" id="first_name" value="<?= htmlspecialchars($mentor['First_Name']) ?>" class="disabled-input" readonly>
            </div>

            <div class="form-group">
                <label>Last Name:</label>
                <input type="text" name="last_name" id="last_name" value="<?= htmlspecialchars($mentor['Last_Name']) ?>" class="disabled-input" readonly>
            </div>

            <div class="form-group">
                <label>Email:</label>
                <input type="email" name="email" id="email" value="<?= htmlspecialchars($mentor['Email']) ?>" class="disabled-input" readonly>
            </div>

            <div class="form-group">
                <label>Contact Number:</label>
                <input type="text" name="contact_number" id="contact_number" value="<?= htmlspecialchars($mentor['Contact_Number']) ?>" class="disabled-input" readonly>
            </div>

            <div class="form-group">
                <label>Date of Birth:</label>
                <input type="text" name="dob" id="dob" value="<?= htmlspecialchars($mentor['DOB']) ?>" class="disabled-input" readonly disabled>
            </div>

            <div class="form-group">
                <label>Gender:</label>
                <input type="text" name="gender" id="gender" value="<?= htmlspecialchars($mentor['Gender']) ?>" class="disabled-input" readonly disabled>
            </div>

            <div class="form-group">
                <label>Password:</label>
                <div class="password-container">
                    <input type="password" name="password" id="password" value="<?= $masked_password ?>" class="disabled-input" readonly data-original-password="<?= htmlspecialchars($mentor['Applicant_Password']) ?>">
                    <span class="toggle-password" onclick="togglePasswordVisibility()">
                        <ion-icon name="eye-outline"></ion-icon>
                    </span>
                </div>
            </div>

            <input type="hidden" name="original_username" value="<?= htmlspecialchars($mentor['Applicant_Username']) ?>">
            <input type="hidden" name="update_profile" value="1">
            <button type="button" id="editButton" class="action-btn" onclick="toggleEditMode()">Edit Profile</button>
            <button type="submit" id="updateButton" class="action-btn" style="display: none;">Update Profile</button>
        </form>
    </div>
</div>

</div>

<script src="admin.js"></script>

<script>
function confirmLogout() {
    if (confirm("Are you sure you want to logout?")) {
        window.location.href = "logout.php";
    }
}

function toggleEditMode() {
    const editButton = document.getElementById('editButton');
    const updateButton = document.getElementById('updateButton');
    const username = document.getElementById('username');
    const first_name = document.getElementById('first_name');
    const last_name = document.getElementById('last_name');
    const email = document.getElementById('email');
    const contact_number = document.getElementById('contact_number');
    const password = document.getElementById('password');
    
    // Toggle between edit and update modes
    if (editButton.textContent === 'Edit Profile') {
        // Enable editing
        username.readOnly = false;
        first_name.readOnly = false;
        last_name.readOnly = false;
        email.readOnly = false;
        contact_number.readOnly = false;
        password.readOnly = false;
        
        username.classList.remove('disabled-input');
        first_name.classList.remove('disabled-input');
        last_name.classList.remove('disabled-input');
        email.classList.remove('disabled-input');
        contact_number.classList.remove('disabled-input');
        password.classList.remove('disabled-input');
        
        // Clear the password field for security
        password.value = '';
        
        editButton.style.display = 'none';
        updateButton.style.display = 'inline-block';
    }
}

function togglePasswordVisibility() {
    const passwordField = document.getElementById('password');
    const toggleIcon = document.querySelector('.toggle-password ion-icon');
    
    if (passwordField.type === 'password') {
        passwordField.type = 'text';
        toggleIcon.setAttribute('name', 'eye-off-outline');
    } else {
        passwordField.type = 'password';
        toggleIcon.setAttribute('name', 'eye-outline');
    }
}

function submitImageForm() {
    // Show a preview of the selected image before submitting
    const fileInput = document.getElementById('profileImageUpload');
    const imagePreview = document.getElementById('profileImage');
    
    if (fileInput.files && fileInput.files[0]) {
        const reader = new FileReader();
        
        reader.onload = function(e) {
            imagePreview.src = e.target.result;
            
            // Submit the form after a short delay to show the preview
            setTimeout(function() {
                document.getElementById('imageUploadForm').submit();
            }, 500);
        }
        
        reader.readAsDataURL(fileInput.files[0]);
    }
}
</script>

<script type="module" src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.esm.js"></script>

<script nomodule src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.js"></script>

</body>
</html>