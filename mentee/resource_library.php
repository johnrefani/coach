<?php
session_start();

// --- ACCESS CONTROL ---
// Check if the user is logged in and if their user_type is 'Mentee'
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'Mentee') {
    header("Location: login.php");
    exit();
}

// --- DATABASE CONNECTION & FETCH USER ACCOUNT ---
require '../connection/db_connection.php';

$firstName = '';
$menteeIcon = '';
$defaultIcon = '../uploads/img/default_pfp.png'; // default PFP

// Get username from session
$username = $_SESSION['username'];

// Fetch user info
$sql = "SELECT first_name, icon FROM users WHERE username = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $firstName = $row['first_name'];
    $menteeIcon = $row['icon'];

    // --- Set display icon ---
    if (!empty($menteeIcon) && file_exists("../uploads/" . $menteeIcon)) {
        $displayIcon = "../uploads/" . $menteeIcon; // user icon path
    } else {
        $displayIcon = $defaultIcon; // default icon
    }
} else {
    $displayIcon = $defaultIcon; // fallback
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <link rel="stylesheet" href="css/navbar.css" />
  <link rel="stylesheet" href="css/courses.css" />
  <link rel="icon" href="../uploads/img/coachicon.svg" type="image/svg+xml">
  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
  <title>Resource Library</title>
</head>
<body>

<section class="background" id="home">
  <nav class="navbar">
    <div class="logo">
      <img src="../uploads/img/LogoCoach.png" alt="Logo">
      <span>COACH</span>
    </div>

    <div class="nav-center">
      <ul class="nav_items" id="nav_links">
        <li><a href="home.php">Home</a></li>
        <li><a href="course.php">Courses</a></li>
        <li><a href="resource_library.php">Resource Library</a></li>
        <li><a href="activities.php">Activities</a></li>
        <li><a href="forum-chat.php">Sessions</a></li>
        <li><a href="forums.php">Forums</a></li>
      </ul>
    </div>

<div class="nav-profile">
  <a href="#" id="profile-icon">
    <img src="<?php echo htmlspecialchars($displayIcon); ?>" 
         alt="User Icon" 
         style="width: 35px; height: 35px; border-radius: 50%;">
  </a>
</div>

<div class="sub-menu-wrap hide" id="profile-menu">
  <div class="sub-menu">
    <div class="user-info">
      <div class="user-icon">
        <img src="<?php echo htmlspecialchars($displayIcon); ?>" 
             alt="User Icon" 
             style="width: 40px; height: 40px; border-radius: 50%;">
      </div>
      <div class="user-name"><?php echo htmlspecialchars($firstName); ?></div>
    </div>
    <ul class="sub-menu-items">
      <li><a href="profile.php">Profile</a></li>
      <li><a href="taskprogress.php">Progress</a></li>
      <li><a href="#" onclick="confirmLogout()">Logout</a></li>
    </ul>
  </div>
</div>
  </nav>
</section>

  <section>
    <div class="resource-container">
      <div class="resource-left">
        <img src="../uploads/img/books3d.png" alt="Stack of Books" class="books"/>
      </div>
      <div class="resource-right">
        <h1>Learn more by reading files shared by your mentors!</h1>
        <p>
          Access valuable PowerPoint presentations, PDF files, and Video tutorials to enhance your learning journey 🚀
        </p>
        <div class="search-container">
          <input type="text" id="search-box" placeholder="Find resources by title or keyword...">
          <button onclick="performSearch()"><ion-icon name="search-outline"></ion-icon></button>
        </div>
      </div>
    </div>

    <div class="title">Check out the resources you can learn from!</div>
   <div class="button-wrapper">
  <div id="categoryButtons" style="margin-bottom: 20px; display: flex; gap: 10px; flex-wrap: wrap;">
        <button class="category-btn active" data-category="all">All</button>
        <button class="category-btn" data-category="IT">Information Technology</button>
        <button class="category-btn" data-category="CS">Computer Science</button>
        <button class="category-btn" data-category="DS">Data Science</button>
        <button class="category-btn" data-category="GD">Game Development</button>
        <button class="category-btn" data-category="DAT">Digital Animation</button>
</div>
</div>
  <div class="resource-grid" id="resource-results">
    <?php
      // Fetch resources from the database
      $sql_resources = "SELECT Resource_ID, Resource_Title, Resource_Icon, Resource_Type, Resource_File, Category FROM resources WHERE Status = 'Approved'";
      $result_resources = $conn->query($sql_resources);

      if ($result_resources && $result_resources->num_rows > 0) {
        // Output data for each resource
        while ($resource = $result_resources->fetch_assoc()) {
          echo '<div class="course-card" data-category="' . htmlspecialchars($resource['Category']) . '" data-status="Approved">';
          if (!empty($resource['Resource_Icon'])) {
            // Ensure the path is correct, assuming icons are in an 'uploads' folder
            echo '<img src="../uploads/' . htmlspecialchars($resource['Resource_Icon']) . '" alt="Resource Icon">';
          }
          echo '<h2>' . htmlspecialchars($resource['Resource_Title']) . '</h2>';
          echo '<p><strong>Type: ' . htmlspecialchars($resource['Resource_Type']) . '</strong></p>';

          // --- FIXED VIEW BUTTON ---
          // Ensure Resource_File contains only the filename, not the full path yet
          $filePath = $resource['Resource_File']; // Get the filename from DB
          $fileTitle = $resource['Resource_Title'];

          // Construct the URL for view_resource.php
          // urlencode() is crucial for filenames/titles with spaces or special characters
          $viewUrl = 'view_resource.php?file=' . urlencode($filePath) . '&title=' . urlencode($fileTitle);

          // Create the link
          echo '<a href="' . htmlspecialchars($viewUrl) . '" class="view-btn" target="_blank">View</a>'; // Updated class from btn-view to view-btn
          // --- END FIXED VIEW BUTTON ---

          echo '</div>';
        }
      } else {
        echo "<p>No resources found.</p>";
      }
    ?>
  </div>

  <div id="no-resources-message" style="display:none; 
    text-align: center;
    padding: 20px;
    color: #6c757d;
    font-size: 18px;
    background: #f8f9fa;
    border-radius: 10px;
    border: 2px dashed #dee2e6;
    margin: 20px auto;
    max-width: 400px;
    margin-top: 10px;
    margin-bottom: 200px;
    
    ">
    No resources match the selected filters.
</div>
</section>

<script src="mentee.js"></script>
<script>
    // Navigation menu functionality
    document.addEventListener("DOMContentLoaded", function () {
      console.log("✅ Navigation menu script loaded");
      
      // Profile menu toggle functionality
      const profileIcon = document.getElementById("profile-icon");
      const profileMenu = document.getElementById("profile-menu");
      
      if (profileIcon && profileMenu) {
        profileIcon.addEventListener("click", function (e) {
          e.preventDefault();
          console.log("Profile icon clicked");
          profileMenu.classList.toggle("show");
          profileMenu.classList.toggle("hide");
        });
        
        // Close menu when clicking outside
        document.addEventListener("click", function (e) {
          if (!profileIcon.contains(e.target) && !profileMenu.contains(e.target)) {
            profileMenu.classList.remove("show");
            profileMenu.classList.add("hide");
          }
        });
      } else {
        console.error("Profile menu elements not found");
      }

      // Initialize category filtering after DOM is loaded
      initializeCategoryFiltering();
      
      // Initialize search functionality
      initializeSearch();
      
      // Initialize course filtering (if needed)
      initializeCourseFilters();
    });

    // Category filtering functionality
    function initializeCategoryFiltering() {
      const buttons = document.querySelectorAll('.category-btn');
      const resourceCards = document.querySelectorAll('#resource-results .course-card');

      buttons.forEach(button => {
        button.addEventListener('click', () => {
          // Remove active class from all buttons, then add to the clicked one
          buttons.forEach(btn => btn.classList.remove('active'));
          button.classList.add('active');

          const selected = button.getAttribute('data-category');
          let visibleCount = 0;

          resourceCards.forEach(card => {
            const cardCategory = card.getAttribute('data-category');

            // ✅ UPDATED LOGIC: Show card if:
            // 1. "All" is selected, OR
            // 2. Card category matches selected category, OR
            // 3. Card category is "all" (these appear in every category)
            if (selected === 'all' || 
                cardCategory === selected || 
                cardCategory === 'all') {
              card.style.display = 'block';
              visibleCount++;
            } else {
              card.style.display = 'none';
            }
          });

          // ✅ Show or hide "no resources" message
          const noResourcesMsg = document.getElementById('no-resources-message');
          if (noResourcesMsg) {
            if (visibleCount === 0) {
              noResourcesMsg.style.display = 'block';
            } else {
              noResourcesMsg.style.display = 'none';
            }
          }
        });
      });
    }

    // Search functionality
    function initializeSearch() {
      const searchBox = document.getElementById('search-box');
      if (searchBox) {
        // Real-time search as you type
        searchBox.addEventListener('input', function () {
          performSearch();
        });
      }

      // Course search button functionality
      const searchBtn = document.getElementById('searchBtn');
      if (searchBtn) {
        searchBtn.addEventListener('click', function() {
          const searchTerm = document.getElementById('courseSearch').value.toLowerCase().trim();
          const courseCards = document.querySelectorAll('.course-card');
          let visibleCount = 0;

          courseCards.forEach(card => {
            const title = card.querySelector('h2').textContent.toLowerCase();
            const description = card.querySelector('p').textContent.toLowerCase();

            if (title.includes(searchTerm) || description.includes(searchTerm)) {
              card.style.display = 'block';
              visibleCount++;
            } else {
              card.style.display = 'none';
            }
          });

          updateNoCourseMessage(visibleCount);
        });
      }
    }

    // Search function for resources
    function performSearch() {
      const query = document.getElementById('search-box').value;

      fetch('search_resources.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: 'query=' + encodeURIComponent(query)
      })
      .then(response => response.text())
      .then(data => {
        const resourceResults = document.getElementById('resource-results');
        if (resourceResults) {
          resourceResults.innerHTML = data;
          // Re-initialize category filtering after search results are loaded
          initializeCategoryFiltering();
        }
      })
      .catch(error => console.error('Search error:', error));
    }

    // Course filtering functionality (for advanced filtering if needed)
    function initializeCourseFilters() {
      const filterButtons = document.querySelectorAll('.course-filter-btn');
      
      if (filterButtons.length === 0) {
        return; // No course filter buttons found, skip initialization
      }
      
      // Track current filters
      let currentCategoryFilter = 'all';
      let currentLevelFilter = 'all';
      
      // Add event listeners to all filter buttons
      filterButtons.forEach(button => {
        button.addEventListener('click', function() {
          const filterType = this.getAttribute('data-filter-type');
          const filterValue = this.getAttribute('data-filter-value');
          
          // Update active states
          if (filterType === 'category') {
            document.querySelectorAll('[data-filter-type="category"]').forEach(btn => 
              btn.classList.remove('active')
            );
            this.classList.add('active');
            currentCategoryFilter = filterValue;
          } else if (filterType === 'level') {
            document.querySelectorAll('[data-filter-type="level"]').forEach(btn => 
              btn.classList.remove('level-active')
            );
            this.classList.add('level-active');
            currentLevelFilter = filterValue;
          }
          
          // Apply filters
          applyFilters(currentCategoryFilter, currentLevelFilter);
        });
      });
      
      // Apply initial filter (show all)
      applyFilters('all', 'all');
    }

    // Apply multiple filters (category and level)
    function applyFilters(categoryFilter, levelFilter) {
      const courseCards = document.querySelectorAll('.course-card');
      let visibleCount = 0;
      
      courseCards.forEach(card => {
        const cardCategory = card.getAttribute('data-category');
        const cardLevel = card.getAttribute('data-level');
        
        // ✅ UPDATED LOGIC: Check if card matches category filter
        // Show if: "all" is selected OR card matches selected category OR card category is "all"
        const categoryMatch = categoryFilter === 'all' || 
                             cardCategory === categoryFilter || 
                             cardCategory === 'all';
        
        const levelMatch = levelFilter === 'all' || cardLevel === levelFilter;
        
        if (categoryMatch && levelMatch) {
          card.style.display = 'block';
          card.classList.remove('hidden');
          visibleCount++;
        } else {
          card.style.display = 'none';
          card.classList.add('hidden');
        }
      });

      // Show/hide "no courses" message
      updateNoCourseMessage(visibleCount);
    }

    // Update "no courses found" message
    function updateNoCourseMessage(visibleCount) {
      let noCourseMsg = document.querySelector('.no-courses-filtered');
      
      if (visibleCount === 0) {
        // Create or show "no courses" message
        if (!noCourseMsg) {
          noCourseMsg = document.createElement('div');
          noCourseMsg.className = 'no-courses-filtered';
          noCourseMsg.innerHTML = '<p>No courses match the selected filters.</p>';
          noCourseMsg.style.cssText = `
            text-align: center;
            padding: 20px;
            color: #6c757d;
            font-size: 18px;
            background: #f8f9fa;
            border-radius: 10px;
            border: 2px dashed #dee2e6;
            margin: 20px auto;
            display: block;
            max-width: 400px;
          `;
          
          const courseGrid = document.querySelector('.course-grid');
          if (courseGrid) {
            courseGrid.appendChild(noCourseMsg);
          }
        }
        noCourseMsg.style.display = 'block';
      } else {
        // Hide "no courses" message
        if (noCourseMsg) {
          noCourseMsg.style.display = 'none';
        }
      }
    }

    // Make logout function available globally
    window.confirmLogout = function() {
      var confirmation = confirm("Are you sure you want to log out?");
      if (confirmation) {
        window.location.href = "../login.php";
      }
      return false;
    }

    // Category mapping for better display
    const categoryMap = {
      'all': 'All Categories',
      'IT': 'Information Technology',
      'CS': 'Computer Science',
      'DS': 'Data Science',
      'GD': 'Game Development',
      'DAT': 'Digital Animation'
    };

    // Function to reset all filters
    function resetFilters() {
      // Reset category filter
      document.querySelectorAll('[data-filter-type="category"]').forEach(btn => {
        btn.classList.remove('active');
        if (btn.getAttribute('data-filter-value') === 'all') {
          btn.classList.add('active');
        }
      });
      
      // Reset level filter
      document.querySelectorAll('[data-filter-type="level"]').forEach(btn => {
        btn.classList.remove('level-active');
        if (btn.getAttribute('data-filter-value') === 'all') {
          btn.classList.add('level-active');
        }
      });
      
      // Apply filters
      applyFilters('all', 'all');
    }

    // Optional: Add search functionality for courses
    function addCourseSearch() {
      const searchInput = document.createElement('input');
      searchInput.type = 'text';
      searchInput.placeholder = 'Search courses...';
      searchInput.style.cssText = `
        padding: 10px 15px;
        border: 2px solid #dee2e6;
        border-radius: 25px;
        width: 100%;
        max-width: 300px;
        margin: 10px auto;
        display: block;
        font-size: 14px;
      `;
      
      // Insert search box before filters
      const filterSection = document.querySelector('.filter-section');
      if (filterSection) {
        filterSection.insertBefore(searchInput, filterSection.firstChild);
        
        searchInput.addEventListener('input', function() {
          const searchTerm = this.value.toLowerCase();
          const courseCards = document.querySelectorAll('.course-card');
          
          courseCards.forEach(card => {
            const title = card.querySelector('h2') ? card.querySelector('h2').textContent.toLowerCase() : '';
            const description = card.querySelector('p') ? card.querySelector('p').textContent.toLowerCase() : '';
            
            if (title.includes(searchTerm) || description.includes(searchTerm)) {
              card.style.display = 'block';
            } else {
              card.style.display = 'none';
            }
          });
        });
      }
    }

  </script>
</body>
</html>

<?php $conn->close(); ?>
