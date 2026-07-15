/* ── Admin Media Analytics JS ── */

document.addEventListener('DOMContentLoaded', function() {
    lucide.createIcons();

    // ── Auto-submit search with debounce ──
    let searchTimeout;
    const searchInput = document.querySelector('input[name="search"]');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(function() {
                const form = document.getElementById('search-form');
                if (form) form.submit();
            }, 400);
        });
    }

    // ── Delete Modal ──
    const deleteModal = document.getElementById('delete-modal');
    if (deleteModal) {
        // Close on backdrop click
        deleteModal.addEventListener('click', function(e) {
            if (e.target === this) closeDeleteModal();
        });
    }

    // Close on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeDeleteModal();
    });

    // ── Table row hover effect ──
    document.querySelectorAll('.admin-table tbody tr').forEach(function(row) {
        row.addEventListener('mouseenter', function() {
            this.style.background = 'rgba(255, 255, 255, 0.02)';
        });
        row.addEventListener('mouseleave', function() {
            this.style.background = 'transparent';
        });
    });

    // ── Action button hover effects ──
    document.querySelectorAll('.action-btn-edit').forEach(function(btn) {
        btn.addEventListener('mouseenter', function() {
            this.style.background = '#2563eb';
            this.style.color = '#fff';
        });
        btn.addEventListener('mouseleave', function() {
            this.style.background = 'rgba(37, 99, 235, 0.1)';
            this.style.color = '#60a5fa';
        });
    });

    document.querySelectorAll('.action-btn-delete').forEach(function(btn) {
        btn.addEventListener('mouseenter', function() {
            this.style.background = '#ef4444';
            this.style.color = '#fff';
        });
        btn.addEventListener('mouseleave', function() {
            this.style.background = 'rgba(239, 68, 68, 0.08)';
            this.style.color = '#f87171';
        });
    });
});

/**
 * Opens the delete confirmation modal
 */
function confirmDelete(id, type, title) {
    document.getElementById('modal-media-id').value = id;
    document.getElementById('modal-media-type').value = type;
    document.getElementById('modal-title-display').textContent = title;

    const isVideo = type === 'video';
    const badge = document.getElementById('modal-type-badge');
    badge.textContent = type.toUpperCase();
    badge.style.cssText = 'font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.12em;padding:3px 9px;border-radius:8px;' +
        (isVideo ?
            'background:rgba(239,68,68,.1);color:#ef4444;border:1px solid rgba(239,68,68,.2);' :
            'background:rgba(249,115,22,.1);color:#f97316;border:1px solid rgba(249,115,22,.2);');

    document.getElementById('delete-modal').classList.add('open');
}

/**
 * Closes the delete confirmation modal
 */
function closeDeleteModal() {
    const modal = document.getElementById('delete-modal');
    if (modal) modal.classList.remove('open');
}
