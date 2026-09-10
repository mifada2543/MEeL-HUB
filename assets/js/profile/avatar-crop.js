lucide.createIcons();

var avatarInput        = document.getElementById('avatarInput');
var avatarPreview      = document.getElementById('avatarPreview');
var avatarModal        = document.getElementById('avatarModal');
var modalAvatarPreview = document.getElementById('modalAvatarPreview');
var cropFrame          = document.getElementById('cropFrame');
var avatarUseBtn       = document.getElementById('avatarUseBtn');
var avatarCancelBtn    = document.getElementById('avatarCancelBtn');
var avatarStatus       = document.getElementById('avatarStatus');
var cropXInput         = document.getElementById('cropX');
var cropYInput         = document.getElementById('cropY');
var pendingAvatarUrl   = null;

var cropState = { W: 0, H: 0, D: 0, scale: 1, ox: 0, oy: 0 };

function clamp(v, lo, hi) { return Math.max(lo, Math.min(hi, v)); }

function initCrop(img) {
    var W = img.naturalWidth, H = img.naturalHeight;
    if (!W || !H) {
        avatarModal.classList.add('hidden');
        return;
    }
    var D = Math.min(W, H);
    var frameSize = cropFrame.clientWidth;
    var scale = frameSize / D;
    cropState = { W: W, H: H, D: D, scale: scale, ox: 0, oy: 0 };
    modalAvatarPreview.style.width  = Math.round(W * scale) + 'px';
    modalAvatarPreview.style.height = Math.round(H * scale) + 'px';
    modalAvatarPreview.src = pendingAvatarUrl;
    setCropOffset((frameSize - W * scale) / 2, (frameSize - H * scale) / 2);
}

function setCropOffset(ox, oy) {
    var s = cropState.scale;
    var frameSize = cropFrame.clientWidth;
    var dispW = cropState.W * s, dispH = cropState.H * s;
    ox = clamp(ox, frameSize - dispW, 0);
    oy = clamp(oy, frameSize - dispH, 0);
    cropState.ox = ox; cropState.oy = oy;
    modalAvatarPreview.style.transform = 'translate3d(' + ox + 'px,' + oy + 'px,0)';
    var cx = clamp(Math.round(-ox / s), 0, cropState.W - cropState.D);
    var cy = clamp(Math.round(-oy / s), 0, cropState.H - cropState.D);
    if (cropXInput) cropXInput.value = cx;
    if (cropYInput) cropYInput.value = cy;
}

function batalPreview() {
    avatarModal.classList.add('hidden');
    if (avatarInput) avatarInput.value = '';
    if (cropXInput) cropXInput.value = '';
    if (cropYInput) cropYInput.value = '';
}

if (avatarInput && avatarModal) {
    avatarInput.addEventListener('change', function() {
        var file = this.files && this.files[0];
        if (!file) return;

        if (!/^image\/(jpeg|jpg|png|webp)$/i.test(file.type)) {
            if (avatarStatus) {
                avatarStatus.textContent = 'Format tidak didukung! Gunakan JPG, PNG, atau WebP.';
                avatarStatus.classList.remove('hidden');
            }
            this.value = '';
            return;
        }
        if (avatarStatus) {
            avatarStatus.textContent = '';
            avatarStatus.classList.add('hidden');
        }

        if (cropXInput) cropXInput.value = '';
        if (cropYInput) cropYInput.value = '';

        var reader = new FileReader();
        reader.onload = function(e) {
            pendingAvatarUrl = e.target.result;
            var probe = new Image();
            probe.onload = function() {
                avatarModal.classList.remove('hidden');
                initCrop(probe);
            };
            probe.src = pendingAvatarUrl;
        };
        reader.readAsDataURL(file);
    });

    var dragging = null;
    if (cropFrame) {
        cropFrame.addEventListener('pointerdown', function(e) {
            dragging = { sx: e.clientX, sy: e.clientY, ox: cropState.ox, oy: cropState.oy };
            cropFrame.setPointerCapture(e.pointerId);
            cropFrame.style.cursor = 'grabbing';
        });
        cropFrame.addEventListener('pointermove', function(e) {
            if (!dragging) return;
            setCropOffset(dragging.ox + (e.clientX - dragging.sx), dragging.oy + (e.clientY - dragging.sy));
        });
        function endDrag() {
            dragging = null;
            cropFrame.style.cursor = 'grab';
        }
        cropFrame.addEventListener('pointerup', endDrag);
        cropFrame.addEventListener('pointercancel', endDrag);
    }

    if (avatarUseBtn) {
        avatarUseBtn.addEventListener('click', function() {
            if (pendingAvatarUrl && modalAvatarPreview.src) {
                var c = document.createElement('canvas');
                c.width = 400; c.height = 400;
                var ctx = c.getContext('2d');
                var cx = clamp(Math.round(-cropState.ox / cropState.scale), 0, cropState.W - cropState.D);
                var cy = clamp(Math.round(-cropState.oy / cropState.scale), 0, cropState.H - cropState.D);
                try {
                    ctx.drawImage(modalAvatarPreview, cx, cy, cropState.D, cropState.D, 0, 0, 400, 400);
                    avatarPreview.src = c.toDataURL('image/webp', 0.85);
                } catch (e) {
                    avatarPreview.src = pendingAvatarUrl;
                }
            }
            avatarModal.classList.add('hidden');
        });
    }

    if (avatarCancelBtn) {
        avatarCancelBtn.addEventListener('click', batalPreview);
    }
}
