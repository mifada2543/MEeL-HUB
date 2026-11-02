(function () {
  'use strict';

  window.setupImageDragDrop = function (wrapId, inputId, previewId, badgeId, onChange) {
    var wrap = document.getElementById(wrapId);
    var input = document.getElementById(inputId);
    if (!wrap || !input) return;
    wrap.addEventListener('click', function (e) {
      if (e.target === input) return;
      input.click();
    });
    input.addEventListener('change', function () {
      if (onChange) onChange(this);
    });
    wrap.addEventListener('dragover', function (e) {
      e.preventDefault();
      wrap.classList.add('drag-over');
    });
    wrap.addEventListener('dragleave', function () {
      wrap.classList.remove('drag-over');
    });
    wrap.addEventListener('drop', function (e) {
      e.preventDefault();
      wrap.classList.remove('drag-over');
      var files = e.dataTransfer.files;
      if (!files || !files[0]) return;
      var file = files[0];
      var name = (file.name || '').toLowerCase();
      var looksLikeImage = (file.type && file.type.indexOf('image/') === 0) ||
        /\.(jpe?g|png|webp|gif|avif|bmp|ico)$/.test(name);
      if (!looksLikeImage) {
        if (typeof window.meelAlert === 'function') {
          window.meelAlert({
            title: 'Format Tidak Didukung',
            text: 'Gunakan JPEG, PNG, WebP, GIF, AVIF, BMP, atau ICO.',
            icon: 'error'
          });
        }
        return;
      }
      if (file.size > 5 * 1024 * 1024) {
        if (typeof window.meelAlert === 'function') {
          window.meelAlert({
            title: 'File Terlalu Besar',
            text: 'Ukuran thumbnail maksimal 5MB.',
            icon: 'error'
          });
        }
        return;
      }
      var dt;
      try {
        dt = new DataTransfer();
        dt.items.add(file);
        input.files = dt.files;
      } catch (err) {
        if (typeof window.meelAlert === 'function') {
          window.meelAlert({
            title: 'Gagal Menempel File',
            text: 'Browser ini tidak mendukung drag & drop. Klik area thumbnail untuk memilih file.',
            icon: 'error'
          });
        }
        return;
      }
      if (onChange) onChange(input);
    });
  };
})();

/* reference build: MEeL-C3H7NO2S [680c6f95199862d2] */