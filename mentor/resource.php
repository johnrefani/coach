<?php
session_start();
require '../connection/db_connection.php';
// SESSION CHECK: Updated to use user_id and user_type from the 'users' table
// This assumes your login script now sets $_SESSION['user_id'] and $_SESSION['user_type']
if (!isset($_SESSION['user_id']) || (isset($_SESSION['user_type']) && $_SESSION['user_type'] !== 'Mentor')) {
  // Redirect to a unified login page if not logged in or not a mentor
  header("Location: ../login.php");
  exit();
}

// DELETE resource: This logic remains largely the same as it relies on Resource_ID
if (isset($_GET['delete_resource'])) {
  $deleteResourceID = $_GET['delete_resource'];

  // First, get the file names to delete them from the server
  $sql_fetch_files = "SELECT Resource_Icon, Resource_File FROM resources WHERE Resource_ID = ?";
  $stmt_fetch = $conn->prepare($sql_fetch_files);
  $stmt_fetch->bind_param("i", $deleteResourceID);
  $stmt_fetch->execute();
  $result_fetch = $stmt_fetch->get_result();
  $files_to_delete = $result_fetch->fetch_assoc();
  $stmt_fetch->close();

  // Then, delete the record from the database
  $stmt = $conn->prepare("DELETE FROM resources WHERE Resource_ID = ?");
  $stmt->bind_param("i", $deleteResourceID);
  $stmt->execute();

  if ($stmt->affected_rows > 0) {
    // If database deletion is successful, delete the actual files
    if ($files_to_delete) {
      $icon_path = "../uploads/" . $files_to_delete['Resource_Icon'];
      $file_path = "../uploads/" . $files_to_delete['Resource_File'];
      if (!empty($files_to_delete['Resource_Icon']) && file_exists($icon_path)) {
        unlink($icon_path);
      }
      if (!empty($files_to_delete['Resource_File']) && file_exists($file_path)) {
        unlink($file_path);
      }
    }
    // Redirect back to the same page to show the updated list
    header("Location: resource.php");
    exit();
  } else {
    echo "<script>alert('Error: Resource not found or could not be deleted.'); window.location='resource.php';</script>";
    exit();
  }
  $stmt->close();
}

// CREATE resource: Updated to align with the 'users' table
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit'])) {
  $user_id = $_SESSION['user_id']; // Use the user_id from the session

  // Get the mentor's full name from the 'users' table to store in 'UploadedBy'
  $getMentor = $conn->prepare("SELECT first_name, last_name FROM users WHERE user_id = ?");
  $getMentor->bind_param("i", $user_id);
  $getMentor->execute();
  $mentorResult = $getMentor->get_result();
  if ($mentorResult->num_rows === 1) {
    $mentor = $mentorResult->fetch_assoc();
    $uploadedBy = $mentor['first_name'] . ' ' . $mentor['last_name'];
  } else {
    // Fallback name if user not found
    $uploadedBy = "Unknown Mentor";
  }
  $getMentor->close();

  // Retrieve form data
  $title = $_POST['resource_title'];
  $type = $_POST['resource_type'];
  $category = $_POST['resource_category'];

  // Handle icon file upload
  $icon = null;
  if (isset($_FILES['resource_icon']) && $_FILES['resource_icon']['error'] === UPLOAD_ERR_OK) {
    $icon_ext = strtolower(pathinfo($_FILES["resource_icon"]["name"], PATHINFO_EXTENSION));
    $icon_name = uniqid('icon_') . '.' . $icon_ext;
    $icon_target_path = "../uploads/" . $icon_name;
    if (move_uploaded_file($_FILES["resource_icon"]["tmp_name"], $icon_target_path)) {
      $icon = $icon_name;
    } else {
      echo "Error uploading icon file.";
    }
  }

  // Handle resource file upload
  $fileName = null;
  if (isset($_FILES['resource_file']) && $_FILES['resource_file']['error'] === UPLOAD_ERR_OK) {
    $file = $_FILES['resource_file'];
    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $fileName = uniqid('file_') . '.' . $file_ext;
    $targetDir = "../uploads/";
    $targetPath = $targetDir . $fileName;

    if (!file_exists($targetDir)) {
      mkdir($targetDir, 0777, true);
    }

    if (move_uploaded_file($file["tmp_name"], $targetPath)) {
      // UPDATED INSERT statement: uses 'user_id' instead of 'Applicant_Username'
      $stmt = $conn->prepare("INSERT INTO resources (user_id, UploadedBy, Resource_Title, Resource_Icon, Resource_Type, Category, Resource_File, Status) VALUES (?, ?, ?, ?, ?, ?, ?, 'Under Review')");
      // UPDATED bind_param: 'i' for integer user_id
      $stmt->bind_param("issssss", $user_id, $uploadedBy, $title, $icon, $type, $category, $fileName);

      if ($stmt->execute()) {
        echo "<script>alert('Resource successfully uploaded!'); window.location.href='resource.php';</script>";
        exit();
      } else {
        echo "Error uploading resource: " . $stmt->error;
      }
      $stmt->close();
    } else {
      echo "Error moving uploaded file.";
    }
  } else {
    echo "Resource file upload failed.";
  }
}

