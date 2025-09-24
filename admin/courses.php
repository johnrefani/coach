<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

// Standard session check for an admin user
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'Admin') {
    header("Location: ../login.php");
    exit();
}

// Use your standard database connection
require '../connection/db_connection.php';

// Ensure Category column exists
$checkColumnQuery = "SHOW COLUMNS FROM courses LIKE 'Category'";
$columnExists = $conn->query($checkColumnQuery);
if ($columnExists->num_rows == 0) {
    // Column doesn't exist, add it
    $conn->query("ALTER TABLE courses ADD COLUMN Category VARCHAR(10) DEFAULT 'all'");
}

// ARCHIVE COURSE
if (isset($_GET['archive'])) {
  $id = intval($_GET['archive']);
  $stmt = $conn->prepare("UPDATE courses SET Course_Status = 'Archive' WHERE Course_ID = ?");
  $stmt->bind_param("i", $id);
  if ($stmt->execute()) {
      header("Location: courses.php?status=archived");
  } else {
      // It's better to log errors than to die with a generic message
      error_log("Error archiving course: " . $stmt->error);
      header("Location: courses.php?status=error");
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
      header("Location: courses.php?status=activated");
  } else {
      error_log("Error activating course: " . $stmt->error);
      header("Location: courses.php?status=error");
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
  $editCategory = $_POST['edit_category'];
  $editImage = null; 

  if (isset($_FILES['edit_image']) && $_FILES['edit_image']['error'] === UPLOAD_ERR_OK) {
    $targetDir = "../uploads/";
    if (!is_dir($targetDir)) {
      mkdir($targetDir, 0777, true); 
    }
    $imageFileType = strtolower(pathinfo($_FILES["edit_image"]["name"], PATHINFO_EXTENSION));
    $safeFilename = 'course_edit_' . uniqid() . '.' . $imageFileType; 
    $targetFilePath = $targetDir . $safeFilename;
    $allowTypes = ['jpg','png','jpeg','gif','svg','webp'];
    if(in_array($imageFileType, $allowTypes)){
        if (move_uploaded_file($_FILES["edit_image"]["tmp_name"], $targetFilePath)) {
            $editImage = $safeFilename; 
        }
    }
  }

  if ($editImage !== null) {
    $stmt = $conn->prepare("UPDATE courses SET Course_Title=?, Course_Description=?, Skill_Level=?, Category=?, Course_Icon=? WHERE Course_ID=?");
    $stmt->bind_param("sssssi", $editTitle, $editDescription, $editLevel, $editCategory, $editImage, $editId);
  } else {
    $stmt = $conn->prepare("UPDATE courses SET Course_Title=?, Course_Description=?, Skill_Level=?, Category=? WHERE Course_ID=?");
    $stmt->bind_param("ssssi", $editTitle, $editDescription, $editLevel, $editCategory, $editId);
  }
  
  if ($stmt->execute()) {
    header("Location: courses.php?status=updated");
  } else {
    error_log("Error updating course: " . $stmt->error);
    header("Location: courses.php?status=error");
  }
  $stmt->close();
  exit;
}

// ADD NEW COURSE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['title']) && !isset($_POST['edit_id'])) {
  $title = $_POST['title'];
  $description = $_POST['description'];
  $level = $_POST['level'];
  $category = $_POST['category'];
  $imageName = ""; 

  if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
    $targetDir = "../uploads/";
    if (!is_dir($targetDir)) {
      mkdir($targetDir, 0777, true);
    }
    $imageFileType = strtolower(pathinfo($_FILES["image"]["name"], PATHINFO_EXTENSION));
    $safeFilename = 'course_add_' . uniqid() . '.' . $imageFileType;
    $targetFilePath = $targetDir . $safeFilename;
    $allowTypes = ['jpg','png','jpeg','gif','svg','webp'];
    if(in_array($imageFileType, $allowTypes)){
        if (move_uploaded_file($_FILES["image"]["tmp_name"], $targetFilePath)) {
            $imageName = $safeFilename; 
        }
    }
  }

  $stmt = $conn->prepare("INSERT INTO courses (Course_Title, Course_Description, Skill_Level, Category, Course_Icon, Course_Status) VALUES (?, ?, ?, ?, ?, 'Active')");
  $stmt->bind_param("sssss", $title, $description, $level, $category, $imageName);

  if ($stmt->execute()) {
    header("Location: courses.php?status=added");
  } else {
    error_log("Error adding course: " . $stmt->error);
    header("Location: courses.php?status=error");
  }
  $stmt->close();
  exit;
}

