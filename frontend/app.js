const API_URL = 'http://localhost/task-manager/api.php/api/tasks';

// --- Centralized API Fetcher ---
async function apiCall(endpoint = '', method = 'GET', body = null) {
    const options = { method, headers: { 'Content-Type': 'application/json' } };
    if (body) options.body = JSON.stringify(body);
    
    try {
        const response = await fetch(`${API_URL}${endpoint}`, options);
        const data = await response.json();
        if (!response.ok) throw new Error(data.message || 'API Error');
        return data;
    } catch (error) {
        alert(error.message);
        throw error; // Stop execution if there's an error
    }
}

// --- Refresh Table and Report simultaneously ---
const refreshUI = () => {
    loadTasks();
    if (document.getElementById('reportContainer')?.style.display === 'block') generateReport();
};

// --- INITIALIZE PAGE ---
window.onload = () => {
    const today = new Date().toISOString().split('T')[0];
    document.getElementById('due_date').setAttribute('min', today);
    if (document.getElementById('report_date')) document.getElementById('report_date').value = today;
    loadTasks(); 
};

// --- 1. CREATE TASK ---
async function createTask() {
    const title = document.getElementById('title').value;
    const due_date = document.getElementById('due_date').value;
    const priority = document.getElementById('priority').value;

    if (!title || !due_date) return alert("Please fill in both the title and due date.");

    await apiCall('', 'POST', { title, due_date, priority });
    alert('Task created successfully!');
    
    // Clear inputs
    document.getElementById('title').value = '';
    document.getElementById('due_date').value = '';
    document.getElementById('priority').value = 'low'; 
    refreshUI();
}

// --- 2. LIST / FILTER TASKS ---
async function loadTasks() {
    const filter = document.getElementById('filter').value;
    const tbody = document.getElementById('tasks');
    
    try {
        const data = await apiCall(filter ? `?status=${filter}` : '');
        tbody.innerHTML = ''; 
        
        if (data.message || data.length === 0) {
            return tbody.innerHTML = '<tr><td colspan="5" style="color: #666;">No tasks found.</td></tr>';
        }

        data.forEach(task => {
            const statusStr = task.status.replace('_', ' ');
            const btnStyle = "color: white; border: none; border-radius: 4px; padding: 6px 12px; cursor: pointer;";
            
            const btn = task.status !== 'done' 
                ? `<button onclick="advanceStatus(${task.id}, '${task.status}')" style="background: #28a745; ${btnStyle}">Advance</button>` 
                : `<button onclick="deleteTask(${task.id})" style="background: #dc3545; ${btnStyle}">Delete</button>`;

            tbody.innerHTML += `
                <tr>
                    <td><strong>${task.title}</strong></td>
                    <td>${task.due_date}</td>
                    <td><span class="priority-${task.priority}" style="text-transform: capitalize;">${task.priority}</span></td>
                    <td><em style="text-transform: capitalize;">${statusStr}</em></td>
                    <td>${btn}</td>
                </tr>
            `;
        });
    } catch {
        tbody.innerHTML = '<tr><td colspan="5" style="color: red;">Failed to load tasks.</td></tr>';
    }
}

// --- 3. UPDATE TASK STATUS ---
async function advanceStatus(taskId, currentStatus) {
    const status = currentStatus === 'pending' ? 'in_progress' : 'done';
    await apiCall(`/${taskId}/status`, 'PATCH', { status });
    refreshUI();
}

// --- 4. DELETE TASK ---
async function deleteTask(taskId) {
    if (!confirm('Are you sure you want to permanently delete this task?')) return;
    await apiCall(`/${taskId}`, 'DELETE');
    refreshUI();
}

// --- 5. GENERATE DAILY REPORT ---
async function generateReport() {
    const date = document.getElementById('report_date').value;
    if (!date) return alert("Please select a date.");

    const container = document.getElementById('reportContainer');
    try {
        const { summary } = await apiCall(`/report?date=${date}`);
        container.style.display = 'block';
        
        // Helper to generate HTML cards cleanly
        const makeCard = (level, info) => `
            <div class="report-card">
                <h3 class="priority-${level}" style="display:block; text-transform: capitalize;">${level} Priority</h3>
                <ul>
                    <li>Pending: <strong>${info.pending}</strong></li>
                    <li>In Progress: <strong>${info.in_progress}</strong></li>
                    <li>Done: <strong>${info.done}</strong></li>
                </ul>
            </div>`;

        container.innerHTML = `
            <div class="report-grid">
                ${makeCard('high', summary.high)}
                ${makeCard('medium', summary.medium)}
                ${makeCard('low', summary.low)}
            </div>
        `;
    } catch {
        container.innerHTML = `<p style="color: red;">Failed to load report.</p>`;
    }
}
