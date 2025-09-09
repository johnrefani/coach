<?php
// Get file and title from URL parameters, provide defaults
$file = $_GET['file'] ?? '';
$title = $_GET['title'] ?? 'Resource Viewer';

// --- Security: Prevent directory traversal ---
// Use basename() to ensure $file only contains the filename part
$fileName = basename($file);
if (empty($fileName) || $fileName === '.' || $fileName === '..') {
    die("❌ Invalid file parameter.");
}

// --- Construct the file path relative to the script's location ---
// Assumes 'uploads' directory is in the same directory or accessible via this relative path
$uploadDir = 'uploads/';
$filepath = $uploadDir . $fileName;
$ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));

// --- Check if the file actually exists ---
if (!file_exists($filepath)) {
    // Log the error for debugging on the server side
    error_log("Resource file not found: " . $filepath . " (Requested file: " . $file . ")");
    // Provide a user-friendly error message
    die("❌ Resource file not found. Please check the file name and ensure it's uploaded correctly.");
}

// --- Determine the full, absolute URL for the Office viewer ---
$scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? "https" : "http";
$host = $_SERVER['HTTP_HOST'] ?? 'localhost'; // Get the host name

// --- IMPORTANT: Adjust this base path if your project is in a subdirectory ---
// Example: If your site URL is http://example.com/my_coach_app/ and uploads are in
// http://example.com/my_coach_app/uploads/, set $basePath = '/my_coach_app/';
// If uploads are directly under the domain root (http://example.com/uploads/), use '/'
$basePath = '/'; // Default: assumes uploads is in the web root relative to the domain. ADJUST IF NEEDED!

// Construct the web-accessible path to the file (relative to the domain root)
$webFilePath = $basePath . $uploadDir . $fileName;
// Construct the full URL
$fullUrl = $scheme . "://" . $host . $webFilePath;

// --- Check if the server is likely localhost or a private network ---
$isLocalOrPrivate = (
    $host === 'localhost' ||
    $host === '127.0.0.1' ||
    filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false // Checks if it's private/reserved IP
);

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"> <title><?php echo htmlspecialchars($title); ?> | View Resource</title>
  <link rel="stylesheet" href="css/view-resource.css" />
  <link rel="icon" href="coachicon.svg" type="image/svg+xml">
</head>
<body>
  <div class="viewer-container">
    <h1 class="viewer-title"><?php echo htmlspecialchars($title); ?></h1>

    <div class="viewer-frame">
      <?php if ($ext === "pdf"): ?>
        <iframe src="<?php echo htmlspecialchars($filepath); ?>" title="PDF Viewer: <?php echo htmlspecialchars($title); ?>"></iframe>
      <?php elseif (in_array($ext, ["mp4", "webm", "ogg", "mov"])): // Added more video types ?>
        <video controls preload="metadata" title="Video Player: <?php echo htmlspecialchars($title); ?>">
          <source src="<?php echo htmlspecialchars($filepath); ?>" type="video/<?php echo $ext === 'ogg' ? 'ogg' : ($ext === 'webm' ? 'webm' : 'mp4'); ?>">
          Your browser does not support the video tag. You can <a href="<?php echo htmlspecialchars($filepath); ?>" download>download the video</a>.
        </video>
      <?php elseif (in_array($ext, ["ppt", "pptx", "doc", "docx", "xls", "xlsx"])): // Handle all Office docs ?>
        <?php if ($isLocalOrPrivate): ?>
          <p class="info-message">⚠️ Online preview for Office documents (like PPTX) is not available when running on a local server.</p>
          <p>📎 Please use the download button below.</p>
        <?php else: ?>
          <?php
            // Construct the Office Apps Viewer URL
            $viewerUrl = "https://view.officeapps.live.com/op/embed.aspx?src=" . urlencode($fullUrl);
          ?>
          <p class="info-message"><small>Attempting to load preview using Microsoft Office Online viewer. This requires the file URL (<?php echo htmlspecialchars($fullUrl); ?>) to be publicly accessible from the internet.</small></p>
          <iframe src='<?php echo htmlspecialchars($viewerUrl); ?>' title="Office Document Viewer: <?php echo htmlspecialchars($title); ?>" frameborder='0'>
             Your browser does not support iframes, or the Office viewer could not load the file (it might not be publicly accessible).
          </iframe>
        <?php endif; ?>
      <?php elseif (in_array($ext, ["jpg", "jpeg", "png", "gif", "bmp", "webp"])): // Handle common images ?>
         <img src="<?php echo htmlspecialchars($filepath); ?>" alt="<?php echo htmlspecialchars($title); ?>" style="max-width: 100%; height: auto; display: block; margin: auto;">
      <?php else: ?>
        <p class="info-message">ℹ️ Preview is not available for this file type (<?php echo htmlspecialchars($ext); ?>).</p>
        <p>📎 You can download the file directly using the button below.</p>
      <?php endif; ?>
    </div>

    <?php if (isset($viewerUrl) && !$isLocalOrPrivate && in_array($ext, ["ppt", "pptx", "doc", "docx", "xls", "xlsx"])): ?>
        <p class="info-message" style="text-align:center;">If the preview above doesn't load, the file might not be publicly accessible, or there might be an issue with the Office viewer.</p>
    <?php endif; ?>

    <div class="viewer-actions">
      <a href="<?php echo htmlspecialchars($filepath); ?>" download="<?php echo htmlspecialchars($fileName); ?>" class="btn">Download File</a>
      <?php if (in_array($ext, ["pdf", "mp4", "webm", "ogg", "mov", "ppt", "pptx", "doc", "docx", "xls", "xlsx"])): ?>
        <button onclick="toggleFullScreen()" class="btn">View in Full Screen</button>
      <?php endif; ?>
      <button onclick="window.location.href = 'CoachMentee.php#resourceLibrary';" class="btn back-btn">← Back</button>
    </div>
  </div>

  <script>
    function toggleFullScreen() {
      // Try to target the iframe or video element within the viewer frame
      const el = document.querySelector(".viewer-frame iframe, .viewer-frame video");
      if (!el) {
          console.warn("Could not find iframe or video element to make fullscreen.");
          return; // Exit if no element found
      }

      if (!document.fullscreenElement &&    // Standard
          !document.webkitFullscreenElement && // Chrome, Safari & Opera
          !document.mozFullScreenElement &&    // Firefox
          !document.msFullscreenElement) {   // IE/Edge

          if (el.requestFullscreen) {
              el.requestFullscreen();
          } else if (el.webkitRequestFullscreen) { /* Safari */
              el.webkitRequestFullscreen();
          } else if (el.msRequestFullscreen) { /* IE11 */
              el.msRequestFullscreen();
          } else if (el.mozRequestFullScreen) { /* Firefox */
             el.mozRequestFullScreen();
          }
      } else { // Exit fullscreen
          if (document.exitFullscreen) {
              document.exitFullscreen();
          } else if (document.webkitExitFullscreen) { /* Safari */
              document.webkitExitFullscreen();
          } else if (document.msExitFullscreen) { /* IE11 */
              document.msExitFullscreen();
          } else if (document.mozCancelFullScreen) { /* Firefox */
              document.mozCancelFullScreen();
          }
      }
    }

    document.querySelector('.back-btn').addEventListener('click', function(e) {
   e.preventDefault();
   document.getElementById('resourceLibrary').scrollIntoView({ behavior: 'smooth' });
});

  </script>
</body>
</html>
