# Changelog — MEeL-HUB Core (UTAMA)

Mencatat perubahan pada inti platform: video, musik, buku, cloud drive, autentikasi, dan admin.
Modul arcade telah di-extract menjadi **ekstensi terpisah** — dicatat di [CHANGELOG-arcade.md](CHANGELOG-arcade.md).

Format mengikuti [Keep a Changelog](https://keepachangelog.com/), versi mengikuti
[Semantic Versioning](https://semver.org/) dengan tag `core-vX.Y.Z`.

## [Unreleased]

### Added
- **Auto-retry sentinel load-more:** file baru `assets/js/shared/sentinel-retry.js` — saat request load-more / rekomendasi watch gagal, handler menghapus `data-hx-revealed` dari sentinel sehingga scroll *berikutnya* memicunya lagi (retry digerakkan scroll user, tanpa loop otomatis); di halaman watch, `.rec-sentinel` hanya disembunyikan setelah load sukses (`detail.successful`)

### Changed
- **Infinite scroll video & music (tombol load-more diganti sentinel):** library video & music, hasil search, dan sidebar rekomendasi watch kini memuat konten otomatis lewat sentinel `hx-trigger="revealed"` (htmx memicu saat elemen masuk viewport): video `#load-more-area` (`video/index.php` + `video/load_more.php`, 15 kartu/load), music `#load-more-music` yang kini berada **di dalam `#music-list`** (`music/index.php` + `music/load_more_music.php`, 10 kartu/load — diletakkan di dalam list karena search menimpa `innerHTML` list). Tiap respons berisi kartu + sentinel pengganti sehingga rantai berlanjut otomatis dan berakhir dengan end box **"Out Of Content · Konten sudah tidak ada lagi"** (respons kosong juga menghasilkan end box yang sama). Hasil search video menyematkan `#load-more-area` yang sama; search music memakai id terpisah `#load-more-music-search` agar pembersihan `#load-more-music` pasca-search tidak mematikannya. Sidebar rekomendasi watch (`video/watch.php`, `music/watch.php`) ikut pola ini via `search?exclude=<id>` + swap `beforeend` — jalur sidebar saat search tetap tak berubah. Id sentinel wajib dipertahankan (CSS `overflow-anchor: none` + guard `isFromLoadMore` di `assets/js/music/index/index.js`). Pola terdokumentasi di `docs/{id,en}/development.md` ("Infinite Scroll (Sentinel) Pattern") dan endpoint di `docs/{id,en}/api.md`
- **Penomoran ulang migrasi database:** kunci `$migrations` di `database/migrate.php` diganti `16 → 2` dan `17 → 3` — v1 merupakan hasil regresi/konsolidasi migrasi lama v1–v15, sehingga migrasi lanjutan kini bernomor urut setelah v1; baris `db_version` dirapikan menjadi `{1, 2, 3}` (riwayat pra-regresi 2–15 dihapus, baris 16/17 diganti) agar guard `versi > MAX(db_version)` tetap mengeksekusi migrasi mendatang (v4…); dokumentasi migrasi di `docs/{id,en}/modules.md` dan `docs/{id,en}/index.md` ikut disesuaikan

### Removed
- **Tombol "Muat Lebih Banyak" / "Load More"** pada library video & music — manual pagination diganti auto-scroll
- **Teks progres halaman** (angka halaman "x/y") di UI video dan protokol `.lm-meta` pada respons load-more music
- **`assets/js/music/index/load-more.js`** — smooth-scroll + MutationObserver tidak kompatibel dengan rantai `revealed` (loop `scrollTo` akan terus menarik halaman tanpa henti); sisa referensinya di `library-ui.js` (`#load-more-btn`) berupa no-op ber-guard

### Fixed
- **Paginasi salah "1/1 → 2/1" saat hasil search kosong:** `SearchEngine::searchVideo()` / `searchMusic()` mengembalikan `total = 0` untuk hasil kosong sehingga perhitungan halaman rusak; total kini dihitung tanpa syarat (saat ini: video 242 item / 17 halaman, musik 92 item / 10 halaman)
- **Halaman tersentak ke atas saat infinite scroll (music):** pada swap `outerHTML`, `htmx:afterSwap` menyala **sekali per elemen hasil swap**, sementara kartu music tidak punya `id` (`targetId === ""`) — handler `htmx:afterSwap` di `assets/js/music/index/index.js` karenanya menjalankan `bootPlayerIndex()` (termasuk `scrollToActiveArtistDesktop()`, efek scroll artist-menu ke item aktif) per kartu dan menarik halaman ke atas saat scroll; kini dijaga guard `isFragmentSwap` sehingga fragment swap diperlakukan sebagai content update (hanya rebinding klik kartu)
- **Scroll posisi terbawa antar view (music):** `meelNavigateView()` (`assets/js/shared/view-router.js`) membersihkan lalu mengisi ulang `body` dalam satu task sehingga browser tak pernah meng-clamp scroll — posisi library index terbawa saat watch dibuka dari mini-player, dan script view dimuat sambil konten masih ter-scroll; kini `pushState` dipindahkan ke **sebelum** `await ensureViewScripts` (browser meng-capture scroll view lama saat entry ditinggalkan, jadi Back browser tetap memulihkan posisi lama) dan `window.scrollTo({behavior: "instant"})` mereset ke atas sebelum await. Selaras: `loadPlaylistById()` (`music/index/library-ui.js`) me-reset scroll saat konten playlist diganti total, dan toggle mini-player music watch menyimpan `savedWatchScrollY` lalu memulihkannya saat kembali ke watch (pola yang sama dengan video) — masuk mode mini juga mereset ke atas sehingga temp-index mulai bersih
