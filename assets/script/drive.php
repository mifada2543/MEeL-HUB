<script>
    lucide.createIcons();

    // ── Sidebar toggle (mobile) ──
    function openSidebar() {
        document.getElementById('sidebar').classList.add('open');
        document.getElementById('overlay').classList.add('active');
    }

    function closeSidebar() {
        document.getElementById('sidebar').classList.remove('open');
        document.getElementById('overlay').classList.remove('active');
    }

    // ── Section switcher ──
    const counts = {
        video: <?= count($videos) ?>,
        audio: <?= count($audios) ?>,
        dokumen: <?= count($dokumens) ?>
    };
    const accents = {
        video: {
            color: 'var(--accent-r)',
            label: 'Video'
        },
        audio: {
            color: 'var(--accent-o)',
            label: 'Audio'
        },
        dokumen: {
            color: 'var(--accent-g)',
            label: 'Dokumen'
        }
    };

    function showSection(id, btn, source) {
        // Hide all sections
        document.querySelectorAll('.drive-section').forEach(s => s.style.display = 'none');
        document.getElementById('drive-' + id).style.display = 'block';

        // Update heading
        document.getElementById('sectionAccent').textContent = accents[id].label;
        document.getElementById('sectionAccent').style.color = accents[id].color;
        document.getElementById('fileCount').textContent = counts[id] + ' file';

        // Desktop nav highlight
        document.querySelectorAll('.nav-btn-desktop').forEach(b => {
            b.className = 'nav-btn-desktop nav-item';
        });
        if (btn) {
            btn.className = 'nav-btn-desktop nav-item active';
        }

        // Mobile tab highlight
        ['video', 'audio', 'dokumen'].forEach(t => {
            const el = document.getElementById('tab-' + t);
            if (el) el.classList.toggle('active', t === id);
        });

        // Animate cards
        document.querySelectorAll('#drive-' + id + ' .file-card').forEach((card, i) => {
            card.style.animation = 'none';
            card.offsetWidth;
            card.style.animation = `fadeUp .3s ease-out ${i * 40}ms both`;
        });
    }

    // Init
    showSection('video', document.querySelector('.nav-btn-desktop'));

    // ── File input label ──
    function updateFileName(input) {
        const label = document.getElementById('fileLabel');
        if (input.files && input.files[0]) {
            label.textContent = input.files[0].name;
            label.style.color = '#e5e7eb';
        }
    }

    // ── Preview modal ──
    function openPreview(path, type, filename) {
        const modal = document.getElementById('previewModal');
        const content = document.getElementById('previewContent');
        const title = document.getElementById('previewTitle');

        content.innerHTML = '<div style="display:flex;flex-direction:column;align-items:center;gap:12px;padding:40px;color:#4b5563;"><i data-lucide="loader-2" style="width:28px;height:28px;animation:spin 1s linear infinite;"></i><span style="font-size:12px;">Memuat...</span></div>';
        title.innerText = decodeURIComponent(filename);
        modal.style.display = 'flex';
        lucide.createIcons();

        const decodedPath = decodeURIComponent(path);
        let html = '';

        if (type === 'video') {
            html = `<video controls autoplay style="width:100%;max-height:70vh;background:#000;">
            <source src="${decodedPath}" type="video/mp4">
            Browser kamu tidak mendukung preview video.
        </video>`;
        } else if (type === 'audio') {
            html = `<div style="padding:48px 24px;width:100%;text-align:center;">
            <div style="width:72px;height:72px;border-radius:20px;background:rgba(249,115,22,.1);display:flex;align-items:center;justify-content:center;margin:0 auto 20px;">
                <i data-lucide="music" style="width:32px;height:32px;color:var(--accent-o);"></i>
            </div>
            <div style="font-size:14px;font-weight:600;color:#d1d5db;margin-bottom:20px;word-break:break-all;">${decodeURIComponent(filename)}</div>
            <audio controls style="width:100%;max-width:360px;">
                <source src="${decodedPath}" type="audio/mpeg">
            </audio>
        </div>`;
        } else {
            const ext = decodeURIComponent(filename).split('.').pop().toLowerCase();
            const imgExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
            if (imgExts.includes(ext)) {
                html = `<img src="${decodedPath}" style="max-width:100%;max-height:70vh;object-fit:contain;padding:16px;">`;
            } else {
                html = `<div style="padding:48px;text-align:center;">
                <div style="width:64px;height:64px;border-radius:18px;background:rgba(255,255,255,.04);display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
                    <i data-lucide="file-warning" style="width:28px;height:28px;color:#374151;"></i>
                </div>
                <p style="font-size:13px;color:#6b7280;margin:0 0 16px;">Preview tidak tersedia untuk format ini.</p>
                <a href="${decodedPath}" download style="font-size:11px;font-weight:700;color:var(--accent-b);text-transform:uppercase;letter-spacing:.08em;text-decoration:none;border:1px solid rgba(59,130,246,.3);padding:8px 18px;border-radius:8px;">Download File</a>
            </div>`;
            }
        }

        setTimeout(() => {
            content.innerHTML = html;
            lucide.createIcons();
        }, 350);
    }

    function closePreview() {
        document.getElementById('previewModal').style.display = 'none';
        document.getElementById('previewContent').innerHTML = '';
    }

    document.getElementById('previewModal').addEventListener('click', function(e) {
        if (e.target === this) closePreview();
    });

    // spin keyframe for loader
    const style = document.createElement('style');
    style.textContent = '@keyframes spin{to{transform:rotate(360deg)}}';
    document.head.appendChild(style);
</script>