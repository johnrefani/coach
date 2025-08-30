<?php
session_start();
// Check if the logged-in user is a Super Admin
// The session now checks for 'user_id' and 'user_type' for better security and clarity
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'Super Admin') {
    header("Location: ../login.php");
    exit();
}

// Connect to the database
require '../connection/db_connection.php';

// Handle Create Operation
if (isset($_POST['create'])) {
    $username_admin = $_POST['username'];
    $name = $_POST['name']; 
    $email = $_POST['email'];
    $password = $_POST['password'];
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    
    // Split the full name into first and last names for the new database structure
    $name_parts = explode(' ', $name, 2);
    $first_name = $name_parts[0];
    $last_name = isset($name_parts[1]) ? $name_parts[1] : ''; // Handle cases with no last name

    // MODIFIED: Insert into the unified 'users' table with user_type 'Admin'
    $stmt = $conn->prepare("INSERT INTO users (username, first_name, last_name, password, email, user_type) VALUES (?, ?, ?, ?, ?, 'Admin')");
    $stmt->bind_param("sssss", $username_admin, $first_name, $last_name, $hashed_password, $email);
    
    if ($stmt->execute()) {
        // The email sending logic remains the same as it uses the POST variables
        $to = $email;
        $subject = "Your COACH Admin Access Credentials";
        $message = "
        <html>
        <head>
          <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 5px; background-color:rgb(241, 223, 252); }
            .header { background-color: #562b63; padding: 15px; color: white; text-align: center; border-radius: 5px 5px 0 0; }
            .content { padding: 20px; background-color: #f9f9f9; }
            .credentials { background-color: #fff; border: 1px solid #ddd; padding: 15px; margin: 15px 0; border-radius: 5px; }
            .footer { text-align: center; padding: 10px; font-size: 12px; color: #777; }
          </style>
        </head>
        <body>
          <div class='container'>
            <div class='header'><h2>Welcome to COACH Admin Panel</h2></div>
            <div class='content'>
              <p>Dear $name,</p>
              <p>You have been granted administrator access to the COACH system. Below are your login credentials:</p>
              <div class='credentials'>
                <p><strong>Username:</strong> $username_admin</p>
                <p><strong>Password:</strong> $password</p>
                <p><strong>Email:</strong> $email</p>
              </div>
              <p>Please log in at <a href='http://yourwebsite.com/loginadmin.php'>http://yourwebsite.com/loginadmin.php</a> using these credentials.</p>
              <p>For security reasons, we recommend changing your password after your first login.</p>
            </div>
            <div class='footer'><p>&copy; " . date("Y") . " COACH. All rights reserved.</p></div>
          </div>
        </body>
        </html>";
        
        $headers = "MIME-Version: 1.0" . "\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
        $headers .= "From: COACH System <noreply@yourwebsite.com>" . "\r\n";
        
        if(mail($to, $subject, $message, $headers)) {
            header("Location: moderators.php?success=create&email=sent");
        } else {
            header("Location: moderators.php?success=create&email=failed");
        }
        exit();
    } else {
        $error = "Error creating admin: " . $conn->error;
    }
    $stmt->close();
}

// Handle Update Operation
if (isset($_POST['update'])) {
    $id = $_POST['id'];
    $username_admin = $_POST['username'];
    $name = $_POST['name'];
    $email = $_POST['email'];
    
    // Split the full name for updating first_name and last_name columns
    $name_parts = explode(' ', $name, 2);
    $first_name = $name_parts[0];
    $last_name = isset($name_parts[1]) ? $name_parts[1] : '';

    // MODIFIED: Update the 'users' table where user_id matches and user_type is 'Admin'
    $stmt = $conn->prepare("UPDATE users SET first_name = ?, last_name = ?, username = ?, email = ? WHERE user_id = ? AND user_type = 'Admin'");
    $stmt->bind_param("ssssi", $first_name, $last_name, $username_admin, $email, $id);
    
    if ($stmt->execute()) {
        header("Location: moderators.php?success=update");
        exit();
    } else {
        $error = "Error updating admin: " . $conn->error;
    }
    $stmt->close();
}

// Handle Delete Operation
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    
    // MODIFIED: Delete from the 'users' table where user_id matches and user_type is 'Admin'
    $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ? AND user_type = 'Admin'");
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        header("Location: moderators.php?success=delete");
        exit();
    } else {
        $error = "Error deleting admin: " . $conn->error;
    }
    $stmt->close();
}

// Fetch all users with the user_type 'Admin'
$result = $conn->query("SELECT user_id, username, first_name, last_name, email, icon FROM users WHERE user_type = 'Admin'");

// Fetch SuperAdmin data for the navigation from the 'users' table
$username = $_SESSION['username'];
$stmt = $conn->prepare("SELECT first_name, last_name, icon FROM users WHERE username = ? AND user_type = 'Super Admin'");
$stmt->bind_param("s", $username);
$stmt->execute();
$admin_result = $stmt->get_result();

if ($admin_result->num_rows === 1) {
    $row = $admin_result->fetch_assoc();
    $_SESSION['user_full_name'] = $row['first_name'] . ' ' . $row['last_name'];
    
    if (isset($row['icon']) && !empty($row['icon'])) {
        $_SESSION['user_icon'] = $row['icon'];
    } else {
        $_SESSION['user_icon'] = "img/default_pfp.png"; 
    }
} else {
    $_SESSION['user_full_name'] = "Super Admin";
    $_SESSION['user_icon'] = "img/default_pfp.png";
}
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/dashboard.css" />
    <link rel="stylesheet" href="css/mentee.css">
    <link rel="icon" href="../uploads/coachicon.svg" type="image/svg+xml">
    <title>Manage Moderators</title>
</head>
<body>

<nav>
  <div class="nav-top">
    <div class="logo">
      <div class="logo-image"><img src="../uploads/img/logo.png" alt="Logo"></div>
      <div class="logo-name">COACH</div>
    </div>

    <div class="admin-profile">
      <img src="<?php echo htmlspecialchars($_SESSION['user_icon']); ?>" alt="SuperAdmin Profile Picture" />
      <div class="admin-text">
        <span class="admin-name">
          <?php echo htmlspecialchars($_SESSION['user_full_name']); ?>
        </span>
        <span class="admin-role">SuperAdmin</span>
      </div>
      <a href="dashboard.php?username=<?= urlencode($_SESSION['username']) ?>" class="edit-profile-link" title="Edit Profile">
        <ion-icon name="create-outline" class="verified-icon"></ion-icon>
      </a>
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
          <a href="moderators.php">
            <ion-icon name="lock-closed-outline"></ion-icon>
            <span class="links">Moderators</span>
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
      <img src="../uploads/img/logo.png" alt="Logo"> </div>

<?php if (isset($_GET['success'])): ?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        let message = "";
        const urlParams = new URLSearchParams(window.location.search);
        
        if (urlParams.get('success') === 'create') {
            const emailStatus = urlParams.get('email');
            if (emailStatus === 'sent') {
                message = 'Admin created successfully and login credentials were sent to the provided email!';
            } else if (emailStatus === 'failed') {
                message = 'Admin created successfully but failed to send email with credentials. Please provide the login details manually.';
            } else {
                message = "Create successful!";
            }
        } else if (urlParams.get('success') === 'update') {
            message = "Update successful!";
        } else if (urlParams.get('success') === 'delete') {
            message = "Delete successful!";
        }

        if (message) {
            alert(message);
            // Remove query params from URL without refreshing the page
            if (history.replaceState) {
                const cleanUrl = window.location.protocol + "//" + window.location.host + window.location.pathname;
                history.replaceState(null, null, cleanUrl);
            }
        }
    });
