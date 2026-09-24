// ================================================
// HRIS SYSTEM - SHARED JAVASCRIPT
// ================================================

document.addEventListener('DOMContentLoaded', function () {
    // Mobile sidebar toggle
    const menuToggle = document.getElementById('menuToggle');
    const sidebar = document.getElementById('sidebar');
    if (menuToggle && sidebar) {
        menuToggle.addEventListener('click', () => sidebar.classList.toggle('open'));
    }

    // Live clock (used on attendance page)
    const clockTime = document.getElementById('clockTime');
    const clockDate = document.getElementById('clockDate');
    if (clockTime && clockDate) {
        function updateClock() {
            const now = new Date();
            clockTime.textContent = now.toLocaleTimeString('en-PH', { hour12: true });
            clockDate.textContent = now.toLocaleDateString('en-PH', {
                weekday: 'long', year: 'numeric', month: 'long', day: 'numeric'
            });
        }
        updateClock();
        setInterval(updateClock, 1000);
    }

    // Generic confirm-delete for forms/links with data-confirm
    document.querySelectorAll('[data-confirm]').forEach(el => {
        el.addEventListener('click', function (e) {
            if (!confirm(this.getAttribute('data-confirm'))) {
                e.preventDefault();
            }
        });
    });

    // Simple client-side table search filter
    const searchInput = document.getElementById('tableSearch');
    if (searchInput) {
        searchInput.addEventListener('input', function () {
            const q = this.value.toLowerCase();
            const rows = document.querySelectorAll('#dataTable tbody tr');
            rows.forEach(row => {
                row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
            });
        });
    }

    // Auto-calc total leave days
    const leaveStart = document.getElementById('start_date');
    const leaveEnd = document.getElementById('end_date');
    const totalDaysField = document.getElementById('total_days');
    function calcLeaveDays() {
        if (leaveStart && leaveEnd && totalDaysField && leaveStart.value && leaveEnd.value) {
            const start = new Date(leaveStart.value);
            const end = new Date(leaveEnd.value);
            const diff = Math.round((end - start) / (1000 * 60 * 60 * 24)) + 1;
            totalDaysField.value = diff > 0 ? diff : '';
        }
    }
    if (leaveStart && leaveEnd) {
        leaveStart.addEventListener('change', calcLeaveDays);
        leaveEnd.addEventListener('change', calcLeaveDays);
    }
});
