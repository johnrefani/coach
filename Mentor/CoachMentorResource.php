<?php
session_start();
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "coach";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}

// DELETE resource
if (isset($_GET['delete_resource'])) {
  $deleteResourceID = $_GET['delete_resource'];

  $sql_fetch_files = "SELECT Resource_Icon, Resource_File FROM resources WHERE Resource_ID = ?";
  $stmt_fetch = $conn->prepare($sql_fetch_files);
  $stmt_fetch->bind_param("i", $deleteResourceID);
  $stmt_fetch->execute();
  $result_fetch = $stmt_fetch->get_result();
  $files_to_delete = $result_fetch->fetch_assoc();
  $stmt_fetch->close();

  $stmt = $conn->prepare("DELETE FROM resources WHERE Resource_ID = ?");
  $stmt->bind_param("i", $deleteResourceID);
  $stmt->execute();

  if ($stmt->affected_rows > 0) {
    if ($files_to_delete) {
      $icon_path = "uploads/" . $files_to_delete['Resource_Icon'];
      $file_path = "uploads/" . $files_to_delete['Resource_File'];
      if (!empty($files_to_delete['Resource_Icon']) && file_exists($icon_path)) {
        unlink($icon_path);
      }
      if (!empty($files_to_delete['Resource_File']) && file_exists($file_path)) {
        unlink($file_path);
      }
    }
    header("Location: CoachMentorResource.php");
    exit();
  } else {
    echo "<script>alert('Error: Resource not found or could not be deleted.'); window.location='CoachMentorResource.php';</script>";
    exit();
  }
}

