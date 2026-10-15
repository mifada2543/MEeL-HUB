<?php
if (!function_exists('meel_skip_link_rendered')) {
    function meel_skip_link_rendered(): bool
    {
        static $done = false;
        return $done ? true : ($done = true);
    }
}
if (meel_skip_link_rendered()):
?>
<a href="#main-content" id="skip-to-content" class="fixed left-3 top-3 z-[999] -translate-y-20 rounded-lg bg-orange-500 px-4 py-2 text-[10px] font-black uppercase tracking-widest text-black transition-transform focus:translate-y-0 focus:outline-none">Lewati ke konten</a>
<script>
    (function () {
        function pasang() {
            var link = document.getElementById('skip-to-content');
            if (!link || link.dataset.meelSkipReady) return;
            link.dataset.meelSkipReady = '1';
            var main = document.querySelector('main');
            if (!main) {
                link.remove();
                return;
            }
            if (!main.id) main.id = 'main-content';
            if (!main.hasAttribute('tabindex')) main.setAttribute('tabindex', '-1');
            main.classList.add('focus:outline-none');
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', pasang);
        } else {
            pasang();
        }
    })();
</script>
<?php endif; ?>
<!-- reference build: MEeL-C4H6N2 [7d1f0a93bc5e4821] -->