// FETCH resources: Updated to use 'user_id'
$resources = [];
if (isset($_SESSION['user_id'])) {
  $user_id = $_SESSION['user_id'];
  // UPDATED SELECT query to filter resources by the logged-in mentor's user_id
  $res = $conn->prepare("SELECT * FROM resources WHERE user_id = ? ORDER BY Resource_ID DESC");

  if ($res) {
    $res->bind_param("i", $user_id); // 'i' for integer
    if ($res->execute()) {
      $result = $res->get_result();
      if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
          $resources[] = $row;
        }
      }
    }
    $res->close();
  }
} else {
  error_log("Session variable 'user_id' is not set.");
}

// FETCH mentor details for navbar: Updated for the 'users' table
if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    // UPDATED query to get name, icon, and username from the 'users' table
    $sql = "SELECT username, CONCAT(first_name, ' ', last_name) AS mentor_name, icon FROM users WHERE user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $row = $result->fetch_assoc();
        $_SESSION['mentor_name'] = $row['mentor_name'];
        $_SESSION['username'] = $row['username']; // Stored for use in profile links
        // The icon column in 'users' table is named 'icon'
        $_SESSION['mentor_icon'] = !empty($row['icon']) ? $row['icon'] : "../uploads/img/default_pfp.png";
    } else {
        // Set default values if user is not found
        $_SESSION['mentor_name'] = "Unknown Mentor";
        $_SESSION['username'] = "unknown";
        $_SESSION['mentor_icon'] = "../uploads/img/default_pfp.png";
    }
    $stmt->close();
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <link rel="stylesheet" href="css/dashboard.css" />
  <link rel="stylesheet" href="css/resources.css" /> 
  <link rel="stylesheet" href="css/home.css" />
  <link rel="stylesheet" href="../superadmin/css/clock.css" />
  <link rel="icon" href="../uploads/coachicon.svg" type="image/svg+xml">
  <title>Mentor Dashboard</title>
