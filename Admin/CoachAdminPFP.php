<?php
session_start();
$conn = new mysqli("localhost", "root", "", "coach");

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$updated = false;
$error = '';
$admin = null;
$imageUploaded = false;

// Determine username
$username = $_GET['username'] ?? ($_POST['original_username'] ?? '');

if (empty($username)) {
    die("No username provided.");
}

// If form was submitted, process update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_username = $_POST['username'] ?? '';
    $name = $_POST['name'] ?? '';
    $password = $_POST['password'] ?? '';
    $original_username = $_POST['original_username'] ?? '';

    // Check if profile form was submitted
    if (isset($_POST['update_profile'])) {
        if (empty($new_username) || empty($name) || empty($password) || empty($original_username)) {
            $error = "All fields are required.";
        } else {
            // Check if the password has been changed by comparing with the stored hash
            $check_sql = "SELECT Admin_Password FROM admins WHERE Admin_Username = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param("s", $original_username);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            $current_data = $check_result->fetch_assoc();
            $check_stmt->close();
            
            // If password has changed, hash the new password
            if ($password !== $current_data['Admin_Password']) {
                // Password has been changed, hash it
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                
                $sql = "UPDATE admins SET Admin_Username = ?, Admin_Name = ?, Admin_Password = ? WHERE Admin_Username = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ssss", $new_username, $name, $hashed_password, $original_username);
            } else {
                // Password hasn't changed, use original hash
                $sql = "UPDATE admins SET Admin_Username = ?, Admin_Name = ? WHERE Admin_Username = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("sss", $new_username, $name, $original_username);
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
            $new_filename = uniqid('profile_') . '.' . $ext;
            $destination = 'uploads/' . $new_filename;
            
            // Make sure the uploads directory exists
            if (!file_exists('uploads')) {
                mkdir('uploads', 0777, true);
            }
            
            // Try to move the uploaded file
            if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $destination)) {
                $sql = "UPDATE admins SET Admin_Icon = ? WHERE Admin_Username = ?";
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

// Fetch latest admin data
$sql = "SELECT Admin_Username, Admin_Name, Admin_Password, Admin_Icon FROM admins WHERE Admin_Username = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();
$admin = $result->fetch_assoc();

if (!$admin) {
    die("Admin not found.");
}

// Set default icon if missing
$admin['Admin_Icon'] = !empty($admin['Admin_Icon']) ? $admin['Admin_Icon'] : 'img/default_pfp.png';

// Display password as masked for security
$masked_password = "••••••••";

// FETCH Admin_Name AND Admin_Icon BASED ON USERNAME
$adminUsername = $_SESSION['admin_username'];
$sql = "SELECT Admin_Name, Admin_Icon FROM admins WHERE Admin_Username = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $adminUsername);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 1) {
  $row = $result->fetch_assoc();
  $_SESSION['admin_name'] = $row['Admin_Name'];
  
  // Check if Admin_Icon exists and is not empty
  if (isset($row['Admin_Icon']) && !empty($row['Admin_Icon'])) {
    $_SESSION['admin_icon'] = $row['Admin_Icon'];
  } else {
    $_SESSION['admin_icon'] = "img/default_pfp.png";
  }
} else {
  $_SESSION['admin_name'] = "Unknown User";
  $_SESSION['admin_icon'] = "img/default_pfp.png";
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
  <title>Profile</title>
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
    <span class="admin-role">Moderator</span>
  </div>
  <a href="CoachAdminPFP.php?username=<?= urlencode($_SESSION['admin_username']) ?>" class="edit-profile-link" title="Edit Profile">
    <ion-icon name="create-outline" class="verified-icon"></ion-icon>
  </a>
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
            <li class="navList"> <a href="CoachAdminFeedback.php"> <ion-icon name="star-outline"></ion-icon>
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
      <li class="logout-link">
        <a href="#" onclick="confirmLogout()" style="color: white; text-decoration: none; font-size: 18px; margin-top: 70px;">
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
    <h1 class="page-title">Profile Settings</h1>
    
    <div class="profile-container">
        <div class="profile-image-section">
            <div class="profile-img-container">
                <img src="<?= htmlspecialchars($admin['Admin_Icon']) ?>" class="profile-img" alt="Profile Picture" id="profileImage">
                <div class="edit-icon" onclick="document.getElementById('profileImageUpload').click()">
                    <ion-icon name="camera"></ion-icon>
                </div>
            </div>
            
            <form id="imageUploadForm" method="post" action="CoachAdminPFP.php" enctype="multipart/form-data">
                <input type="file" name="profile_image" id="profileImageUpload" class="hidden-file-input" accept="image/*" onchange="submitImageForm()">
                <input type="hidden" name="original_username" value="<?= htmlspecialchars($admin['Admin_Username']) ?>">
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

            <form method="post" action="CoachAdminPFP.php" id="profileForm">
                <div class="form-group">
                    <label>Username:</label>
                    <input type="text" name="username" id="username" value="<?= htmlspecialchars($admin['Admin_Username']) ?>" class="disabled-input" readonly>
                </div>

                <div class="form-group">
                    <label>Name:</label>
                    <input type="text" name="name" id="name" value="<?= htmlspecialchars($admin['Admin_Name']) ?>" class="disabled-input" readonly>
                </div>

                <div class="form-group">
                    <label>Password:</label>
                    <div class="password-container">
                        <input type="password" name="password" id="password" value="<?= $masked_password ?>" class="disabled-input" readonly data-original-password="<?= htmlspecialchars($admin['Admin_Password']) ?>">
                        <span class="toggle-password" onclick="togglePasswordVisibility()">
                            <ion-icon name="eye-outline"></ion-icon>
                        </span>
                    </div>
                </div>

                <input type="hidden" name="original_username" value="<?= htmlspecialchars($admin['Admin_Username']) ?>">
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
    const name = document.getElementById('name');
    const password = document.getElementById('password');
    
    // Toggle between edit and update modes
    if (editButton.textContent === 'Edit Profile') {
        // Enable editing
        username.readOnly = false;
        name.readOnly = false;
        password.readOnly = false;
        
        username.classList.remove('disabled-input');
        name.classList.remove('disabled-input');
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