</script>
<?php endif; ?>

<h1>Manage Moderators</h1>

<div class="top-bar">
    <button onclick="showCreateForm()" class="create-btn">+ Create</button>

    <div class="search-box">
        <input type="text" id="searchInput" onkeyup="searchAdmins()" placeholder="Search moderators...">
        <button onclick="searchAdmins()" class="search-btn"><ion-icon name="search-outline"></ion-icon></button>
    </div>
</div>

<!-- Create Admin Form -->
<div class="form-container" id="createForm" style="display:none;">
    <h2>Create New Moderator</h2>
    <form method="POST" action="moderators.php">
        <input type="hidden" name="create" value="1">

        <div class="form-group"><label>Full Name</label><input type="text" name="name" required></div>
        <div class="form-group"><label>Email</label><input type="email" name="email" required></div>
        <div class="form-group"><label>Username</label><input type="text" name="username" required></div>
        <div class="form-group">
            <label>Password</label>
            <div class="password-input-container">
                <input type="password" name="password" id="create_password" required>
                <button type="button" class="password-toggle" onclick="togglePasswordVisibility('create_password')">
                    <ion-icon name="eye-outline"></ion-icon>
                </button>
            </div>
        </div>
        
        <div class="form-buttons">
            <button type="submit" class="create-btn">Save</button>
            <button type="button" onclick="hideCreateForm()" class="cancel-btn">Cancel</button>
        </div>
    </form>
</div>

