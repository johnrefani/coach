<?php
session_start(); // Start the session
$servername = "localhost";
$username = "root";
$password = ""; 
$dbname = "coach";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}

// Ensure Course_Status column exists
$checkColumnQuery = "SHOW COLUMNS FROM courses LIKE 'Course_Status'";
$columnExists = $conn->query($checkColumnQuery);
if ($columnExists->num_rows == 0) {
  // Column doesn't exist, add it
  $conn->query("ALTER TABLE courses ADD COLUMN Course_Status VARCHAR(20) DEFAULT NULL");
}

// ARCHIVE COURSE
if (isset($_GET['archive'])) {
  $id = intval($_GET['archive']);
  $stmt = $conn->prepare("UPDATE courses SET Course_Status = 'Archive' WHERE Course_ID = ?");
  $stmt->bind_param("i", $id);
  if ($stmt->execute()) {
      echo "<script>alert('Course archived.'); window.location='CoachAdminCourses.php';</script>";
  } else {
      echo "<script>alert('Error archiving course: " . $stmt->error . "'); window.location='CoachAdminCourses.php';</script>";
  }
  $stmt->close();
  exit;
}


// ACTIVATE COURSE
if (isset($_GET['activate'])) {
  $id = intval($_GET['activate']);
  $stmt = $conn->prepare("UPDATE courses SET Course_Status = 'Active' WHERE Course_ID = ?");
  $stmt->bind_param("i", $id);
  if ($stmt->execute()) {
      echo "<script>alert('Course activated.'); window.location='CoachAdminCourses.php';</script>";
  } else {
      echo "<script>alert('Error activating course: " . $stmt->error . "'); window.location='CoachAdminCourses.php';</script>";
  }
  $stmt->close();
  exit;
}

// EDIT COURSE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_id'])) {
  $editId = intval($_POST['edit_id']);
  $editTitle = $_POST['edit_title'];
  $editDescription = $_POST['edit_description'];
  $editLevel = $_POST['edit_level'];
  $editmentor = $_POST['edit_mentor'];
  $editImage = null; 

  if (isset($_FILES['edit_image']) && $_FILES['edit_image']['error'] === UPLOAD_ERR_OK) {
    $targetDir = "uploads/";
    if (!is_dir($targetDir)) {
      mkdir($targetDir, 0777, true); 
    }

    $imageFileType = strtolower(pathinfo($_FILES["edit_image"]["name"], PATHINFO_EXTENSION));
    $safeFilename = uniqid('course_edit_', true) . '.' . $imageFileType; 
    $targetFilePath = $targetDir . $safeFilename;
    $allowTypes = array('jpg','png','jpeg','gif','svg','webp');
    if(in_array($imageFileType, $allowTypes)){
        if (move_uploaded_file($_FILES["edit_image"]["tmp_name"], $targetFilePath)) {
            $editImage = $safeFilename; 
        } else {
            echo "<script>alert('Sorry, there was an error uploading your file.');</script>";
            $editImage = null; 
        }
    } else {
        echo "<script>alert('Sorry, only JPG, JPEG, PNG, GIF, SVG & WEBP files are allowed.');</script>";
        $editImage = null; 
    }
  }


  if ($editImage !== null) {
    $stmt = $conn->prepare("UPDATE courses SET Course_Title=?, Course_Description=?, Skill_Level=?, Assigned_Mentor=?, Course_Icon=? WHERE Course_ID=?");
    $stmt->bind_param("sssssi", $editTitle, $editDescription, $editLevel, $editmentor, $editImage, $editId);
  } else {
    $stmt = $conn->prepare("UPDATE courses SET Course_Title=?, Course_Description=?, Skill_Level=?, Assigned_Mentor=? WHERE Course_ID=?");
    $stmt->bind_param("ssssi", $editTitle, $editDescription, $editLevel, $editmentor, $editId);
  }
  

  if ($stmt->execute()) {
    echo "<script>alert('Course updated successfully!'); window.location='CoachAdminCourses.php';</script>";
  } else {
    echo "<script>alert('Error updating course: " . $stmt->error . "');</script>";
  }

  $stmt->close();
  exit;
}

