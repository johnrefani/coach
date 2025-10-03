document.addEventListener("DOMContentLoaded", function() {
    // Select all necessary elements
    const profileIcon = document.getElementById("profile-icon");
    const profileMenu = document.getElementById("profile-menu");
    
    // Elements for LOGOUT DIALOG
    const logoutDialog = document.getElementById("logoutDialog");
    const cancelLogoutBtn = document.getElementById("cancelLogout");
    const confirmLogoutBtn = document.getElementById("confirmLogoutBtn");

    // 🔑 NEW: Elements for LEAVE CHAT DIALOG
    const leaveChatDialog = document.getElementById("leaveChatDialog");
    const cancelLeaveBtn = document.getElementById("cancelLeave");
    // Note: confirmLeaveBtn (now an <a> tag) is handled directly in HTML, 
    // but the cancel button needs a listener to close the modal.

    const cancelSessionDialog = document.getElementById("cancelSessionDialog");
    const cancelSessionBtn = document.getElementById("cancelSession");
    const sessionToCancelIDInput = document.getElementById("sessionToCancelID");


    // --- Profile Menu Toggle Logic ---
    if (profileIcon && profileMenu) {
        profileIcon.addEventListener("click", function (e) {
            e.preventDefault();
            profileMenu.classList.toggle("show");
            profileMenu.classList.toggle("hide");
        });
        
        // Close menu when clicking outside
        document.addEventListener("click", function (e) {
            // Check if the click is outside the icon, the menu, and the menu's sub-elements
            if (!profileIcon.contains(e.target) && !profileMenu.contains(e.target) && !e.target.closest('#profile-menu')) {
                profileMenu.classList.remove("show");
                profileMenu.classList.add("hide");
            }
        });
    }

    // ------------------------------------
    // --- LOGOUT Dialog Logic ---
    // ------------------------------------
    
    // Attach event listener to cancel button
    if (cancelLogoutBtn && logoutDialog) {
        cancelLogoutBtn.addEventListener("click", function(e) {
            e.preventDefault(); 
            logoutDialog.style.display = "none";
        });
    }

    // Attach event listener to confirm button (for redirection)
    if (confirmLogoutBtn) {
        confirmLogoutBtn.addEventListener("click", function(e) {
            e.preventDefault(); 
            // The action: Redirect to the login page (which handles session destruction/logout)
            window.location.href = "../login.php"; 
        });
    }

    // Global function to show the Logout dialog (called by the navigation menu's onclick)
    window.confirmLogout = function(e) { 
        if (e) e.preventDefault(); 
        if (logoutDialog) {
            logoutDialog.style.display = "flex";
        }
    }


    // ------------------------------------
    // 🔑 NEW: LEAVE CHAT Dialog Logic
    // ------------------------------------
    
    // Global function to show the Leave Chat dialog (called by the button onclick in forum-chat.php)
    window.confirmLeaveChat = function(e) {
        if (e) e.preventDefault(); 
        if (leaveChatDialog) {
            leaveChatDialog.style.display = "flex";
        }
    }

    // Attach event listener to cancel button
    if (cancelLeaveBtn && leaveChatDialog) {
        cancelLeaveBtn.addEventListener("click", function(e) {
            e.preventDefault(); 
            leaveChatDialog.style.display = "none";
        });
    }


    // ------------------------------------
    // 🔑 NEW: CANCEL SESSION Dialog Logic
    // ------------------------------------

    // Function to show the Cancel Session dialog (called by the button onclick in sessions.php)
    window.confirmCancelSession = function(pendingId, e) {
        if (e) e.preventDefault();
        if (cancelSessionDialog && sessionToCancelIDInput) {
            // 🔑 CRITICAL: Update the hidden input value with the correct Pending_ID
            sessionToCancelIDInput.value = pendingId;
            cancelSessionDialog.style.display = "flex";
        }
    }

    // Function to hide the Cancel Session dialog
    if (cancelSessionBtn && cancelSessionDialog) {
        cancelSessionBtn.addEventListener("click", function(e) {
            e.preventDefault();
            cancelSessionDialog.style.display = "none";
            // Optional: Reset the hidden ID input
            if (sessionToCancelIDInput) {
                sessionToCancelIDInput.value = 0;
            }
        });
    }

}); // Closing bracket for DOMContentLoaded