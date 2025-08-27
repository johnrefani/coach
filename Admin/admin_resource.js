document.addEventListener('DOMContentLoaded', () => {
    // --- Element Selection ---
    const navBar = document.querySelector("nav");
    const navToggle = document.querySelector(".navToggle");
    const navLinks = document.querySelectorAll(".navList");
    const darkToggle = document.querySelector(".darkToggle");
    const body = document.body;

    // Content Sections
    const homeContent = document.getElementById("homeContent");
    const addCourseSection = document.getElementById("addCourseSection");
    const courseTitle = document.getElementById("courseTitle");
    const submittedCoursesTitle = document.getElementById("submittedCoursesTitle");
    const submittedCourses = document.getElementById("submittedCourses");
    const sessionsContent = document.getElementById("sessionsContent");
    const forumContent = document.getElementById("forumContent");
    const resourceLibraryContent = document.getElementById("resourceLibraryContent");
    const applicationsContent = document.getElementById("applicationsContent");

    const approvedCount = document.getElementById('approvedCount');
    const pendingresourceCount = document.getElementById('pendingresourceCount');
    const rejectedCount = document.getElementById("rejectedCount");

    const categoryButtons = document.querySelectorAll('.category-btn');
    const filterButtons = document.querySelectorAll('.filter-btn');
    const cards = document.querySelectorAll('.resource-card');

    // Create modal elements for rejection reason
    const modalContainer = document.createElement('div');
    modalContainer.className = 'rejection-modal-container';
    modalContainer.style.display = 'none';
    
    const modalContent = document.createElement('div');
    modalContent.className = 'rejection-modal-content';
    
    modalContent.innerHTML = `
        <h3>Reason for Rejection</h3>
        <textarea id="rejectionReason" placeholder="Please provide a reason for rejection..."></textarea>
        <div class="modal-buttons">
            <button id="confirmReject" class="confirm-btn">Confirm</button>
            <button id="cancelReject" class="cancel-btn">Cancel</button>
        </div>
    `;
    
    modalContainer.appendChild(modalContent);
    document.body.appendChild(modalContainer);

    let activeStatus = "Approved"; // Default status
    let activeCategory = "all";    // Default category
    let currentResourceId = null;  // To track which resource is being rejected
    let currentForm = null;        // To track which form triggered the rejection

    // --- Navigation Toggle ---
    if (navToggle && navBar) {
        navToggle.addEventListener('click', () => {
            navBar.classList.toggle('close');
        });
    }

    // --- Dark Mode Toggle ---
    if (darkToggle) {
        darkToggle.addEventListener('click', () => {
            body.classList.toggle('dark');
            localStorage.setItem('darkMode', body.classList.contains('dark') ? 'enabled' : '');
        });

        if (localStorage.getItem('darkMode') === 'enabled') {
            body.classList.add('dark');
        }
    }

    // --- Tab Section Display Handler ---
    function updateVisibleSections() {
        const activeLink = document.querySelector(".navList.active");
        const activeText = activeLink?.querySelector("span")?.textContent.trim();

        [homeContent, addCourseSection, courseTitle, submittedCoursesTitle, submittedCourses,
            sessionsContent, forumContent, resourceLibraryContent, applicationsContent].forEach(section => {
            if (section) section.style.display = "none";
        });

        switch (activeText) {
            case "Home":
                homeContent && (homeContent.style.display = "block");
                break;
            case "Courses":
                addCourseSection && (addCourseSection.style.display = "flex");
                courseTitle && (courseTitle.style.display = "block");
                submittedCoursesTitle && (submittedCoursesTitle.style.display = "block");
                submittedCourses && (submittedCourses.style.display = "flex");
                break;
            case "Sessions":
                sessionsContent && (sessionsContent.style.display = "block");
                break;
            case "Forum":
                forumContent && (forumContent.style.display = "block");
                break;
            case "Resource Library":
                resourceLibraryContent && (resourceLibraryContent.style.display = "block");
                break;
            case "Applications":
                applicationsContent && (applicationsContent.style.display = "block");
                break;
            default:
                homeContent && (homeContent.style.display = "block");
                break;
        }
    }

    navLinks.forEach(link => {
        link.addEventListener('click', e => {
            e.preventDefault();
            navLinks.forEach(l => l.classList.remove('active'));
            link.classList.add('active');
            updateVisibleSections();
        });
    });

    updateVisibleSections(); // Initial section

    // --- Combined Filter Logic ---
    function updateResourceVisibility() {
        cards.forEach(card => {
            const cardCategory = card.getAttribute('data-category');
            const cardStatus = card.getAttribute('data-status');

            const statusMatch = cardStatus === activeStatus;
            const categoryMatch = activeCategory === 'all' || cardCategory === activeCategory;

            if (statusMatch && categoryMatch) {
                card.style.display = 'block';
                card.style.opacity = 0;
                setTimeout(() => card.style.opacity = 1, 100);
            } else {
                card.style.display = 'none';
            }
        });

        updateCountBadges();
    }

    // --- Category Filter ---
    categoryButtons.forEach(button => {
        button.addEventListener('click', () => {
            activeCategory = button.getAttribute('data-category');

            categoryButtons.forEach(btn => btn.classList.remove('active'));
            button.classList.add('active');

            updateResourceVisibility();
        });
    });

    // --- Status Filter ---
    filterButtons.forEach(button => {
        button.addEventListener('click', () => {
            const status = button.getAttribute('data-status');

            activeStatus = status;

            filterButtons.forEach(btn => btn.classList.remove('active'));
            button.classList.add('active');

            // Reset to "all" category
            const defaultCategoryButton = document.querySelector('.category-btn[data-category="all"]');
            if (defaultCategoryButton) {
                activeCategory = "all";
                categoryButtons.forEach(btn => btn.classList.remove('active'));
                defaultCategoryButton.classList.add('active');
            }

            updateResourceVisibility();
        });
    });

    // --- Count Badges ---
    function updateCountBadges() {
        const approvedCards = document.querySelectorAll('.resource-card[data-status="Approved"]');
        const pendingCards = document.querySelectorAll('.resource-card[data-status="Under Review"]');
        const rejectedCards = document.querySelectorAll('.resource-card[data-status="Rejected"]');

        if (approvedCount) approvedCount.textContent = approvedCards.length;
        if (pendingresourceCount) pendingresourceCount.textContent = pendingCards.length;
        if (rejectedCount) rejectedCount.textContent = rejectedCards.length;
    }

    // --- Handle Form Submission ---
    const resourceForms = document.querySelectorAll('form[action="update_resource_status.php"]');
    
    resourceForms.forEach(form => {
        form.addEventListener('submit', function(event) {
            event.preventDefault();
            
            const resourceId = this.querySelector('input[name="resource_id"]').value;
            const clickedButton = document.activeElement;
            const action = clickedButton.value;
            
            if (action === "Rejected") {
                // Show the rejection reason modal
                modalContainer.style.display = 'flex';
                currentResourceId = resourceId;
                currentForm = this;
            } else {
                // For approval, proceed normally
                updateResourceStatus(resourceId, action, this);
            }
        });
    });

    // --- Modal Buttons Event Listeners ---
    document.getElementById('confirmReject').addEventListener('click', () => {
        const reason = document.getElementById('rejectionReason').value.trim();
        
        if (reason === '') {
            alert('Please provide a reason for rejection.');
            return;
        }
        
        // Add the reason to the form data and submit
        updateResourceStatus(currentResourceId, "Rejected", currentForm, reason);
        
        // Hide the modal
        modalContainer.style.display = 'none';
        document.getElementById('rejectionReason').value = '';
    });
    
    document.getElementById('cancelReject').addEventListener('click', () => {
        // Hide the modal without submitting
        modalContainer.style.display = 'none';
        document.getElementById('rejectionReason').value = '';
    });

    function updateResourceStatus(resourceId, action, formElement, rejectionReason = null) {
        // Create form data
        const formData = new FormData();
        formData.append('resource_id', resourceId);
        formData.append('action', action);
        
        // Add rejection reason if provided
        if (rejectionReason) {
            formData.append('rejection_reason', rejectionReason);
        }
        
        // Use the correct URL (update_resource_status.php)
        fetch("update_resource_status.php", {
            method: "POST",
            body: formData
        })
        .then(response => response.text())
        .then(result => {
            alert(result);
            
            // Update the card status
            const card = formElement.closest(".resource-card");
            if (card) {
                card.setAttribute("data-status", action);
                
                // Update the status label
                const statusLabel = card.querySelector(".status-label");
                if (statusLabel) {
                    statusLabel.innerHTML = "<strong>Status:</strong> " + action;
                    
                    // Add rejection reason if provided
                    if (rejectionReason && action === "Rejected") {
                        const reasonElement = document.createElement('p');
                        reasonElement.className = 'rejection-reason';
                        reasonElement.innerHTML = "<strong>Rejection Reason:</strong> " + rejectionReason;
                        
                        // Insert after status label
                        statusLabel.parentNode.insertBefore(reasonElement, statusLabel.nextSibling);
                    }
                }
                
                // Remove action buttons after approval/rejection
                formElement.style.display = "none";
            }
            
            updateResourceVisibility();
        })
        .catch(error => {
            alert("Error updating status: " + error);
        });
    }

    // Close modal if clicked outside the content
    modalContainer.addEventListener('click', (e) => {
        if (e.target === modalContainer) {
            modalContainer.style.display = 'none';
            document.getElementById('rejectionReason').value = '';
        }
    });

    // --- Initial Setup ---
    // Show approved resources by default
    const approvedFilterButton = document.querySelector('.filter-btn[data-status="Approved"]');
    if (approvedFilterButton) {
        approvedFilterButton.classList.add('active');
    }
    
    updateResourceVisibility();
});