// ADD NEW COURSE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['title'], $_POST['description'], $_POST['level'], $_POST['mentor']) && !isset($_POST['edit_id'])) {

  $title = $_POST['title'];
  $description = $_POST['description'];
  $level = $_POST['level'];
  $mentor = $_POST['mentor'];
  $imageName = ""; 

  // Handle file upload for course icon
  if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
    $targetDir = "uploads/";
    if (!is_dir($targetDir)) {
      mkdir($targetDir, 0777, true);
    }

    $imageFileType = strtolower(pathinfo($_FILES["image"]["name"], PATHINFO_EXTENSION));
    $safeFilename = uniqid('course_add_', true) . '.' . $imageFileType;
    $targetFilePath = $targetDir . $safeFilename;
    $allowTypes = array('jpg','png','jpeg','gif','svg','webp');
    if(in_array($imageFileType, $allowTypes)){
        if (move_uploaded_file($_FILES["image"]["tmp_name"], $targetFilePath)) {
            $imageName = $safeFilename; 
        } else {
            echo "<script>alert('Sorry, there was an error uploading your file.');</script>";
            $imageName = "";
        }
    } else {
        echo "<script>alert('Sorry, only JPG, JPEG, PNG, GIF, SVG & WEBP files are allowed.');</script>";
        $imageName = ""; 
    }
  }

  // Insert the course with 'Active' status
  $stmt = $conn->prepare("INSERT INTO courses (Course_Title, Course_Description, Skill_Level, Assigned_Mentor, Course_Icon, Course_Status) VALUES (?, ?, ?, ?, ?, 'Active')");
  $stmt->bind_param("sssss", $title, $description, $level, $mentor, $imageName);

  // Execute and provide feedback
  if ($stmt->execute()) {
    echo "<script>alert('Course added successfully!'); window.location='CoachAdminCourses.php';</script>";
  } else {
    echo "<script>alert('Error adding course: " . $stmt->error . "');</script>";
  }

  $stmt->close();
  exit;
}

// FETCH APPROVED MENTORS
$approvedMentors = [];
$mentorResult = $conn->query("SELECT First_Name, Last_Name FROM applications WHERE Status = 'Approved'");
if ($mentorResult && $mentorResult->num_rows > 0) {
    while ($mentor = $mentorResult->fetch_assoc()) {
        $approvedMentors[] = $mentor['First_Name'] . ' ' . $mentor['Last_Name'];
    }
}

// FETCH ALREADY ASSIGNED MENTORS
$assignedMentors = [];
$assignedResult = $conn->query("SELECT DISTINCT Assigned_Mentor FROM courses WHERE Assigned_Mentor IS NOT NULL AND Assigned_Mentor != ''");
if ($assignedResult && $assignedResult->num_rows > 0) {
    while ($row = $assignedResult->fetch_assoc()) {
        $assignedMentors[] = $row['Assigned_Mentor'];
    }
}

// FILTER TO GET ONLY APPROVED MENTORS NOT YET ASSIGNED
$mentors = array_diff($approvedMentors, $assignedMentors);

// FETCH COURSES
$courses = [];
$result = $conn->query("SELECT Course_ID, Course_Title, Course_Description, Skill_Level, Assigned_Mentor, Course_Icon, Course_Status FROM courses ORDER BY Course_ID DESC");
if ($result && $result->num_rows > 0) {
  while ($row = $result->fetch_assoc()) {
    // Ensure Course_Status is set to a default value if null
    if ($row['Course_Status'] === null) {
      $row['Course_Status'] = 'Active';
    }
    $courses[] = $row;
  }
}

$conn->close();

