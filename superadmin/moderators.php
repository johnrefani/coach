<?php
session_start();

// --- ACCESS CONTROL ---
// Check if the user is logged in and if their user_type is 'Super Admin'
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'Super Admin') {
    // If not a Super Admin, redirect to the login page.
    header("Location: login.php");
    exit();
}

// --- FETCH SUPERADMIN DATA & ADMIN COUNT ---
require '../connection/db_connection.php';

// Handle Create
if (isset($_POST['create'])) {
  $username_admin = $_POST['username'];
  $name = $_POST['name']; 
  $email = $_POST['email']; // Added email collection
  $password = $_POST['password'];
  $hashed_password = password_hash($password, PASSWORD_DEFAULT);

  // Using prepared statements for better security
  $stmt = $conn->prepare("INSERT INTO admins (Admin_Username, Admin_Name, Admin_Password, Admin_Email) VALUES (?, ?, ?, ?)");
  $stmt->bind_param("ssss", $username_admin, $name, $hashed_password, $email);
  
  if ($stmt->execute()) {
    // Send email with login credentials
    $to = $email;
    $subject = "Your COACH Admin Access Credentials";
    
    // Create a professional HTML email template
    $message = "
    <html>
    <head>
      <style>
        body {
          font-family: Arial, sans-serif;
          line-height: 1.6;
          color: #333;
        }
        .container {
          max-width: 600px;
          margin: 0 auto;
          padding: 20px;
          border: 1px solid #ddd;
          border-radius: 5px;
          background-color:rgb(241, 223, 252);
        }
        .header {
          background-color: #562b63;
          padding: 15px;
          color: white;
          text-align: center;
          border-radius: 5px 5px 0 0;
        }#f9f9f9
        .content {
          padding: 20px;
          background-color: #f9f9f9;
        }
        .credentials {
          background-color: #fff;
          border: 1px solid #ddd;
          padding: 15px;
          margin: 15px 0;
          border-radius: 5px;
        }
        .footer {
          text-align: center;
          padding: 10px;
          font-size: 12px;
          color: #777;
        }
      </style>
    </head>
    <body>
      <div class='container'>
        <div class='header'>
          <h2>Welcome to COACH Admin Panel</h2>
        </div>
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
          <p>If you have any questions or need assistance, please contact the system administrator.</p>
        </div>
        <div class='footer'>
          <p>&copy; " . date("Y") . " COACH. All rights reserved.</p>
        </div>
      </div>
    </body>
    </html>
    ";
    
    // Set email headers for HTML content
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= "From: COACH System <noreply@yourwebsite.com>" . "\r\n";
    
    // Send email
    if(mail($to, $subject, $message, $headers)) {
      header("Location: CoachAdminAdmins.php?success=create&email=sent");
    } else {
      // Admin was created but email failed
      header("Location: CoachAdminAdmins.php?success=create&email=failed");
    }
    exit();
  } else {
    $error = "Error creating admin: " . $conn->error;
  }
  $stmt->close();
}

// Handle Update
if (isset($_POST['update'])) {
  $id = $_POST['id'];
  $username_admin = $_POST['username'];
  $name = $_POST['name'];
  $email = $_POST['email']; // Added email field
  
  // Using prepared statements for better security
  $stmt = $conn->prepare("UPDATE admins SET Admin_Name = ?, Admin_Username = ?, Admin_Email = ? WHERE Admin_ID = ?");
  $stmt->bind_param("sssi", $name, $username_admin, $email, $id);
  
  if ($stmt->execute()) {
    header("Location: CoachAdminAdmins.php?success=update");
    exit();
  } else {
    $error = "Error updating admin: " . $conn->error;
  }
  $stmt->close();
}

// Handle Delete if implemented
if (isset($_GET['delete'])) {
  $id = $_GET['delete'];
  
  $stmt = $conn->prepare("DELETE FROM admins WHERE Admin_ID = ?");
  $stmt->bind_param("i", $id);
  
  if ($stmt->execute()) {
    header("Location: CoachAdminAdmins.php?success=delete");
    exit();
  } else {
    $error = "Error deleting admin: " . $conn->error;
  }
  $stmt->close();
}

