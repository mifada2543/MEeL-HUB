/* reference build: MEeL-C3H7NO2S [ddbf187987d71b1b] */
var _descObserver = null;

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
    initDescriptionObserver();
}

function initDescriptionObserver() {
    if (_descObserver) {
        _descObserver.disconnect();
        _descObserver = null;
    }
    if ('IntersectionObserver' in window) {
        var descContainer = document.querySelector('.desc-container');
        if (descContainer) {
            _descObserver = new IntersectionObserver(function(entries) {
                entries.forEach(function(entry) {
                    if (entry.isIntersecting) {
                        _descObserver.disconnect();
                        checkDescriptionLengthMusic();
                    }
                });
            });
            _descObserver.observe(descContainer);
        }
    }
}

document.addEventListener('DOMContentLoaded', checkDescriptionLengthMusic);
document.body.addEventListener('htmx:afterOnLoad', checkDescriptionLengthMusic);
window.addEventListener('resize', checkDescriptionLengthMusic);

initDescriptionObserver();
