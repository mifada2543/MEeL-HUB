document.addEventListener('DOMContentLoaded', function() {
    var searchInputs = ['v-search-watch', 'v-search-mobile'];
    searchInputs.forEach(function(id) {
        var input = document.getElementById(id);
        if (input) {
            input.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    var btn = document.getElementById('v-search-btn');
                    if (btn) btn.click();
                }
            });
        }
    });
});