// Fetch all admins
$username = $_SESSION['username']; // Use the generic 'username' session from login
$stmt = $conn->prepare("SELECT first_name, last_name, icon FROM users WHERE username = ? AND user_type = 'Super Admin'");
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 1) {
    $row = $result->fetch_assoc();
    // Set specific session variables for display
    $_SESSION['superadmin_name'] = $row['first_name'] . ' ' . $row['last_name'];
    $_SESSION['superadmin_icon'] = !empty($row['icon']) ? $row['icon'] : 'img/default_pfp.png';
} else {
    // Default values if something goes wrong
    $_SESSION['superadmin_name'] = "Super Admin";
    $_SESSION['superadmin_icon'] = 'img/default_pfp.png';
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
    <link rel="icon" href="coachicon.svg" type="image/svg+xml">
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
        <li class="navList active">
          <a href="moderators.php">
            <ion-icon name="lock-closed-outline"></ion-icon>
            <span class="links">Moderators</span>
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
      <img src="../uploads/img/logo.png" alt="Logo"> </div>
      

<?php if (isset($_GET['success'])): ?>
<script>
    let message = "";
    <?php if ($_GET['success'] == 'create'): ?>
        message = "Create successful!";
    <?php elseif ($_GET['success'] == 'update'): ?>
        message = "Update successful!";
    <?php elseif ($_GET['success'] == 'delete'): ?>
        message = "Delete successful!";
    <?php endif; ?>

    if (message) {
        alert(message);
        // Remove ?success= from URL without refreshing the page
        if (history.replaceState) {
            const cleanUrl = window.location.protocol + "//" + window.location.host + window.location.pathname;
            history.replaceState(null, null, cleanUrl);
        }
    }
</script>
<?php endif; ?>

<h1>Manage Moderators</h1>

<div class="top-bar">
    <button onclick="showCreateForm()" class="create-btn">+ Create</button>

    <div class="search-box">
        <input type="text" id="searchInput" placeholder="Search admins...">
        <button onclick="searchAdmins()" class="search-btn"><ion-icon name="search-outline"></ion-icon></button>
    </div>
</div>

<!-- Create Admin Form -->
<div class="form-container" id="createForm" style="display:none;">
    <h2>Create New Moderator</h2>
    <form method="POST">
        <input type="hidden" name="create" value="1">

        <div class="form-group"><label>Name</label><input type="text" name="name" required></div>
        <div class="form-group"><label>Email</label><input type="email" name="email" required></div>
        <div class="form-group"><label>Username</label><input type="text" name="username" required></div>
        <div class="form-group">
            <label>Password</label>
            <div class="password-input-container">
                <input type="password" name="password" id="password" required>
                <button type="button" class="password-toggle" onclick="togglePasswordVisibility()">
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
      <?php while($row = $result->fetch_assoc()): ?>
      <tr class="data-row">
        <td><?= $row['Admin_ID'] ?></td>
        <td class="username"><?= htmlspecialchars($row['Admin_Username']) ?></td>
        <td class="name"><?= htmlspecialchars($row['Admin_Name']) ?></td>
        <td>
          <button class="view-btn" onclick='viewAdmin(this)' data-info='<?= json_encode($row, JSON_HEX_APOS | JSON_HEX_QUOT) ?>'>View</button>
        </td>
      </tr>
      <?php endwhile; ?>
    </tbody>
  </table>
</div>

<div id="detailView">
  <div id="adminDetails" class="form-container">
    <h2>View / Edit Moderators Details</h2>
    <form method="POST" id="adminForm">
      <div class="form-buttons">
        <button type="button" id="editButton" class="create-btn">Edit</button>
        <button type="submit" name="update" value="1" id="updateButton" class="create-btn" style="display: none;">Update</button>
        <button type="button" onclick="goBack()" class="cancel-btn">Back</button>
      </div>

      <input type="hidden" name="id" id="admin_id">
      <div class="form-group"><label>Name</label><input type="text" name="name" id="name" required readonly></div>
      <div class="form-group"><label>Email</label><input type="email" name="email" id="email" required readonly></div>
      <div class="form-group"><label>Username</label><input type="text" name="username" id="username" required readonly></div>

    </form>
  </div>
</div>


<script src="admin_mentees.js"></script>
  <script type="module" src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.js"></script>
<script>
function showCreateForm() {
    document.getElementById('createForm').style.display = 'block';
}

function hideCreateForm() {
    document.getElementById('createForm').style.display = 'none';
}

function searchAdmins() {
    const input = document.getElementById('searchInput').value.toLowerCase();
    const rows = document.querySelectorAll('table tbody tr.data-row');

    rows.forEach(row => {
        const id = row.querySelector('td:first-child').innerText.toLowerCase();
        const username = row.querySelector('.username').innerText.toLowerCase();
        const name = row.querySelector('.name').innerText.toLowerCase();

        if (id.includes(input) || username.includes(input) || name.includes(input)) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

let isViewing = false;

function viewAdmin(button) {
  const data = JSON.parse(button.getAttribute('data-info'));
  
  // Fill form fields
  document.getElementById('admin_id').value = data.Admin_ID;
  document.getElementById('name').value = data.Admin_Name;
  document.getElementById('username').value = data.Admin_Username;
  document.getElementById('email').value = data.Admin_Email; // Added email field population
  
  // Reset form state
  document.querySelectorAll('#adminForm input').forEach(el => {
      el.setAttribute('readonly', true);
  });
  document.getElementById('editButton').style.display = 'inline-block';
  document.getElementById('updateButton').style.display = 'none';
  
  // Toggle views
  document.getElementById('tableContainer').style.display = 'none';
  document.getElementById('detailView').style.display = 'block';
}

function goBack() {
  // Toggle views back
  document.getElementById('detailView').style.display = 'none';
  document.getElementById('tableContainer').style.display = 'block';
}

// Handle Edit button
document.getElementById('editButton').addEventListener('click', function() {
    document.querySelectorAll('#adminForm input').forEach(el => {
        el.removeAttribute('readonly');
    });
    document.getElementById('editButton').style.display = 'none';
    document.getElementById('updateButton').style.display = 'inline-block';
});

function confirmLogout() {
    var confirmation = confirm("Are you sure you want to log out?");
    if (confirmation) {
      // If the user clicks "OK", redirect to logout.php
      window.location.href = "../logout.php";
    } else {
      // If the user clicks "Cancel", do nothing
      return false;
    }
  }

  // Password visibility toggle function
function togglePasswordVisibility() {
    const passwordInput = document.getElementById('password');
    const toggleButton = document.querySelector('.password-toggle ion-icon');
    
    if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        toggleButton.setAttribute('name', 'eye-off-outline');
    } else {
        passwordInput.type = 'password';
        toggleButton.setAttribute('name', 'eye-outline');
    }
}

// Form validation and submission
document.addEventListener('DOMContentLoaded', function() {
    const createForm = document.querySelector('#createForm form');
    
    if (createForm) {
        createForm.addEventListener('submit', function(e) {
            const name = this.querySelector('input[name="name"]').value.trim();
            const email = this.querySelector('input[name="email"]').value.trim();
            const username = this.querySelector('input[name="username"]').value.trim();
            const password = this.querySelector('input[name="password"]').value;
            
            // Basic validation
            if (!name || !email || !username || !password) {
                e.preventDefault();
                alert('Please fill in all fields');
                return false;
            }
            
            // Email validation
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email)) {
                e.preventDefault();
                alert('Please enter a valid email address');
                return false;
            }
            
            // Password strength check
            if (password.length < 8) {
                e.preventDefault();
                alert('Password must be at least 8 characters long');
                return false;
            }
            
            // All validations passed, form will submit
            return true;
        });
    }
});

// Handle email notification status
window.addEventListener('load', function() {
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('success') === 'create') {
        const emailStatus = urlParams.get('email');
        if (emailStatus === 'sent') {
            alert('Admin created successfully and login credentials were sent to the provided email!');
        } else if (emailStatus === 'failed') {
            alert('Admin created successfully but failed to send email with credentials. Please provide the login details manually.');
        }
        
        // Clean URL
        if (history.replaceState) {
            const cleanUrl = window.location.protocol + "//" + window.location.host + window.location.pathname;
            history.replaceState(null, null, cleanUrl);
        }
    }
});

