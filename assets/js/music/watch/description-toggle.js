function toggleDescriptionMusic() {
    var descText = document.getElementById('desc-text-music');
    var btn = document.getElementById('btn-read-more-music');
    if (descText.classList.contains('line-clamp-3')) {
        descText.classList.remove('line-clamp-3');
        btn.textContent = 'Lebih Sedikit';
    } else {
        descText.classList.add('line-clamp-3');
        btn.textContent = 'Selengkapnya';
    }
}

function checkDescriptionLengthMusic() {
    var descText = document.getElementById('desc-text-music');
    var btn = document.getElementById('btn-read-more-music');
    if (descText && btn) {
        setTimeout(function() {
            if (!descText.classList.contains('line-clamp-3')) {
                btn.classList.remove('hidden');
                return;
            }
            var isOverflowing = descText.scrollHeight > descText.offsetHeight;
            if (isOverflowing) {
                btn.classList.remove('hidden');
            } else {
                btn.classList.add('hidden');
            }
        }, 50);
    }
}

document.addEventListener('DOMContentLoaded', checkDescriptionLengthMusic);
document.body.addEventListener('htmx:afterOnLoad', checkDescriptionLengthMusic);
window.addEventListener('resize', checkDescriptionLengthMusic);

var descContainer = document.querySelector('.desc-container');
if (descContainer && 'IntersectionObserver' in window) {
    var descObserver = new IntersectionObserver(function(entries) {
        entries.forEach(function(entry) {
            if (entry.isIntersecting) {
                descObserver.disconnect();
                checkDescriptionLengthMusic();
            }
        });
    });
    descObserver.observe(descContainer);
}