</head>
<body>
<nav>
  <div class="nav-top">
    <div class="logo">
      <div class="logo-image"><img src="../uploads/img/logo.png" alt="Logo"></div>
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
      <a href="edit_profile.php?username=<?= urlencode($_SESSION['username']) ?>" class="edit-profile-link" title="Edit Profile">
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
        <a href="courses.php">
          <ion-icon name="book-outline"></ion-icon>
          <span class="links">Course</span>
        </a>
      </li>
      <li class="navList">
        <a href="sessions.php">
          <ion-icon name="calendar-outline"></ion-icon>
          <span class="links">Sessions</span>
        </a>
      </li>
      <li class="navList">
        <a href="feedbacks.php">
          <ion-icon name="star-outline"></ion-icon>
          <span class="links">Feedbacks</span>
        </a>
      </li>
      <li class="navList">
        <a href="activities.php">
          <ion-icon name="clipboard"></ion-icon>
          <span class="links">Activities</span>
        </a>
      </li>
      <li class="navList active">
        <a href="resource.php">
          <ion-icon name="library-outline"></ion-icon>
          <span class="links">Resource Library</span>
        </a>
      </li>
    </ul>

    <ul class="bottom-link">
      <li class="logout-link">
        <a href="#" onclick="confirmLogout()">
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

     <div id="resourceLibraryContent" style="padding: 20px;">
        <h1 class="section-title" id="resourceTitle" style="display: none;">Manage Resource Library</h1>

    <h1 class="section-title" id="resourceTitle">Manage Resource Library</h1>
    <div id="addResourceSection" style="padding: 20px; display: flex; flex-wrap: wrap; gap: 20px;">
        <div class="form-container" style="flex: 1; min-width: 300px;">
            <h1>ADD A NEW RESOURCE</h1>
            <form method="POST" enctype="multipart/form-data" id="resourceForm">

              <label for="resourceTitleInput">Resource Title</label>
              <input type="text" id="resourceTitleInput" name="resource_title" placeholder="Enter Resource Title" required />

              <label for="resourceType">Resource Type</label>
              <select id="resourceType" name="resource_type" required>
                <option value="">Select Type</option>
                <option value="Video">Video</option>
                <option value="PDF">PDF</option>
                <option value="PPT">PPT</option>
              </select>

              <label for="resourceCategory">Category</label>
              <select id="resourceCategory" name="resource_category" required>
                <option value="">Select Category</option>
                <option value="HTML">HTML</option>
                <option value="CSS">CSS</option>
                <option value="Java">Java</option>
                <option value="C#">C#</option>
                <option value="JS">JavaScript</option>
                <option value="PHP">PHP</option>
              </select>

              <label for="resourceIcon">Resource Icon/Image</label>
              <input type="file" id="resourceIcon" name="resource_icon" accept="image/*" />

              <label for="resourceFile">Upload Resource File</label>
              <input type="file" id="resourceFile" name="resource_file" accept=".pdf,.ppt,.pptx,.mp4,.avi,.mov,.wmv" required />

              <button type="submit" name="submit">SUBMIT</button>
            </form>
        </div>

        <div class="preview-container" style="flex: 1; min-width: 300px;">
            <h1>Preview</h1>
            <div class="resource-card" id="resourcePreview">
              <img src="" id="resourcePreviewImage" alt="Resource Icon Preview" style="display:none; max-width: 100%; height: auto; margin-bottom: 10px;" />
              <h2 id="resourcePreviewTitle">Resource Title</h2>
              <p><strong>Type:</strong> <span id="resourcePreviewType">Resource Type</span></p>
              <p id="resourceFileName" style="font-style: italic; color: #555;">No file selected</p>
              <button class="choose-btn">View</button>
            </div>
        </div>
    </div>

<h1 class="section-title">All Resources</h1>

<div class="button-wrapper">
<div id="categoryButtons" style="margin-bottom: 20px; display: flex; gap: 10px; flex-wrap: wrap;">
    <button class="category-btn active" data-category="all">All</button>
    <button class="category-btn" data-category="HTML">HTML</button>
    <button class="category-btn" data-category="CSS">CSS</button>
    <button class="category-btn" data-category="Java">Java</button>
    <button class="category-btn" data-category="C#">C#</button>
    <button class="category-btn" data-category="JS">JavaScript</button>
    <button class="category-btn" data-category="PHP">PHP</button>
    <button class="category-btn" data-category="Approved">Approved</button>
    <button class="category-btn" data-category="Under Review">Under Review</button>
    <button class="category-btn" data-category="Rejected">Rejected</button>
</div>
</div>

<div id="submittedResources" style="padding: 20px; display: flex; flex-wrap: wrap; gap: 20px;">
  <?php if (empty($resources)): ?>
    <p>No resources found.</p>
  <?php else: ?>
    <?php foreach ($resources as $resource): ?>
      <div class="resource-card"
           data-category="<?php echo htmlspecialchars($resource['Category']); ?>"
           data-status="<?php echo htmlspecialchars($resource['Status']); ?>"
           style="border: 1px solid #eee; padding: 15px; border-radius: 8px; flex: 1; min-width: 250px; max-width: 300px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        
        <?php if (!empty($resource['Resource_Icon']) && file_exists("../uploads/" . $resource['Resource_Icon'])): ?>
          <img src="../uploads/<?php echo htmlspecialchars($resource['Resource_Icon']); ?>" alt="Resource Icon" style="max-width: 100%; height: auto; margin-bottom: 10px; border-radius: 4px;" />
        <?php else: ?>
          <div style="height: 100px; background: #f0f0f0; display: flex; align-items: center; justify-content: center; color: #999; margin-bottom: 10px; border-radius: 4px;">No Image</div>
        <?php endif; ?>

        <h2><?php echo htmlspecialchars($resource['Resource_Title']); ?></h2>
        <p><strong>Type:</strong> <?php echo htmlspecialchars($resource['Resource_Type']); ?></p>
        
        <?php if (!empty($resource['Resource_File']) && file_exists("../uploads/" . $resource['Resource_File'])): ?>
            <p><strong>File:</strong> <a href="view_resource.php?file=<?php echo urlencode($resource['Resource_File']); ?>&title=<?php echo urlencode($resource['Resource_Title']); ?>" target="_blank" class="view-button">View</a></p>
        <?php else: ?>
             <p><strong>File:</strong> No file uploaded or file not found</p>
        <?php endif; ?>

       <p class='status-label'>
    <strong>STATUS:</strong> <span class="status"><?php echo htmlspecialchars($resource['Status']); ?></span>