// Function to check username availability in real-time
function checkUsernameAvailability() {
    const usernameInput = document.querySelector('#createForm input[name="username"]');
    const usernameValue = usernameInput.value.trim();
    
    // Remove any existing message
    const existingMessage = document.getElementById('username-message');
    if (existingMessage) {
        existingMessage.remove();
    }
    
    // Don't check if username is empty
    if (!usernameValue) {
        return;
    }
    
    // Create a message element
    const messageElement = document.createElement('div');
    messageElement.id = 'username-message';
    messageElement.style.marginTop = '5px';
    messageElement.style.fontSize = '14px';
    
    // Show checking message
    messageElement.textContent = 'Checking username...';
    messageElement.style.color = '#666';
    usernameInput.parentNode.appendChild(messageElement);
    
    // Create form data for the AJAX request
    const formData = new FormData();
    formData.append('check', 'username');
    formData.append('username', usernameValue);
    
    // Send AJAX request to check username
    fetch('check_user_data.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.error) {
            messageElement.textContent = 'Error checking username';
            messageElement.style.color = '#f44336';
        } else if (data.exists) {
            messageElement.textContent = 'Username already exists';
            messageElement.style.color = '#f44336';
            usernameInput.setCustomValidity('Username already exists');
        } else {
            messageElement.textContent = 'Username available';
            messageElement.style.color = '#4CAF50';
            usernameInput.setCustomValidity('');
        }
    })
    .catch(error => {
        messageElement.textContent = 'Error checking username';
        messageElement.style.color = '#f44336';
        console.error('Error:', error);
    });
}