if (!isset($_SESSION['admin_username'])) {
  header("Location: login_mentee.php");
  exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <link rel="stylesheet" href="css/admin_dashboardstyle.css" />
  <link rel="stylesheet" href="css/admin_coursesstyle.css" />
  <link rel="icon" href="coachicon.svg" type="image/svg+xml">
  <title>Admin Dashboard</title>
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
            <li class="navList active">
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

    <div id="homeContent" style="padding: 20px;">
        <h1 class="section-title">Welcome to the Admin Dashboard</h1>
        <p>Select an option from the menu to manage different sections.</p>
        </div>
    <h1 class="section-title" id="courseTitle" style="display: none;">Manage Courses</h1>
    <div id="addCourseSection" style="padding: 20px; display: none; flex-wrap: wrap; gap: 20px;"> <div class="form-container" style="flex: 1; min-width: 300px;"> <h1>ADD A NEW COURSE</h1>
        <form method="POST" enctype="multipart/form-data" id="courseForm">
          <label for="title">Course Title</label>
          <input type="text" id="title" name="title" placeholder="Enter Course Title" required />

          <label for="description">Course Description</label>
          <textarea id="description" name="description" rows="3" placeholder="Enter Course Description" required></textarea>

          <label for="level">Skill Level</label>
          <select id="level" name="level" required>
            <option value="">Select Level</option>
            <option value="Beginner">Beginner</option>
            <option value="Intermediate">Intermediate</option>
            <option value="Advanced">Advanced</option>
          </select>

          <label for="mentor">Assigned Mentor</label>
          <select id="mentor" name="mentor" required>
            <option value="">Select Mentor</option>
            <?php foreach ($mentors as $mentorName): ?>
              <option value="<?php echo htmlspecialchars($mentorName); ?>">
              <?php echo htmlspecialchars($mentorName); ?>
              </option>
            <?php endforeach; ?>
          </select>

          <label for="image">Course Icon/Image</label>
          <input type="file" id="image" name="image" accept="image/*" />

          <button type="submit">SUBMIT</button>
        </form>
      </div>

      <div class="preview-container" style="flex: 1; min-width: 300px;"> <h1>Preview</h1>
        <div class="course-card" id="preview">
          <img src="" id="previewImage" alt="Course Icon Preview" style="display:none; max-width: 100%; height: auto; margin-bottom: 10px;"/>
          <h2 id="previewTitle">Course Title</h2>
          <p id="previewDescription">Course Description</p>
          <p><strong>Level:</strong> <span id="previewLevel">Skill Level</span></p>
          <p><strong>Mentor:</strong> <span id="previewMentor">Assigned Mentor</span></p>
          <button class="choose-btn">Choose</button>
          </div>
      </div>
    </div>

    <h1 class="section-title" id="submittedCoursesTitle" style="display: none;">All Courses</h1>

<div style="margin-bottom: 20px;">
  <button id="activeCoursesBtn" onclick="filterCourses('active')" class="filter-btn1 active-filter">Active</button>
  <button id="archivedCoursesBtn" onclick="filterCourses('archived')" class="filter-btn">Archived</button>
</div>

    <div id="submittedCourses" style="padding: 20px; display: flex; flex-wrap: wrap; gap: 20px;">
    <?php if (empty($courses)): ?>
    <p>No courses found.</p>
<?php else: ?>
    <?php foreach ($courses as $course): ?>
        <?php if ($course['Course_Status'] === 'Active' || $course['Course_Status'] === null): ?>
            <div class="course-card active-course" data-status="active" style="border: 1px solid #eee; padding: 15px; border-radius: 8px; flex: 1; min-width: 250px; max-width: 300px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        <?php else: ?>
            <div class="course-card archived-course" data-status="archived" style="border: 1px solid #eee; padding: 15px; border-radius: 8px; flex: 1; min-width: 250px; max-width: 300px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); display: none;">
        <?php endif; ?>
            <?php if (!empty($course['Course_Icon'])): ?>
                <img src="uploads/<?php echo htmlspecialchars($course['Course_Icon']); ?>" alt="Course Icon" style="max-width: 100%; height: auto; margin-bottom: 10px; border-radius: 4px;" />
            <?php else: ?>
                <div style="height: 100px; background: #f0f0f0; display: flex; align-items: center; justify-content: center; color: #999; margin-bottom: 10px; border-radius: 4px;">No Image</div>
            <?php endif; ?>
            <h2><?php echo htmlspecialchars($course['Course_Title']); ?></h2>
            <p style="text-align: center"><?php echo nl2br(htmlspecialchars($course['Course_Description'])); ?></p>
            <p><strong>Level:</strong> <?php echo htmlspecialchars($course['Skill_Level']); ?></p>
            <p><strong>Mentor:</strong> <?php echo htmlspecialchars($course['Assigned_Mentor']); ?></p>
            <div style="margin-top: 15px; display: flex; gap: 10px;">
               <button onclick="openEditModal(
                  '<?php echo $course['Course_ID']; ?>',
                  '<?php echo htmlspecialchars(addslashes($course['Course_Title'])); ?>',
                  '<?php echo htmlspecialchars(addslashes($course['Course_Description'])); ?>',
                  '<?php echo $course['Skill_Level']; ?>',
                  '<?php echo $course['Assigned_Mentor']; ?>'
                )" class="edit-btn" style="cursor: pointer;">Edit</button>
               
               <?php if ($course['Course_Status'] === 'Archive'): ?>
                   <a href="?activate=<?php echo $course['Course_ID']; ?>" onclick="return confirm('Are you sure you want to restore this course? \nTitle: <?php echo htmlspecialchars(addslashes($course['Course_Title'])); ?>')" class="activate-btn" style="cursor: pointer; text-decoration: none; background: linear-gradient(to right, #5d2c69, #8a5a96); color: white; padding: 8px 12px; border-radius: 4px;">Activate</a>
               <?php else: ?>
                   <a href="?archive=<?php echo $course['Course_ID']; ?>" onclick="return confirm('Are you sure you want to archive this course? \nTitle: <?php echo htmlspecialchars(addslashes($course['Course_Title'])); ?>')" class="delete-btn" style="cursor: pointer; text-decoration: none;">Archive</a>
               <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
