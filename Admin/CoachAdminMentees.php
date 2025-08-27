<?php
session_start(); // Start the session
// Connect to database
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "coach";

$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Handle Create
if (isset($_POST['create'])) {
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

    $sql = "INSERT INTO mentee_profiles 
            (First_Name, Last_Name, DOB, Gender, Username, Password, Email, Contact_Number, Full_Address, Student, Student_YearLevel, Occupation, ToLearn)
            VALUES
            ('$fname', '$lname', '$dob', '$gender', '$username_mentee', '$hashed_password', '$email', '$contact', '$address', '$student', '$grade', '$occupation', '$learning')";
    
    $conn->query($sql);
    header("Location: CoachAdminMentees.php?success=create");
    exit();
}

// Handle Update
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

    if (isset($_POST['update'])) {
        // ... (existing fields)
        $id = $_POST['id'];
        $updatePasswordClause = "";
    
        if (!empty($_POST['password'])) {
            $password = $_POST['password'];
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $updatePasswordClause = ", Password='$hashed_password'";
        }
    
        $sql = "UPDATE mentee_profiles SET 
                First_Name='$fname',
                Last_Name='$lname',
                DOB='$dob',
                Gender='$gender',
                Username='$username_mentee',
                Email='$email',
                Contact_Number='$contact',
                Full_Address='$address',
                Student='$student',
                Student_YearLevel='$grade',
                Occupation='$occupation',
                ToLearn='$learning'
                $updatePasswordClause
                WHERE Mentee_ID='$id'";
        
        $conn->query($sql);
        header("Location: CoachAdminMentees.php?success=update");
        exit();
    }    
}



// Fetch all mentees
$result = $conn->query("SELECT * FROM mentee_profiles");