// Function to check email validity and availability
function checkEmailValidity() {
    const emailInput = document.querySelector('#createForm input[name="email"]');
    const emailValue = emailInput.value.trim();
    
    // Remove any existing message
    const existingMessage = document.getElementById('email-message');
    if (existingMessage) {
        existingMessage.remove();
    }
    
    // Don't check if email is empty
    if (!emailValue) {
        return;
    }
    
    // Create a message element
    const messageElement = document.createElement('div');
    messageElement.id = 'email-message';
    messageElement.style.marginTop = '5px';
    messageElement.style.fontSize = '14px';
    
    // Show checking message
    messageElement.textContent = 'Verifying email...';
    messageElement.style.color = '#666';
    emailInput.parentNode.appendChild(messageElement);
    
    // Create form data for the AJAX request
    const formData = new FormData();
    formData.append('check', 'email');
    formData.append('email', emailValue);
    
    // Send AJAX request to check email
    fetch('check_user_data.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.error) {
            messageElement.textContent = 'Error checking email';
            messageElement.style.color = '#f44336';
            emailInput.setCustomValidity('Error checking email');
        } else if (!data.valid) {
            messageElement.textContent = 'Invalid email format';
            messageElement.style.color = '#f44336';
            emailInput.setCustomValidity('Invalid email format');
        } else if (data.exists) {
            messageElement.textContent = 'Email already in use';
            messageElement.style.color = '#f44336';
            emailInput.setCustomValidity('Email already in use');
        } else if (!data.verified) {
            messageElement.textContent = 'Email domain could not be verified';
            messageElement.style.color = '#FFA500'; // Orange as a warning color
            emailInput.setCustomValidity(''); // Allow submission but with a warning
        } else {
            messageElement.textContent = 'Email valid and available';
            messageElement.style.color = '#4CAF50';
            emailInput.setCustomValidity('');
        }
    })
    .catch(error => {
        messageElement.textContent = 'Error checking email';
        messageElement.style.color = '#f44336';
        console.error('Error:', error);
    });
}

// Update the DOM ready event listener to include the username check
document.addEventListener('DOMContentLoaded', function() {
    const createForm = document.querySelector('#createForm form');
    const usernameInput = document.querySelector('#createForm input[name="username"]');
    const emailInput = document.querySelector('#createForm input[name="email"]');
    
    if (usernameInput) {
        // Add event listeners for real-time validation
        usernameInput.addEventListener('input', debounce(checkUsernameAvailability, 500));
        usernameInput.addEventListener('blur', checkUsernameAvailability);
    }
    
    if (emailInput) {
        // Add event listeners for real-time validation
        emailInput.addEventListener('input', debounce(checkEmailValidity, 800));
        emailInput.addEventListener('blur', checkEmailValidity);
    }
    
    // Your existing form validation code remains here
    if (createForm) {
        createForm.addEventListener('submit', function(e) {
            const name = this.querySelector('input[name="name"]').value.trim();
            const email = this.querySelector('input[name="email"]').value.trim();
            const username = this.querySelector('input[name="username"]').value.trim();
            const password = this.querySelector('input[name="password"]').value;
            
            // Check if username is valid before submitting
            const usernameMessage = document.getElementById('username-message');
            if (usernameMessage && usernameMessage.style.color === 'rgb(244, 67, 54)') {
                e.preventDefault();
                alert('Please choose a different username');
                return false;
            }
            
            // Check if email is valid before submitting
            const emailMessage = document.getElementById('email-message');
            if (emailMessage && emailMessage.style.color === 'rgb(244, 67, 54)') {
                e.preventDefault();
                alert('Please provide a valid email address');
                return false;
            }
            
            // Basic validation
            if (!name || !email || !username || !password) {
                e.preventDefault();
                alert('Please fill in all fields');
                return false;
            }
            
            // Email validation
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email)) {
                e.preventDefault();
                alert('Please enter a valid email address');
                return false;
            }
            
            // Password strength check
            if (password.length < 8) {
                e.preventDefault();
                alert('Password must be at least 8 characters long');
                return false;
            }
            
            // All validations passed, form will submit
            return true;
        });
    }
});

// Debounce function to prevent too many requests
function debounce(func, delay) {
    let timeout;
    return function() {
        const context = this;
        const args = arguments;
        clearTimeout(timeout);
        timeout = setTimeout(() => func.apply(context, args), delay);
    };
}

</script>

</body>
</html>