// FETCH ALL COURSES
$courses = [];
$result = $conn->query("SELECT Course_ID, Course_Title, Course_Description, Skill_Level, Category, Course_Icon, Course_Status FROM courses ORDER BY Course_ID DESC");
if ($result) {
  while ($row = $result->fetch_assoc()) {
    $row['Course_Status'] = $row['Course_Status'] ?? 'Active'; // Set default status if NULL
    $row['Category'] = $row['Category'] ?? 'all'; // Set default category if NULL
    $courses[] = $row;
  }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <link rel="stylesheet" href="css/dashboard.css" />
  <link rel="stylesheet" href="css/courses.css" />
   <link rel="icon" href="../uploads/img/coachicon.svg" type="image/svg+xml">
  <title>Manage Courses</title>
</head>
<body>
<nav>
  <div class="nav-top">
    <div class="logo">
      <div class="logo-image"><img src="../uploads/img/logo.png" alt="Logo"></div>
      <div class="logo-name">COACH</div>
    </div>
    <div class="admin-profile">
      <img src="<?php echo htmlspecialchars($_SESSION['user_icon']); ?>" alt="Admin Profile Picture" />
      <div class="admin-text">
        <span class="admin-name"><?php echo htmlspecialchars($_SESSION['user_full_name']); ?></span>
        <span class="admin-role">Moderator</span>
      </div>
      <a href="edit_profile.php?username=<?= urlencode($_SESSION['username']) ?>" class="edit-profile-link" title="Edit Profile">
        <ion-icon name="create-outline"></ion-icon>
      </a>
    </div>
  </div>
  <div class="menu-items">
    <ul class="navLinks">
        <li class="navList"><a href="dashboard.php"><ion-icon name="home-outline"></ion-icon><span class="links">Home</span></a></li>
        <li class="navList active"><a href="courses.php"><ion-icon name="book-outline"></ion-icon><span class="links">Courses</span></a></li>
        <li class="navList"><a href="manage_mentees.php"><ion-icon name="person-outline"></ion-icon><span class="links">Mentees</span></a></li>
        <li class="navList"><a href="manage_mentors.php"><ion-icon name="people-outline"></ion-icon><span class="links">Mentors</span></a></li>
        <li class="navList"><a href="manage_session.php"><ion-icon name="calendar-outline"></ion-icon><span class="links">Sessions</span></a></li>
        <li class="navList"><a href="feedbacks.php"><ion-icon name="star-outline"></ion-icon><span class="links">Feedback</span></a></li>
        <li class="navList"><a href="channels.php"><ion-icon name="chatbubbles-outline"></ion-icon><span class="links">Channels</span></a></li>
        <li class="navList"><a href="activities.php"><ion-icon name="clipboard-outline"></ion-icon><span class="links">Activities</span></a></li>
        <li class="navList"><a href="resource.php"><ion-icon name="library-outline"></ion-icon><span class="links">Resource Library</span></a></li>
        <li class="navList"><a href="reports.php"><ion-icon name="folder-outline"></ion-icon><span class="links">Reported Posts</span></a></li>
        <li class="navList"><a href="banned-users.php"><ion-icon name="person-remove-outline"></ion-icon><span class="links">Banned Users</span></a></li>
    </ul>
    <ul class="bottom-link">
      <li class="logout-link">
        <a href="#" onclick="confirmLogout()"><ion-icon name="log-out-outline"></ion-icon><span class="links">Logout</span></a>
      </li>
    </ul>
  </div>
</nav>

<section class="dashboard">
    <div class="top">
      <ion-icon class="navToggle" name="menu-outline"></ion-icon>
      <img src="../uploads/img/logo.png" alt="Logo">
    </div>
    
    <h1 class="section-title">Manage Courses</h1>
    
    <div id="addCourseSection">
        <div class="form-container">
            <h1>ADD A NEW COURSE</h1>
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
                <label for="category">Program Category</label>
                <select id="category" name="category" required>
                    <option value="">Select Category</option>
                    <option value="all">All</option>
                    <option value="IT">Information Technology</option>
                    <option value="CS">Computer Science</option>
                    <option value="DS">Data Science</option>
                    <option value="GD">Game Development</option>
                    <option value="DAT">Digital Animation</option>
                </select>
                <label for="image">Course Icon/Image</label>
                <input type="file" id="image" name="image" accept="image/*" />
                <button type="submit">SUBMIT</button>
            </form>
        </div>
        <div class="preview-container">
            <h1>Preview</h1>
            <div class="course-card" id="preview">
                <img src="" id="previewImage" alt="Course Icon Preview" style="display:none;"/>
                <h2 id="previewTitle">Course Title</h2>
                <p id="previewDescription">Course Description</p>
                <p><strong>Level:</strong> <span id="previewLevel">Skill Level</span></p>
                <p><strong>Program:</strong> <span id="previewCategory">Program Category</span></p>
                <button class="choose-btn" disabled>Choose</button>
            </div>
        </div>
    </div>

    <h1 class="section-title">All Courses</h1>
    
    <div class="filter-section" style="margin-bottom: 20px;">
        <h4>Filter by Category:</h4>
        <div class="category-filters" style="margin-bottom: 15px;">
            <button class="filter-btn active-filter" data-category="all">All</button>
            <button class="filter-btn" data-category="IT">Information Technology</button>
            <button class="filter-btn" data-category="CS">Computer Science</button>
            <button class="filter-btn" data-category="DS">Data Science</button>
            <button class="filter-btn" data-category="GD">Game Development</button>
            <button class="filter-btn" data-category="DAT">Digital Animation</button>
        </div>
        
        <h4>Filter by Status:</h4>
        <div class="status-filters">
            <button class="filter-btn" data-status="active">Active Courses</button>
            <button class="filter-btn" data-status="archived">Archived Courses</button>
        </div>
    </div>

    <div id="submittedCourses">
        <?php if (empty($courses)): ?>
            <p>No courses found.</p>
        <?php else: ?>
            <?php foreach ($courses as $course): ?>
                <?php 
                // Set default status if null
                $courseStatus = $course['Course_Status'] ?: 'Active';
                $displayStatus = strtolower($courseStatus);
                
                // Set default category if null
                $courseCategory = $course['Category'] ?: 'all';
                ?>
                <div class="course-card <?= ($courseStatus !== 'Archive') ? 'active-course' : 'archived-course' ?>" 
                     data-status="<?= ($courseStatus !== 'Archive') ? 'active' : 'archived' ?>" 
                     data-category="<?= htmlspecialchars($courseCategory) ?>"
                     style="<?= ($courseStatus === 'Archive') ? 'display: none;' : '' ?>">
                    <?php if (!empty($course['Course_Icon'])): ?>
                        <img src="../uploads/<?= htmlspecialchars($course['Course_Icon']); ?>" alt="Course Icon" />
                    <?php else: ?>
                        <div class="no-image">No Image</div>
                    <?php endif; ?>
                    <h2><?= htmlspecialchars($course['Course_Title']); ?></h2>
                    <p><?= nl2br(htmlspecialchars($course['Course_Description'])); ?></p>
                    <p><strong>Level:</strong> <?= htmlspecialchars($course['Skill_Level']); ?></p>
                    
                    <?php
                    // Map database codes to readable names
                    $categoryMap = [
                        'all' => 'All',
                        'IT'  => 'Information Technology',
                        'CS'  => 'Computer Science',
                        'DS'  => 'Data Science',
                        'GD'  => 'Game Development',
                        'DAT' => 'Digital Animation'
                    ];
                    
                    $categoryValue = isset($categoryMap[$course['Category']]) ? $categoryMap[$course['Category']] : ($course['Category'] ?: 'All');
                    ?>
                    
                    <p><strong>Program:</strong> <?= htmlspecialchars($categoryValue); ?></p>
                    
                    <!-- Status indicator -->
                    <p><strong>Status:</strong> 
                        <span class="status-badge <?= $displayStatus ?>">
                            <?= ucfirst($displayStatus) ?>
                        </span>
                    </p>
                    
                    <div class="card-actions">
                       <button onclick="openEditModal('<?= $course['Course_ID']; ?>', '<?= htmlspecialchars(addslashes($course['Course_Title'])); ?>', '<?= htmlspecialchars(addslashes($course['Course_Description'])); ?>', '<?= $course['Skill_Level']; ?>', '<?= $course['Category']; ?>')" class="edit-btn">Edit</button>
                       <?php if ($course['Course_Status'] === 'Archive'): ?>
                           <a href="?activate=<?= $course['Course_ID']; ?>" onclick="return confirm('Restore this course? \nTitle: <?= htmlspecialchars(addslashes($course['Course_Title'])); ?>')" class="activate-btn">Activate</a>
                       <?php else: ?>
                           <a href="?archive=<?= $course['Course_ID']; ?>" onclick="return confirm('Archive this course? \nTitle: <?= htmlspecialchars(addslashes($course['Course_Title'])); ?>')" class="delete-btn">Archive</a>
                       <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section> 

<!-- Edit Course Modal -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <span class="close-btn" onclick="closeEditModal()">&times;</span>
        <h2>Edit Course</h2>
        <form method="POST" enctype="multipart/form-data" id="editCourseForm">
            <input type="hidden" id="edit_id" name="edit_id">
            <label for="edit_title">Title</label>
            <input type="text" id="edit_title" name="edit_title" required>
            <label for="edit_description">Description</label>
            <textarea id="edit_description" name="edit_description" rows="4" required></textarea> 
            <label for="edit_level">Level</label>
            <select id="edit_level" name="edit_level" required>
                <option value="">Select Level</option>
                <option value="Beginner">Beginner</option>
                <option value="Intermediate">Intermediate</option>
                <option value="Advanced">Advanced</option>
            </select>
            <label for="edit_category">Program Category</label>
            <select id="edit_category" name="edit_category" required>
                <option value="">Select Category</option>
                <option value="all">All</option>
                <option value="IT">Information Technology</option>
                <option value="CS">Computer Science</option>
                <option value="DS">Data Science</option>
                <option value="GD">Game Development</option>
                <option value="DAT">Digital Animation</option>
            </select>
            <label for="edit_image">Change Image (optional)</label>
            <input type="file" id="edit_image" name="edit_image" accept="image/*">
            <div class="modal-actions">
                <button type="submit">Update Course</button>
                <button type="button" onclick="closeEditModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script type="module" src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.esm.js"></script>
<script nomodule src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    // Nav Toggle
    const navBar = document.querySelector("nav");
    const navToggle = document.querySelector(".navToggle");
    if(navToggle) {
        navToggle.addEventListener('click', () => navBar.classList.toggle('close'));
    }

    // Live Preview Logic for Add Form
    const titleInput = document.getElementById("title");
    const descriptionInput = document.getElementById("description");
    const levelSelect = document.getElementById("level");
    const categorySelect = document.getElementById("category");
    const imageInput = document.getElementById("image");
    const previewTitle = document.getElementById("previewTitle");
    const previewDescription = document.getElementById("previewDescription");
    const previewLevel = document.getElementById("previewLevel");
    const previewCategory = document.getElementById("previewCategory");
    const previewImage = document.getElementById("previewImage");

    // Category mapping for preview
    const categoryMap = {
        'all': 'All',
        'IT': 'Information Technology',
        'CS': 'Computer Science',
        'DS': 'Data Science',
        'GD': 'Game Development',
        'DAT': 'Digital Animation'
    };

    titleInput?.addEventListener("input", e => { previewTitle.textContent = e.target.value.trim() || "Course Title"; });
    descriptionInput?.addEventListener("input", e => { previewDescription.textContent = e.target.value.trim() || "Course Description"; });
    levelSelect?.addEventListener("change", e => { previewLevel.textContent = e.target.value || "Skill Level"; });
    categorySelect?.addEventListener("change", e => { previewCategory.textContent = categoryMap[e.target.value] || "Program Category"; });

    imageInput?.addEventListener("change", function() {
        const file = this.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = (e) => {
                previewImage.src = e.target.result;
                previewImage.style.display = "block";
            };
            reader.readAsDataURL(file);
        } else {
            previewImage.src = "";
            previewImage.style.display = "none";
        }
    });
    
    // Initialize filters
    initializeFilters();
    
    // Initial filter state - show active courses
    filterByStatus('active');
});