if (!isset($_SESSION['admin_username'])) {
  header("Location: login_mentee.php");
  exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/admin_dashboardstyle.css"/>
    <link rel="stylesheet" href="css/admin_menteesstyle.css">
    <link rel="icon" href="coachicon.svg" type="image/svg+xml">
    <title>Manage Mentees</title>
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
            <li class="navList active">
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

<?php if (isset($_GET['success'])): ?>
<script>
    let message = "";
    <?php if ($_GET['success'] == 'create'): ?>
        message = "Create successful!";
    <?php elseif ($_GET['success'] == 'update'): ?>
        message = "Update successful!";
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

<h1>Manage Mentees</h1>

<div class="top-bar">
    <button onclick="showCreateForm()" class="create-btn">+ Create</button>

    <div class="search-box">
        <input type="text" id="searchInput" placeholder="Search mentees...">
        <button onclick="searchMentees()" class="search-btn"><ion-icon name="search-outline"></ion-icon></button>
    </div>
</div>

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
    <button type="button" id="editButton" class="update-btn">Edit</button>
    <button type="submit" name="update" id="updateButton" class="update-btn" style="display: none;">Update</button>
    <button type="button" onclick="hideCreateForm()" class="cancel-btn">Cancel</button>
</div>

    </form>
</div>

<table>
    <thead>
    <tr>
        <th style="color: white">ID</th>
        <th style="color: white">First Name</th>
        <th style="color: white">Last Name</th>
        <th style="color: white">Action</th>
    </tr>
    </thead>
    <tbody>
    <?php while($row = $result->fetch_assoc()): ?>
    <tr class="data-row">
        <td><?= $row['Mentee_ID'] ?></td>
        <td class="first-name"><?= htmlspecialchars($row['First_Name']) ?></td>
        <td class="last-name"><?= htmlspecialchars($row['Last_Name']) ?></td>
        <td>
            <button class="view-btn" onclick='viewMentee(this)' data-info='<?= json_encode(array_map('htmlspecialchars', $row)) ?>'>View</button>
        </td>
    </tr>
    <?php endwhile; ?>
    </tbody>
</table>

<div id="menteeDetails" class="form-container" style="display: none;">
    <h2>View / Edit Mentee Details</h2>
    <form method="POST" id="menteeForm">
              <div class="form-buttons">
    <button type="button" id="editButton" class="update-btn">Edit</button>
    <button type="submit" name="update" id="updateButton" class="update-btn" style="display: none;">Update</button>
    <button type="button" onclick="backToTable()" class="cancel-btn">Back</button>
</div>
        <input type="hidden" name="id" id="mentee_id">
        <div class="form-group"><label>First Name</label><input type="text" name="fname" id="fname" required readonly></div>
        <div class="form-group"><label>Last Name</label><input type="text" name="lname" id="lname" required readonly></div>
        <div class="form-group"><label>Date of Birth</label><input type="date" name="dob" id="dob" required readonly></div>
        <div class="form-group"><label>Gender</label><input type="text" name="gender" id="gender" required readonly></div>
        <div class="form-group"><label>Username</label><input type="text" name="username" id="username" required readonly></div>
        <div class="form-group"><label>Password</label><input type="password" name="password" id="password" readonly></div>
        <div class="form-group"><label>Email</label><input type="email" name="email" id="email" required readonly></div>
        <div class="form-group"><label>Contact Number</label><input type="text" name="contact" id="contact" required readonly></div>
        <div class="form-group"><label>Full Address</label><input type="text" name="address" id="address" required readonly></div>
        <div class="form-group"><label>Are you a Student?</label><input type="text" name="student" id="student" required readonly></div>
        <div class="form-group"><label>Year Level</label><input type="text" name="grade" id="grade" readonly></div>
        <div class="form-group"><label>Occupation</label><input type="text" name="occupation" id="occupation" readonly></div>
        <div class="form-group"><label>What they want to learn</label><textarea name="learning" id="learning" rows="3" required readonly></textarea></div>
      
    </form>
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

function searchMentees() {
    const input = document.getElementById('searchInput').value.toLowerCase();
    const rows = document.querySelectorAll('table tbody tr.data-row');

    rows.forEach(row => {
        const id = row.querySelector('td:first-child').innerText.toLowerCase();
        const firstName = row.querySelector('.first-name').innerText.toLowerCase();
        const lastName = row.querySelector('.last-name').innerText.toLowerCase();

        if (id.includes(input) || firstName.includes(input) || lastName.includes(input)) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}


let isViewing = false;

function viewMentee(button) {
    const form = document.getElementById('menteeDetails');
    const table = document.querySelector('table'); // hide table
    const topBar = document.querySelector('.top-bar'); // hide top bar
    table.style.display = 'none';
    form.style.display = 'block';

    const data = JSON.parse(button.getAttribute('data-info'));
    document.getElementById('mentee_id').value = data.Mentee_ID;
    document.getElementById('fname').value = data.First_Name;
    document.getElementById('lname').value = data.Last_Name;
    document.getElementById('dob').value = data.DOB;
    document.getElementById('gender').value = data.Gender;
    document.getElementById('username').value = data.Username;
    document.getElementById('password').value = data.Password;
    document.getElementById('email').value = data.Email;
    document.getElementById('contact').value = data.Contact_Number;
    document.getElementById('address').value = data.Full_Address;
    document.getElementById('student').value = data.Student;
    document.getElementById('grade').value = data.Student_YearLevel;
    document.getElementById('occupation').value = data.Occupation;
    document.getElementById('learning').value = data.ToLearn;
}



// Handle Edit button
document.getElementById('editButton').addEventListener('click', function () {
    document.querySelectorAll('#menteeForm input, #menteeForm textarea').forEach(el => {
        el.removeAttribute('readonly');
    });
    document.getElementById('editButton').style.display = 'none';
    document.getElementById('updateButton').style.display = 'inline-block';
});

document.addEventListener('DOMContentLoaded', () => {
    // Make sure all .navList elements are available
    const navLinks = document.querySelectorAll(".navList");
    const defaultTab = Array.from(navLinks).find(link => 
        link.textContent.trim() === "Mentees"
    );

    // Remove 'active' from all
    navLinks.forEach(link => link.classList.remove("active"));

    // Set default active tab to Resource Library
    if (defaultTab) {
        defaultTab.classList.add("active");
    }

    updateVisibleSections();
});

function confirmLogout() {
    var confirmation = confirm("Are you sure you want to log out?");
    if (confirmation) {
      // If the user clicks "OK", redirect to logout.php
      window.location.href = "logout.php";
    } else {
      // If the user clicks "Cancel", do nothing
      return false;
    }
  }

function backToTable() {
    document.getElementById('menteeDetails').style.display = 'none';
    document.querySelector('table').style.display = 'table';
    document.querySelector('.top-bar').style.display = 'flex';
}

</script>

</body>
</html>