</p>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

  <script type="module" src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.js"></script>
  
  <script>
    // General page scripts, like the navbar toggle and dark mode
    document.addEventListener('DOMContentLoaded', () => {
        const navBar = document.querySelector("nav");
        const navToggle = document.querySelector(".navToggle");

        if (navToggle && navBar) {
            navToggle.addEventListener('click', () => {
                navBar.classList.toggle('close');
            });
        }
    });
  </script>

  <script>
  // This script block contains the scripts SPECIFIC to the resource page functionality.
  
  // Live Preview for Resource Form
  const resTitleInput = document.getElementById("resourceTitleInput");
  const resTypeSelect = document.getElementById("resourceType");
  const resIconInput = document.getElementById("resourceIcon");
  const resFileInput = document.getElementById("resourceFile");

  const resPreviewTitle = document.getElementById("resourcePreviewTitle");
  const resPreviewType = document.getElementById("resourcePreviewType");
  const resPreviewImage = document.getElementById("resourcePreviewImage");
  const resFileName = document.getElementById("resourceFileName");

  // Category and Status Filtering
  const buttons = document.querySelectorAll('.category-btn');
  const resourceCards = document.querySelectorAll('#submittedResources .resource-card');

  buttons.forEach(button => {
    button.addEventListener('click', () => {
      buttons.forEach(btn => btn.classList.remove('active'));
      button.classList.add('active');

      const selected = button.getAttribute('data-category');

      resourceCards.forEach(card => {
        const cardCategory = card.getAttribute('data-category');
        const cardStatus = card.getAttribute('data-status');

        if (
          selected === 'all' ||
          cardCategory === selected ||
          cardStatus === selected
        ) {
          card.style.display = 'block';
        } else {
          card.style.display = 'none';
        }
      });
    });
  });

  // Update accepted file types based on resource type selection
  if(document.getElementById('resourceType')){
    document.getElementById('resourceType').addEventListener('change', function () {
        const fileInput = document.getElementById('resourceFile');
        const type = this.value.toLowerCase();

        let acceptTypes = '';
        if (type === 'pdf') acceptTypes = '.pdf';
        if (type === 'ppt') acceptTypes = '.ppt,.pptx';
        if (type === 'video') acceptTypes = '.mp4,.avi,.mov,.wmv';

        fileInput.value = ''; 
        fileInput.setAttribute('accept', acceptTypes);
        if(resFileName) resFileName.textContent = "No file selected";
    });
  }

  // Live preview for the add resource form
  if (resTitleInput) {
    resTitleInput.addEventListener("input", function () {
      resPreviewTitle.textContent = this.value.trim() || "Resource Title";
    });
  }

  if (resTypeSelect) {
    resTypeSelect.addEventListener("change", function () {
      resPreviewType.textContent = this.value || "Resource Type";
    });
  }

  if (resIconInput) {
    resIconInput.addEventListener("change", function () {
      const file = this.files[0];
      if (file) {
        const reader = new FileReader();
        reader.onload = function (e) {
          resPreviewImage.src = e.target.result;
          resPreviewImage.style.display = "block";
        };
        reader.readAsDataURL(file);
      } else {
        resPreviewImage.src = "";
        resPreviewImage.style.display = "none";
      }
    });
  }

  if (resFileInput) {
    resFileInput.addEventListener("change", function () {
      const file = this.files[0];
      resFileName.textContent = file ? file.name : "No file selected";
    });
  }

  // Logout Confirmation
  function confirmLogout() {
      var confirmation = confirm("Are you sure you want to log out?");
      if (confirmation) {
        window.location.href = "../logout.php";
      } else {
        return false;
      }
    }
  </script>

</body>
</html>