// Filter functionality
function initializeFilters() {
    const filterButtons = document.querySelectorAll('.filter-btn');
    
    filterButtons.forEach(button => {
        button.addEventListener('click', function() {
            const category = this.getAttribute('data-category');
            const status = this.getAttribute('data-status');
            
            // Remove active class from buttons in the same group
            if (category) {
                document.querySelectorAll('[data-category]').forEach(btn => btn.classList.remove('active-filter'));
            } else if (status) {
                document.querySelectorAll('[data-status]').forEach(btn => btn.classList.remove('active-filter'));
            }
            
            // Add active class to clicked button
            this.classList.add('active-filter');
            
            // Filter courses
            if (category) {
                filterByCategory(category);
            } else if (status) {
                filterByStatus(status);
            }
        });
    });
}

function filterByCategory(category) {
    const courseCards = document.querySelectorAll('#submittedCourses .course-card');
    
    courseCards.forEach(card => {
        const courseCategory = card.getAttribute('data-category');
        
        if (category === 'all' || courseCategory === category) {
            // Only show if it's also active (not archived)
            const courseStatus = card.getAttribute('data-status');
            if (courseStatus !== 'archived') {
                card.style.display = 'flex';
            }
        } else {
            card.style.display = 'none';
        }
    });
}

function filterByStatus(status) {
    const courseCards = document.querySelectorAll('#submittedCourses .course-card');
    
    courseCards.forEach(card => {
        const courseStatus = card.getAttribute('data-status');
        
        if (status === 'active' && courseStatus === 'active') {
            card.style.display = 'flex';
        } else if (status === 'archived' && courseStatus === 'archived') {
            card.style.display = 'flex';
        } else {
            card.style.display = 'none';
        }
    });
}

// Edit Modal Logic
function openEditModal(id, title, description, level, category) {
    document.getElementById('editModal').style.display = 'block';
    document.getElementById('edit_id').value = id;
    document.getElementById('edit_title').value = title;
    document.getElementById('edit_description').value = description;
    document.getElementById('edit_level').value = level;
    document.getElementById('edit_category').value = category;
}

function closeEditModal() {
    document.getElementById('editModal').style.display = 'none';
    document.getElementById('editCourseForm').reset();
}

function confirmLogout() {
    if (confirm("Are you sure you want to log out?")) {
        window.location.href = "../login.php";
    }
}
</script>
</body>
</html>