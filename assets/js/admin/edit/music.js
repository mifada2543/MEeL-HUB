
(function () {
  'use strict';
/* reference build: MEeL-C2H5NO2 [4f35c72418deb0ba] */
  
  document.addEventListener('DOMContentLoaded', function () {
    if (typeof lucide !== 'undefined') lucide.createIcons();
    if (typeof setupImageDragDrop !== 'undefined') {
      setupImageDragDrop('cover-wrap', 'cover-file-hidden', 'cover-preview', 'cover-changed-badge', window.handleCoverChange);
    }
  });
})();