<div id="tableContainer">
  <table>
    <thead>
      <tr>
        <th>ID</th>
        <th>Username</th>
        <th>Name</th>
        <th>Action</th>
      </tr>
    </thead>
    <tbody>
      <?php while($row = $result->fetch_assoc()): 
        // Combine first and last name for display and for the data-info attribute
        $row['full_name'] = $row['first_name'] . ' ' . $row['last_name'];
      ?>
      <tr class="data-row">
        <td><?= $row['user_id'] ?></td>
        <td class="username"><?= htmlspecialchars($row['username']) ?></td>
        <td class="name"><?= htmlspecialchars($row['full_name']) ?></td>
        <td>
          <button class="view-btn" onclick='viewAdmin(this)' data-info='<?= json_encode($row, JSON_HEX_APOS | JSON_HEX_QUOT) ?>'>View</button>
          <a href="moderators.php?delete=<?= $row['user_id'] ?>" class="delete-btn" onclick="return confirm('Are you sure you want to delete this moderator?');">Delete</a>
        </td>
      </tr>
      <?php endwhile; ?>
    </tbody>
  </table>
</div>

<div id="detailView" style="display:none;">
  <div id="adminDetails" class="form-container">
    <h2>View / Edit Moderator Details</h2>
    <form method="POST" id="adminForm" action="moderators.php">
      <input type="hidden" name="id" id="admin_id">
      <div class="form-group"><label>Name</label><input type="text" name="name" id="name" required readonly></div>
      <div class="form-group"><label>Email</label><input type="email" name="email" id="email" required readonly></div>
      <div class="form-group"><label>Username</label><input type="text" name="username" id="username" required readonly></div>
      
      <div class="form-buttons">
        <button type="button" id="editButton" class="create-btn">Edit</button>
        <button type="submit" name="update" value="1" id="updateButton" class="create-btn" style="display: none;">Update</button>
        <button type="button" onclick="goBack()" class="cancel-btn">Back</button>
      </div>
    </form>
  </div>
</div>
</section>

<script type="module" src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.esm.js"></script>
<script nomodule src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.js"></script>
<script>
function showCreateForm() {
    document.getElementById('createForm').style.display = 'block';
    document.getElementById('tableContainer').style.display = 'none';
}

function hideCreateForm() {
    document.getElementById('createForm').style.display = 'none';
    document.getElementById('tableContainer').style.display = 'block';
}

function searchAdmins() {
    const input = document.getElementById('searchInput').value.toLowerCase();
    const rows = document.querySelectorAll('table tbody tr.data-row');

    rows.forEach(row => {
        const username = row.querySelector('.username').innerText.toLowerCase();
        const name = row.querySelector('.name').innerText.toLowerCase();

        if (username.includes(input) || name.includes(input)) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

function viewAdmin(button) {
  // CORRECTED: Using updated keys from the 'users' table
  const data = JSON.parse(button.getAttribute('data-info'));
  
  document.getElementById('admin_id').value = data.user_id;
  document.getElementById('name').value = data.full_name; // 'full_name' is created in PHP loop
  document.getElementById('username').value = data.username;
  document.getElementById('email').value = data.email;
  
  // Reset form to read-only state
  document.querySelectorAll('#adminForm input').forEach(el => el.setAttribute('readonly', true));
  document.getElementById('editButton').style.display = 'inline-block';
  document.getElementById('updateButton').style.display = 'none';
  
  document.getElementById('tableContainer').style.display = 'none';
  document.getElementById('detailView').style.display = 'block';
}

function goBack() {
  document.getElementById('detailView').style.display = 'none';
  document.getElementById('tableContainer').style.display = 'block';
}

// Handle Edit button click
document.getElementById('editButton').addEventListener('click', function() {
    document.querySelectorAll('#adminForm input[readonly]').forEach(el => {
        el.removeAttribute('readonly');
    });
    // The hidden ID field should remain readonly/untouched
    document.getElementById('admin_id').setAttribute('readonly', true);
    
    this.style.display = 'none';
    document.getElementById('updateButton').style.display = 'inline-block';
});

function confirmLogout() {
    if (confirm("Are you sure you want to log out?")) {
      window.location.href = "../logout.php";
    }
}

// Password visibility toggle function
function togglePasswordVisibility(fieldId) {
    const passwordInput = document.getElementById(fieldId);
    const toggleButtonIcon = passwordInput.nextElementSibling.querySelector('ion-icon');
    
    if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        toggleButtonIcon.setAttribute('name', 'eye-off-outline');
    } else {
        passwordInput.type = 'password';
        toggleButtonIcon.setAttribute('name', 'eye-outline');
    }
}

// Navigation bar toggle
const navBar = document.querySelector("nav");
const navToggle = document.querySelector(".navToggle");
if (navToggle) {
    navToggle.addEventListener('click', () => {
        navBar.classList.toggle('close');
    });
}

</script>

</body>
</html>
<?php
$conn->close();
?>