</div>
    </section> 
    
    <div id="editModal"> <h2>Edit Course</h2>
    <form method="POST" enctype="multipart/form-data" id="editCourseForm"> <input type="hidden" id="edit_id" name="edit_id">

      <label for="edit_title">Title</label>
      <input type="text" id="edit_title" name="edit_title" required>

      <label for="edit_description">Description</label>
      <textarea id="edit_description" name="edit_description" rows="4" required></textarea> 
      <label for="edit_level">Level</label>
      <select id="edit_level" name="edit_level" required>
         <option value="">Select Level</option> <option value="Beginner">Beginner</option>
        <option value="Intermediate">Intermediate</option>
        <option value="Advanced">Advanced</option>
      </select>


          <label for="edit_mentor">Assigned Mentor</label>
          <select id="edit_mentor" name="edit_mentor" required>
            <option value="">Select Mentor</option>
            <?php foreach ($mentors as $mentor): ?>
              <option value="<?php echo htmlspecialchars($mentor); ?>"><?php echo htmlspecialchars($mentor); ?></option>
            <?php endforeach; ?>
          </select>

      <label for="edit_image">Change Image (optional)</label>
      <input type="file" id="edit_image" name="edit_image" accept="image/*"> <div id="current_image_display" style="margin-top: 10px;"></div>

      <div style="margin-top: 20px; text-align: right;">
         <button type="submit">Update Course</button>
         <button type="button" onclick="closeEditModal()">Cancel</button>
      </div>
    </form>
  </div>

  <script type="module" src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.js"></script>

  <script src="admin_courses.js"></script>

  <script>
    // Edit Modal Logic
    function openEditModal(id, title, description, level, mentor) {
  document.getElementById('edit_id').value = id;
  document.getElementById('edit_title').value = title;
  document.getElementById('edit_description').value = description;
  document.getElementById('edit_level').value = level;

  const mentorSelect = document.getElementById('edit_mentor');
  let mentorFound = false;

  // Check if mentor is in dropdown
  for (let option of mentorSelect.options) {
    if (option.value === mentor) {
      mentorFound = true;
      break;
    }
  }

  // If not found, append and disable the assigned mentor
  if (!mentorFound && mentor) {
    const option = document.createElement('option');
    option.value = mentor;
    option.textContent = mentor + " (already assigned)";
    option.disabled = true;
    option.selected = true;
    option.style.color = "#999";
    mentorSelect.appendChild(option);
  } else {
    mentorSelect.value = mentor;
  }

  document.getElementById('edit_image').value = ''; 

  // Show modal
  document.getElementById('editModal').style.display = 'block';
}

    function closeEditModal() {
      document.getElementById('editModal').style.display = 'none';
       document.getElementById('editCourseForm').reset(); 
    }

    // Live Preview Logic for Add Form
    const titleInput = document.getElementById("title");
    const descriptionInput = document.getElementById("description");
    const levelSelect = document.getElementById("level");
    const mentorSelect = document.getElementById("mentor");
    const imageInput = document.getElementById("image");
    const previewTitle = document.getElementById("previewTitle");
    const previewDescription = document.getElementById("previewDescription");
    const previewLevel = document.getElementById("previewLevel");
    const previewMentor = document.getElementById("previewMentor");
    const previewImage = document.getElementById("previewImage");

    if(titleInput) {
        titleInput.addEventListener("input", function() {
         previewTitle.textContent = this.value.trim() || "Course Title";
        });
    }
    if(descriptionInput) {
        descriptionInput.addEventListener("input", function() {
          previewDescription.textContent = this.value.trim() || "Course Description";
        });
    }
    if(levelSelect) {
        levelSelect.addEventListener("change", function() {
          previewLevel.textContent = this.value || "Skill Level";
        });
    }
    if(mentorSelect) {
        mentorSelect.addEventListener("change", function() {
          previewMentor.textContent = this.value || "Assigned Mentor";
        });
    }
    if(imageInput) {
        imageInput.addEventListener("change", function() {
          const file = this.files[0];
          if (file) {
            const reader = new FileReader();
            reader.onload = function(e) {
              previewImage.src = e.target.result;
              previewImage.style.display = "block";
            };
            reader.onerror = function() {
                console.error("Error reading file for preview.");
                previewImage.src = "";
                previewImage.style.display = "none";
            }
            reader.readAsDataURL(file);
          } else {
              previewImage.src = "";
              previewImage.style.display = "none";
          }
        });
    }

    document.getElementById("coursesLink").addEventListener("click", function(e) {
  e.preventDefault();

  fetch("coachadmincourses.php")
    .then(res => res.text())
    .then(data => {
      document.getElementById("mainContent").innerHTML = data;

      setTimeout(() => {
        const addSection = document.getElementById("addCourseSection");
        if (addSection) {

          const allSections = document.querySelectorAll("#mainContent > div");
          allSections.forEach(section => section.style.display = "none");

          addSection.style.display = "block";
        }
      }, 50);
    });
});

document.addEventListener('DOMContentLoaded', () => {
    const navLinks = document.querySelectorAll(".navList");
    const defaultTab = Array.from(navLinks).find(link => 
        link.textContent.trim() === "Courses"
    );

    navLinks.forEach(link => link.classList.remove("active"));

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

  // JavaScript for filtering courses
function filterCourses(status) {
  const activeCourses = document.querySelectorAll('.active-course');
  const archivedCourses = document.querySelectorAll('.archived-course');
  const activeBtn = document.getElementById('activeCoursesBtn');
  const archivedBtn = document.getElementById('archivedCoursesBtn');
  
  if (status === 'active') {
    activeCourses.forEach(course => course.style.display = 'block');
    archivedCourses.forEach(course => course.style.display = 'none');
    activeBtn.classList.add('active-filter');
    archivedBtn.classList.remove('active-filter');
  } else if (status === 'archived') {
    activeCourses.forEach(course => course.style.display = 'none');
    archivedCourses.forEach(course => course.style.display = 'block');
    activeBtn.classList.remove('active-filter');
    archivedBtn.classList.add('active-filter');
  }
}
  </script>
</body>
</html>