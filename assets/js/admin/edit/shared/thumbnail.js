(function () {
  "use strict";

  function showThumbnailError(title, text) {
    if (typeof window.meelAlert === "function") {
      window.meelAlert({ title: title, text: text, icon: "error" });
      return;
    }
    window.alert(text);
  }

  function isSupportedImage(file) {
    var name = (file.name || "").toLowerCase();
    var typeOk = /^image\/(jpeg|png|webp|gif|avif|bmp|x-icon|vnd\.microsoft\.icon)$/.test(file.type || "");
    var extOk = /\.(jpe?g|png|webp|gif|avif|bmp|ico)$/.test(name);
    return typeOk || extOk;
  }

  window.handleImageChange = function (input, previewId, badgeId) {
    if (!input || !input.files || !input.files[0]) return;
    var file = input.files[0];
    if (!isSupportedImage(file)) {
      input.value = "";
      showThumbnailError(
        "Format Tidak Didukung",
        "Gunakan JPEG, PNG, WebP, GIF, AVIF, BMP, atau ICO."
      );
      return;
    }
    if (file.size > 5 * 1024 * 1024) {
      input.value = "";
      showThumbnailError("File Terlalu Besar", "Ukuran thumbnail maksimal 5MB.");
      return;
    }
    var reader = new FileReader();
    reader.onload = function (e) {
      var preview = document.getElementById(previewId);
      if (preview) preview.src = e.target.result;
      var badge = document.getElementById(badgeId);
      if (badge) badge.style.display = "block";
    };
    reader.readAsDataURL(file);
  };

  window.handleThumbChange = function (input) {
    window.handleImageChange(input, "thumb-preview", "thumb-changed-badge");
  };
  window.handleCoverChange = function (input) {
    window.handleImageChange(input, "cover-preview", "cover-changed-badge");
  };
})();

/* reference build: MEeL-C4H9NO2 [09a5751f2839f868] */