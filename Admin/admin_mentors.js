const btnMentors = document.getElementById('btnMentors');
const btnApplicants = document.getElementById('btnApplicants');
const btnRejected = document.getElementById("btnRejected");
const tableContainer = document.getElementById('tableContainer');
const detailView = document.getElementById('detailView');
const approvedCount = document.getElementById('approvedCount');
const applicantCount = document.getElementById('applicantCount');
const rejectedCount = document.getElementById("rejectedCount");
const searchInput = document.getElementById('searchInput');
const darkToggle = document.querySelector('.darkToggle');
const body = document.body;

let approved = mentorData.filter(m => m.Status === "Approved");
let applicants = mentorData.filter(m => m.Status === "Under Review");
let rejected = mentorData.filter(m => m.Status === "Rejected");

approvedCount.textContent = approved.length;
applicantCount.textContent = applicants.length;
rejectedCount.textContent = rejected.length;

let currentTable = null;
let currentData = [];

function searchMentors() {
  const input = searchInput.value.toLowerCase();
  const rows = document.querySelectorAll('table tbody tr.data-row');

  rows.forEach(row => {
    const id = row.querySelector('td:first-child').innerText.toLowerCase();
    const firstName = row.querySelector('.first-name')?.innerText.toLowerCase() || '';
    const lastName = row.querySelector('.last-name')?.innerText.toLowerCase() || '';

    if (id.includes(input) || firstName.includes(input) || lastName.includes(input)) {
      row.style.display = '';
    } else {
      row.style.display = 'none';
    }
  });
}

searchInput.addEventListener('input', searchMentors);

function showTable(data, isApplicant = false) {
  currentData = data;
  let html = `<table><thead>
    <tr><th>ID</th><th>First Name</th><th>Last Name</th><th>Action</th></tr>
  </thead><tbody>`;

  data.forEach(row => {
    html += `<tr class="data-row">
      <td>${row.Mentor_ID}</td>
      <td class="first-name">${row.First_Name}</td>
      <td class="last-name">${row.Last_Name}</td>
      <td><button onclick="viewDetails(${row.Mentor_ID}, ${isApplicant})">View</button></td>
    </tr>`;
  });

  html += '</tbody></table>';
  tableContainer.innerHTML = html;
  tableContainer.classList.remove('hidden');
  detailView.classList.add('hidden');
}

function viewDetails(id, isApplicant) {
  const row = mentorData.find(m => m.Mentor_ID == id);
  let resumeLink = row.Resume ? `<a href="view_application.php?file=${encodeURIComponent(row.Resume)}&type=resume" target="_blank">View Resume</a>` : "N/A";
  let certLink = row.Certificates ? `<a href="view_application.php?file=${encodeURIComponent(row.Certificates)}&type=certificate" target="_blank">View Certificate</a>` : "N/A";

  let html = `<div class="details">
    <button onclick="backToTable(${isApplicant})">Back</button>
    <h3>${row.First_Name} ${row.Last_Name}</h3>
    <p><strong>First Name:</strong> <input type="text" readonly value="${row.First_Name}"></p>
    <p><strong>Last Name:</strong> <input type="text" readonly value="${row.Last_Name}"></p>
    <p><strong>DOB:</strong> <input type="text" readonly value="${row.DOB}"></p>
    <p><strong>Gender:</strong> <input type="text" readonly value="${row.Gender}"></p>
    <p><strong>Email:</strong> <input type="text" readonly value="${row.Email}"></p>
    <p><strong>Contact:</strong> <input type="text" readonly value="${row.Contact_Number}"></p>
    <p><strong>Username:</strong> <input type="text" readonly value="${row.Applicant_Username}"></p>
    <p><strong>Mentored Before:</strong> <input type="text" readonly value="${row.Mentored_Before}"></p>
    <p><strong>Experience:</strong> <input type="text" readonly value="${row.Mentoring_Experience}"></p>
    <p><strong>Expertise:</strong> <input type="text" readonly value="${row.AreaofExpertise}"></p>
    <p><strong>Resume:</strong> ${resumeLink}</p>
    <p><strong>Certificates:</strong> ${certLink}</p>
    <p><strong>Status:</strong> <input type="text" readonly value="${row.Status}"></p>
    <p><strong>Reason for Rejection:</strong> <input type="text" readonly value="${row.Reason}"></p>`;

  if (isApplicant) {
    html += `<div class="action-buttons">
      <button onclick="updateStatus(${id}, 'Approved')">Approve</button>
      <button onclick="showRejectionDialog(${id})">Reject</button>
    </div>`;
  }

  html += '</div>';
  detailView.innerHTML = html;
  detailView.classList.remove('hidden');
  tableContainer.classList.add('hidden');
}

function backToTable(isApplicant) {
  detailView.classList.add('hidden');
  tableContainer.classList.remove('hidden');
}

function showRejectionDialog(id) {
  const row = mentorData.find(m => m.Mentor_ID == id);
  const prefix = row.Gender && row.Gender.toLowerCase() === 'female' ? 'Ms.' : 'Mr.';
  
  const dialog = document.createElement('div');
  dialog.className = 'rejection-dialog';
  dialog.innerHTML = `
    <div class="rejection-content">
      <h3>Rejection Reason</h3>
      <p>Please provide a reason for rejecting ${prefix} ${row.First_Name} ${row.Last_Name}'s application:</p>
      <textarea id="rejectionReason" placeholder="Enter rejection reason here..."></textarea>
      <div class="dialog-buttons">
        <button id="cancelReject">Cancel</button>
        <button id="confirmReject">Confirm Rejection</button>
      </div>
    </div>
  `;
  
  document.body.appendChild(dialog);
  
  document.getElementById('cancelReject').addEventListener('click', () => {
    document.body.removeChild(dialog);
  });
  
  document.getElementById('confirmReject').addEventListener('click', () => {
    const reason = document.getElementById('rejectionReason').value.trim();
    if (reason === '') {
      alert('Please provide a rejection reason.');
      return;
    }
    
    updateStatusWithReason(id, 'Rejected', reason);
    document.body.removeChild(dialog);
  });
}

function updateStatus(id, newStatus) {
  fetch('update_mentor_status.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: `id=${id}&status=${newStatus}`
  })
  .then(response => response.text())
  .then(msg => {
    alert(msg);
    location.reload();
  });
}

function updateStatusWithReason(id, newStatus, reason) {
  fetch('update_mentor_status.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: `id=${id}&status=${newStatus}&reason=${encodeURIComponent(reason)}`
  })
  .then(response => response.text())
  .then(msg => {
    alert(msg);
    location.reload();
  });
}

btnMentors.onclick = () => {
  if (currentTable === 'mentors') {
    tableContainer.classList.add('hidden');
    detailView.classList.add('hidden');
    currentTable = null;
  } else {
    showTable(approved, false);
    currentTable = 'mentors';
  }
};

btnApplicants.onclick = () => {
  if (currentTable === 'applicants') {
    tableContainer.classList.add('hidden');
    detailView.classList.add('hidden');
    currentTable = null;
  } else {
    showTable(applicants, true);
    currentTable = 'applicants';
  }
};

btnRejected.onclick = () => {
  if (currentTable === 'rejected') {
    tableContainer.classList.add('hidden');
    detailView.classList.add('hidden');
    currentTable = null;
  } else {
    showTable(rejected, false);
    currentTable = 'rejected';
  }
};