// CREATE resource
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit'])) {
  $applicantUsername = $_SESSION['applicant_username'];
  $getMentor = $conn->prepare("SELECT First_Name, Last_Name FROM applications WHERE Applicant_Username = ?");
  $getMentor->bind_param("s", $applicantUsername);
  $getMentor->execute();
  $mentorResult = $getMentor->get_result();
  if ($mentorResult->num_rows === 1) {
    $mentor = $mentorResult->fetch_assoc();
    $uploadedBy = $mentor['First_Name'] . ' ' . $mentor['Last_Name'];
  } else {
    $uploadedBy = "Unknown Mentor";
  }
  $getMentor->close();

  $title = $_POST['resource_title'];
  $type = $_POST['resource_type'];
  $category = $_POST['resource_category']; // ✅ NEW FIELD

  $icon = null;
  if (isset($_FILES['resource_icon']) && $_FILES['resource_icon']['error'] === UPLOAD_ERR_OK) {
    $icon_ext = strtolower(pathinfo($_FILES["resource_icon"]["name"], PATHINFO_EXTENSION));
    $icon_name = uniqid('icon_') . '.' . $icon_ext;
    $icon_target_path = "uploads/" . $icon_name;
    if (move_uploaded_file($_FILES["resource_icon"]["tmp_name"], $icon_target_path)) {
      $icon = $icon_name;
    } else {
      echo "Error uploading icon file.";
    }
  }

  $fileName = null;
  if (isset($_FILES['resource_file']) && $_FILES['resource_file']['error'] === UPLOAD_ERR_OK) {
    $file = $_FILES['resource_file'];
    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $fileName = uniqid('file_') . '.' . $file_ext;
    $targetDir = "uploads/";
    $targetPath = $targetDir . $fileName;

    if (!file_exists($targetDir)) {
      mkdir($targetDir, 0777, true);
    }

    if (move_uploaded_file($file["tmp_name"], $targetPath)) {
      $stmt = $conn->prepare("INSERT INTO resources (Applicant_Username, UploadedBy, Resource_Title, Resource_Icon, Resource_Type, Category, Resource_File, Status) VALUES (?, ?, ?, ?, ?, ?, ?, 'Under Review')");
      $stmt->bind_param("sssssss", $applicantUsername, $uploadedBy, $title, $icon, $type, $category, $fileName);

      if ($stmt->execute()) {
        echo "<script>alert('Resource successfully uploaded!'); window.location.href='CoachMentorResource.php';</script>";
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

// FETCH resources
$resources = [];
if (isset($_SESSION['applicant_username'])) {
  $applicantUsername = $_SESSION['applicant_username'];
  $res = $conn->prepare("SELECT * FROM resources WHERE Applicant_Username = ? ORDER BY Resource_ID DESC");

  if ($res) {
    $res->bind_param("s", $applicantUsername);
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
  error_log("Session variable 'applicant_username' is not set.");
}

// SESSION CHECK
if (!isset($_SESSION['applicant_username'])) {
  header("Location: login_mentor.php");
  exit();
}

// FETCH mentor name and icon
$applicantUsername = $_SESSION['applicant_username'];
$sql = "SELECT CONCAT(First_Name, ' ', Last_Name) AS Mentor_Name, Mentor_Icon FROM applications WHERE Applicant_Username = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $applicantUsername);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 1) {
  $row = $result->fetch_assoc();
  $_SESSION['mentor_name'] = $row['Mentor_Name'];
  $_SESSION['mentor_icon'] = !empty($row['Mentor_Icon']) ? $row['Mentor_Icon'] : "img/default_pfp.png";
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
  <link rel="stylesheet" href="css/mentor_dashboardstyle.css" />
  <link rel="stylesheet" href="css/mentor_resourcesstyle.css" /> 
  <link rel="stylesheet" href="css/mentorhomestyle.css" />
  <link rel="stylesheet" href="css/clockstyle.css" />
  <link rel="icon" href="coachicon.svg" type="image/svg+xml">
  <title>Mentor Dashboard</title>
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
      <li class="navList active">
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

<!-- CATEGORY FILTER BUTTONS -->
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
        
        <?php if (!empty($resource['Resource_Icon']) && file_exists("uploads/" . $resource['Resource_Icon'])): ?>
          <img src="uploads/<?php echo htmlspecialchars($resource['Resource_Icon']); ?>" alt="Resource Icon" style="max-width: 100%; height: auto; margin-bottom: 10px; border-radius: 4px;" />
        <?php else: ?>
          <div style="height: 100px; background: #f0f0f0; display: flex; align-items: center; justify-content: center; color: #999; margin-bottom: 10px; border-radius: 4px;">No Image</div>
        <?php endif; ?>

        <h2><?php echo htmlspecialchars($resource['Resource_Title']); ?></h2>
        <p><strong>Type:</strong> <?php echo htmlspecialchars($resource['Resource_Type']); ?></p>
        
        <?php if (!empty($resource['Resource_File']) && file_exists("uploads/" . $resource['Resource_File'])): ?>
            <p><strong>File:</strong> <a href="view_resource_mentor.php?file=<?php echo urlencode($resource['Resource_File']); ?>&title=<?php echo urlencode($resource['Resource_Title']); ?>" target="_blank" class="view-button">View</a></p>
        <?php else: ?>
             <p><strong>File:</strong> No file uploaded or file not found</p>
        <?php endif; ?>

        <?php 
          $status = htmlspecialchars($resource['Status']); 
          $statusClass = strtolower($status); // approved, rejected, pending
        ?>
        <p class="status-label <?php echo $statusClass; ?>">
          <strong>STATUS:</strong> <span class="status"><?php echo $status; ?></span>
        </p>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>


  <script type="module" src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.js"></script>

  <script src="mentor_resource.js"></script>

  <script>
  // This script block contains the inline JavaScript from the original file
  // It's generally better practice to move this to mentor_resource.js,
  // but keeping it here for now as it was in the original.

  // Live Preview for Resource Form
  const resTitleInput = document.getElementById("resourceTitleInput");
  const resTypeSelect = document.getElementById("resourceType");
  const resIconInput = document.getElementById("resourceIcon");
  const resFileInput = document.getElementById("resourceFile");

  const resPreviewTitle = document.getElementById("resourcePreviewTitle");
  const resPreviewType = document.getElementById("resourcePreviewType");
  const resPreviewImage = document.getElementById("resourcePreviewImage");
  const resFileName = document.getElementById("resourceFileName");

const buttons = document.querySelectorAll('.category-btn');
  const resourceCards = document.querySelectorAll('#submittedResources .resource-card');

  buttons.forEach(button => {
    button.addEventListener('click', () => {
      // Remove active class from all buttons, then add to the clicked one
      buttons.forEach(btn => btn.classList.remove('active'));
      button.classList.add('active');

      const selected = button.getAttribute('data-category');

      resourceCards.forEach(card => {
        const cardCategory = card.getAttribute('data-category');
        const cardStatus = card.getAttribute('data-status');

        // Show card if:
        // - selected is "all", or
        // - it matches the category, or
        // - it matches the status
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

  // Ensure the function exists globally or is accessible from the onclick attribute
  // Event listener to update file input accept attribute based on resource type
  document.getElementById('resourceType').addEventListener('change', function () {
    const fileInput = document.getElementById('resourceFile');
    const type = this.value.toLowerCase();

    let acceptTypes = '';
    if (type === 'pdf') acceptTypes = '.pdf';
    if (type === 'ppt') acceptTypes = '.ppt,.pptx';
    // Added more video formats
    if (type === 'video') acceptTypes = '.mp4,.avi,.mov,.wmv';

    fileInput.value = ''; // Clear current file when type changes
    fileInput.setAttribute('accept', acceptTypes);
     resFileName.textContent = "No file selected"; // Also clear preview text
  });

  // Function to update file input accept attribute and hint for edit modal
  function updateFileAcceptType(prefix) {
    const typeSelect = document.getElementById(`${prefix}ResourceType`);
    const fileInput = document.getElementById(`${prefix}ResourceFile`);
    const hint = document.getElementById(`${prefix}FileHint`);

    let acceptTypes = '';
    let hintText = '';

    switch (typeSelect.value.toLowerCase()) {
      case 'pdf':
        acceptTypes = '.pdf';
        hintText = 'Allowed: .pdf';
        break;
      case 'ppt':
        acceptTypes = '.ppt,.pptx';
        hintText = 'Allowed: .ppt, .pptx';
        break;
      case 'video':
        acceptTypes = '.mp4,.avi,.mov,.wmv';
        hintText = 'Allowed: .mp4, .avi, .mov, .wmv';
        break;
      default:
        acceptTypes = '';
        hintText = '';
    }

    fileInput.setAttribute('accept', acceptTypes);
    if (hint) hint.textContent = hintText;
  }


  // Function to preview image file before upload
  function previewImage(event, previewId) {
    const file = event.target.files[0];
    const imgPreview = document.getElementById(previewId);
    if (file) {
      const reader = new FileReader();
      reader.onload = () => {
        imgPreview.src = reader.result;
        imgPreview.style.display = "block";
      };
      reader.readAsDataURL(file);
    } else {
      imgPreview.src = "";
      imgPreview.style.display = "none";
    }
  }

  // Function to preview different file types
  function previewFileByType(type, filePath, containerId) {
    const previewContainer = document.getElementById(containerId);
    previewContainer.innerHTML = ''; // Clear previous preview

    if (!filePath || !type) return;

    const fileExtension = filePath.split('.').pop().toLowerCase();

    if (type.toLowerCase() === 'pdf' && fileExtension === 'pdf') {
      previewContainer.innerHTML = `<embed src="${filePath}" type="application/pdf" width="100%" height="300px" />`;
    } else if (type.toLowerCase() === 'ppt' && ['ppt', 'pptx'].includes(fileExtension)) {
      previewContainer.innerHTML = `<p>📄 Current File: <a href="${filePath}" target="_blank">Download/View PPT</a></p>`;
    } else if (type.toLowerCase() === 'video' && ['mp4', 'avi', 'mov', 'wmv'].includes(fileExtension)) {
      previewContainer.innerHTML = `
        <video controls width="100%">
          <source src="${filePath}" type="video/${fileExtension}">
          Your browser does not support the video tag.
        </video>
      `;
    } else {
        // Fallback for other file types or if file doesn't match type
        previewContainer.innerHTML = `<p>🔗 Current File: <a href="${filePath}" target="_blank">${filePath.split('/').pop()}</a></p>`;
    }
  }

  // Function to preview newly uploaded file (before saving)
  function previewUploadedFile(event, containerId) {
    const file = event.target.files[0];
    const previewContainer = document.getElementById(containerId);
    previewContainer.innerHTML = ''; // Clear previous preview

    if (!file) return;

    const fileType = file.type;
    const fileURL = URL.createObjectURL(file);

    if (fileType === 'application/pdf') {
      previewContainer.innerHTML = `<embed src="${fileURL}" type="application/pdf" width="100%" height="300px" />`;
    } else if (fileType.startsWith('video/')) {
      previewContainer.innerHTML = `
        <video controls width="100%">
          <source src="${fileURL}" type="${fileType}">
        </video>
      `;
    } else {
      previewContainer.innerHTML = `<p>📄 New File Selected: ${file.name}</p>`;
    }
  }


  // Initialize the accept attribute and hint when the modal opens (optional, but good practice)
  // You might want to call updateFileAcceptType('edit') when the edit modal is opened
   document.addEventListener("DOMContentLoaded", function() {
      // Initial call if the edit modal might be displayed on page load (unlikely here, but for completeness)
     // updateFileAcceptType('edit');
  });


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

  document.getElementById("resourceLibraryLink").addEventListener("click", function(e) {
    e.preventDefault(); // Prevent default link behavior

    // Load the resource page content via fetch
    fetch("CoachMentorResource.php")
      .then(res => {
        if (!res.ok) {
          console.error('Error fetching resource content:', res.statusText);
          return; // Or handle the error appropriately
        }
        return res.text();
      })
      .then(data => {
        const mainContent = document.getElementById("mainContent");
        if (mainContent) {
             mainContent.innerHTML = data;

            setTimeout(() => {
              const addSection = document.getElementById("addResourceSection");
              if (addSection) {
                // Hide other sections if needed
                const allSections = mainContent.querySelectorAll(":scope > div"); // Use :scope to select direct children within mainContent
                allSections.forEach(section => section.style.display = "none");

                // Show the desired one
                addSection.style.display = "block";
                // Also show related titles if they exist within the loaded content
                const resourceTitleLoaded = mainContent.querySelector("#resourceTitle");
                const submittedResourcesLoaded = mainContent.querySelector("#submittedResources");
                 if(resourceTitleLoaded) resourceTitleLoaded.style.display = "block";
                 if(submittedResourcesLoaded) submittedResourcesLoaded.style.display = "flex"; // Or block, depending on your CSS
              }
            }, 50);
        }
      })
      .catch(error => {
          console.error('Error fetching resource content:', error);
      });
  });


  document.addEventListener('DOMContentLoaded', () => {
      // Make sure all .navList elements are available
      const navLinks = document.querySelectorAll(".navList");
      // Find the Resource Library link specifically to set it as default active if needed
      const resourceLibraryLinkElement = document.getElementById("resourceLibraryLink");
      const defaultTab = resourceLibraryLinkElement ? resourceLibraryLinkElement.closest('.navList') : null;


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
  </script>

</body>
</html>