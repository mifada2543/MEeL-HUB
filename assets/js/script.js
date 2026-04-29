// Toggle HealthReminder (Mode Sehat) logic, auto-initialize jika tombol ada
function toggleHealth() {
    const current = localStorage.getItem('health_reminder') === 'true';
    localStorage.setItem('health_reminder', !current);
    location.reload();
}

function updateHealthToggleButton() {
    const btn = document.getElementById('healthToggle');
    if (btn) {
        btn.onclick = toggleHealth;
        const active = localStorage.getItem('health_reminder') === 'true';
        btn.className = btn.className.replace(/bg-(green|red)-500\/20|text-(green|red)-500/g, '').trim();
        if (active) {
            btn.classList.add('bg-green-500/20', 'text-green-500');
            btn.innerText = 'ON';
        } else {
            btn.classList.add('bg-red-500/20', 'text-red-500');
            btn.innerText = 'OFF';
        }
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', updateHealthToggleButton);
} else {
    updateHealthToggleButton();
}

function startHealthReminder() {
    const isEnabled = localStorage.getItem('health_reminder') === 'true';
    if (isEnabled) {
        setInterval(() => {
            showToast('MEeL Health Check: Waktunya istirahatkan mata (20-20-20). Lihat sejauh 6 meter selama 20 detik!');
        }, 20 * 60 * 1000);
    }
}

function showToast(message) {
    const toast = document.createElement('div');
    toast.className = 'fixed bottom-5 right-5 glass-effect p-4 rounded-2xl border border-blue-500/30 text-xs text-blue-400 z-[9999] animate-bounce';
    toast.innerHTML = `<i data-lucide='eye' class='w-4 h-4 inline mr-2'></i> ${message}`;
    document.body.appendChild(toast);
    setTimeout(() => { toast.remove(); }, 10000);
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', startHealthReminder);
} else {
    startHealthReminder();
}