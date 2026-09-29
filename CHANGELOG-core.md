# Changelog — MEeL-HUB Core (UTAMA)

Mencatat perubahan pada inti platform: video, musik, buku, cloud drive, autentikasi, dan admin.
Modul arcade telah di-extract menjadi **ekstensi terpisah** — dicatat di [CHANGELOG-arcade.md](CHANGELOG-arcade.md).

Format mengikuti [Keep a Changelog](https://keepachangelog.com/), versi mengikuti
[Semantic Versioning](https://semver.org/) dengan tag `core-vX.Y.Z`.

## [Unreleased]

### Added
- **Model upscale baru (WebGPU):** `MEeLScale` (resampler separable: Bilinear, Mitchell (Bicubic), Catmull-Rom (Bicubic), Lanczos 2, Lanczos 3) di `assets/models/meelscale/model.js`, dan `FSRCNN ×2` (jaringan saraf ~13k parameter, eksekusi GPU bertile dengan halo + scissor) di `assets/models/fsrcnn/model.js`; keduanya terdaftar lewat `MEEL_UPSCALER.registerModel()` pada `upscaler.js` (shell ringan eager, aset berat dimuat saat model pertama dipilih — total unduhan saat pilihan pertama ≤ ~5 MB)
- **FSRCNN dilatih lokal:** `scripts/train-fsrcnn.js` (trainer vanilla JS, tidak ikut repo — hanya bobot jadi yang beredar) melatih bobot dari frame video lokal (tanpa unduhan weights dari internet); eval penuh n=150 → PSNR **32.409 dB** (+0.019 vs bicubic, +1.101 vs bilinear); bobot tersimpan di `assets/models/fsrcnn/weights.js`
- **Diagnostik performa upscale (F0):** `MEEL_UPSCALER.diagnose().perf` dan `stats().perf` → `{msAvg, msP95, videoFps, renderFps, budgetMs, fitsBudget, samples, skipped, timeouts}` — proxy waktu GPU per frame (`submit` → `onSubmittedWorkDone`, jendela 120 sampel) untuk memutuskan apakah perlu model ringan
- **Test integrasi MEeLCoin:** `tests/integration/MeelCoinIntegrationTest.php` (15 test) — mengunci kontrak atomik `spend`/`refund`/`refill`, semantik siklus refill, dan countdown per-user
- **Migrasi v16 (`database/migrate.php`):** normalisasi state MEeLCoin — `meelcoin_upload_cost` & `meelcoin_advanced_cost` minimum `1`
- **Model upscale baru (WebGPU):** `MEeLSharp` di `assets/models/meelsharp/model.js` — Lanczos-3 **separable** (pass horizontal → pass vertical, tekstur antara `rgba16float`, tepi clamp-to-edge) + anti-ringing dari min/max kotak sumber terdekat + sharpening **FidelityFX CAS (MIT)** di ruang warna masukan (rumus & atribusi di komentar shader); tiga mode **Seimbang** (bawaan, `modes[0]`) / **Lembut** / **Tajam** yang hanya mengganti uniform `sharpness` sehingga ganti mode cukup rebuild ringan; ukuran keluaran mengikuti `o.target` dari host (mis. 1080p → 2560×1440) dan tidak ada bobot maupun aset unduhan; terdaftar lewat `MEEL_UPSCALER.registerModel()` pada `upscaler.js`

### Changed
- **AI Upscale: default OFF & status on/off per video (sessionStorage):** status ON pindah dari localStorage ke `sessionStorage` dengan nilai `true|<videoId>` (OFF = `false`), jadi tab baru selalu mulai **OFF**, sedangkan **refresh** dan **loop** video yang sama tetap mempertahankan ON; setiap **pindah video selalu mematikan upscale** agar beban GPU client tidak ikut pindah — lewat klik rekomendasi/tombol next/kartu mini-player maupun auto-next (`skipToNextVideo()` dan listener kartu mini-player kini memanggil `MEEL_UPSCALER.resetForNewVideo()`), dan lewat navigasi penuh ke URL video lain (id divalidasi ulang `readEnabled()` di `validateState()`, termasuk saat instance Plyr tidak berubah). Reset tanpa toast supaya transisi tetap halus, seluruh pekerjaan mati disatukan di `turnOff()`, `modelId`/`modeId`/`scaleId` tetap di localStorage, dan nilai `meel_upscale_enabled` lama di localStorage dibersihkan saat skrip dimuat; perilaku terdokumentasi di `docs/{id,en}/development.md` (Status on/off per video)
- **Rename model upscale `fsrcnn` → `meelvision` (MEeLVision):** nama model hasil build (jaringan ~13k parameter, arsitektur FSRCNN ×2) di menu Model kini **MEeLVision** — id `meelvision`, folder `assets/models/meelvision/` (`model.js` + `weights.js`), global `window.MEEL_VISION_WEIGHTS_B64`, label objek GPU `meel-vision-*`, dan trainer `scripts/train-meelvision.js` ikut berganti agar id = folder = label tetap konsisten; pilihan model lama di localStorage (`fsrcnn`) otomatis dimigrasi ke `meelvision` (bila tak dikenal, `validateState()` tetap jatuh ke `anime4k`). Istilah arsitektur FSRCNN dipertahankan di docs teknis, label mode menu, dan header bobot; entri CHANGELOG sebelumnya memakai nama lama
- **Gating opsi AI Upscale saat WebGPU tak tersedia:** baris **AI Upscale** tetap muncul di menu Pengaturan dengan `aria-disabled="true"` + class `meel-upscale-unavail` (redup tapi bisa diklik) dan nilai `Tidak tersedia` — tanpa toast otomatis saat halaman dibuka; klik baris membuka sub-panel read-only `upscale-why` berisi alasan spesifik per penyebab (`insecure` / `nogpu` / `no-adapter`, bersumber dari `unsupportedReason()` + konstanta `WHY` yang dipakai bersama `supportLabel()` dan `diagnose()`), daftar persyaratan, dan tombol "Cek ulang dukungan" (`checkSupport(true)` → bila kini didukung: toast + pindah ke panel normal); selama pemeriksaan baris menampilkan spinner tanpa disabled (anti-kedip), dan `buildSubPanel()` kini menerima opsi `backTo`/`backLabel`/`role` untuk panel yang induknya menu home. Gaya di `upscaler.css`, perilaku terdokumentasi di `docs/{id,en}/development.md` (Ketersediaan opsi / Option gating)
- **Pembersihan komentar seluruh kode first-party:** 88 file di `assets/`, `modules/`, `controllers/`, `auth/`, `admin/`, `profile/`, `video/`, `music/`, `books/`, `drive/`, `partials/`, `tests/`, `scripts/`, dan `arcade/` — baris komentar turun 1210 → 567 (−53%): banner `====`/`────`/header bernomor, komentar kosong & duplikat, komentar langkah-demi-langkah di test, serta docblock dan `@param`/`@return` yang hanya menduplikasi signature dihapus; komentar *why* (invarian keamanan, race, kontrak array/return, perilaku browser/GPU/DB) tetap dipertahankan. Kriteria "kapan komentar dihapus" terdokumentasi di `docs/{id,en}/development.md` (Gaya Komentar), dan `scripts/verify_security.sh --help` beralih ke heredoc alih-alih memotong baris dirinya sendiri. Marker `reference build:` (hasil `marks.php`) dan file vendor tidak tersentuh; audit token PHP membuktikan nol perubahan kode, dilengkapi `php -l` 53 file, `node --check` 15 file, `bash -n`, dan PHPUnit 465 OK
- **Pembersihan komentar JS & dokumentasi developer:** banner seksi `/* ==== n. Nama ==== */` dihapus dari `upscaler.js`, header file 34 → 6 baris, dan komentar yang hanya mengulang kode dipangkas (`plyr-config.js`, `recovery.js`, `player-events.js`, termasuk `assets/models/{fsrcnn,meelscale}/model.js` + `fsrcnn/weights.js` yang header-nya kini menunjuk ke dokumentasi alih-alih mengulang kontrak); pengetahuan yang terlalu panjang untuk komentar dipindahkan ke `docs/id/development.md` + `docs/en/development.md` (bagian baru "Video — AI Upscale & Play Recovery": kontrak API model upscale, arsitektur/layout bobot FSRCNN + strategi bertile, parameter kernel MEeLScale, pipeline backpressure + `diagnose().perf`, watchdog rVFC, alur recovery hidden→visible, catatan CSP `blankVideo`) beserta aturan gaya komentar baru di "Coding Conventions" (bebas untuk *why*, dilarang mengulang kode, tanpa banner seksi)
- **Struktur model upscale → `assets/models/{id}/` (lazy-load):** `meelscale/model.js`, `fsrcnn/model.js` + `fsrcnn/weights.js`, dan bundle vendor `anime4k/model.js` pindah dari `assets/js/`; shell `upscaler.js` hanya mendaftarkan deskriptor statis (id/label/modes) dan file model diinjeksi saat pertama dipilih — `registerModel()` menimpa deskriptor, lalu aset beratnya (bobot FSRCNN) dimuat sekali jalan; `SharedJsTest` kini melintasi `assets/models/` (vendor `anime4k/` dikecualikan seperti `compatibilitas/`); rujukan `scripts/train-fsrcnn.js` & `assets/js/VERSIONS.md` ikut diperbarui
- **Proses build model di-`.gitignore`:** trainer `scripts/train-fsrcnn.js` di-untrack dari repo (`git rm --cached`) beserta artefaknya (`fsrcnn-ref*.json`, `*.weights.bin`, `scripts/.train-*/`, `*.train.log`) — yang masuk repo hanya bobot jadi `assets/models/fsrcnn/weights.js` agar user tinggal pakai
- **MEeLCoin atomik:** `MeelCoin::spend()`/`refund()`/`refill()` memakai satu `UPDATE` dengan guard saldo (bukan read-modify-write), sehingga potongan saldo tidak bisa tertimpa request lain yang berjalan bersamaan
- **Refill = siklus, bukan tabungan:** `refill()` meng-reset `meelcoin_last_refill` saat saldo penuh, dan cap ke `meelcoin_user_max`/`meelcoin_member_max` dihitung di DB (`LEAST`)
- **Countdown refill per-user:** `MeelCoin::getRefillCountdown()` mengikuti `meelcoin_last_refill` user tersebut (sebelumnya siklus wall-clock global yang identik untuk semua user); nilai `0` = siap refill
- **Biaya upload wajib ≥ 1:** panel admin MEeLCoin menolak biaya `0`; runtime melewati alur coin bila biaya ≤ 0
- **Refund lebih ketat:** `upload_advanced.php` hanya me-refund kegagalan terkonfirmasi (`''`, `DISCONNECTED`, `Download gagal*`, `File audio tidak ditemukan*`); hasil tak dikenal tidak direfund dan dicatat ke error log
- **`QueueReconciler` dihapus (kode mati berbahaya):** nol panggilan produksi, `checkDownloadedFile()` mengabaikan argumennya (antrean gagal bisa ditandai `completed` dari file temp siapa pun), dan menulis status `orphaned` yang di luar enum `upload_queue.status`. Refund kini berjalan di momen kegagalan (`upload_failed_refund` / `transcode_refund`); status antrean ditulis `releaseQueue()` setelah finalize; 2 test reflection-nya ikut dihapus
- **Sinkronisasi saldo di UI:** `meelRefreshCoinBalance()` (`assets/js/engine/result.js`) menyegarkan `#coin-balance` setelah operasi selesai pada halaman upload & transcode
- **MEeLVision dilepas dari menu AI Upscaler:** blok `registerModel()` untuk `meelvision` dihapus dari `upscaler.js`, jadi menu Model kini `anime4k` / `MEeLScale` / `MEeLSharp`; pilihan lama di localStorage (`fsrcnn` maupun `meelvision`) dimigrasikan ke `meelsharp` (bila id tak dikenal, `validateState()` tetap jatuh ke `anime4k`). File `assets/models/meelvision/` (`model.js` + `weights.js`) **tetap di repo** beserta catatan teknisnya; daftar model bawaan di `docs/{id,en}/development.md` ikut diperbarui

### Fixed
- **Kedip hitam & canvas beku saat ganti kualitas/resolusi video (upscale AI):** ganti level HLS atau ganti `src` membuat `videoWidth` berubah sebelum stabil, sementara canvas upscale masih menampilkan frame lama. Kini perubahan resolusi di-*debounce* 600 ms (`rebuildReason: 'resolution'`): selama menunggu class `meel-upscale-active` dilepas sehingga video asli tampil tanpa frame hitam, copy lintas ukuran dilewati (`skipped`), dan class dipasang kembali oleh `renderFrame()` hanya setelah frame baru sukses dirender; ganti resolusi yang batal (ukuran kembali sama) hanya melanjutkan loop tanpa rebuild. Alasan rebuild kini tercatat (`enable|model|mode|scale|resolution|resize|attach`), dan state loop terpantau via `stats()` (`loopPending`, `loopRvfc`, `loopRaf`, `pendingRender`, `gpuBusy`)
- **Upscale freeze senyap setelah tab di-background atau `load()`:** `load()` (ganti src) menggugurkan callback `requestVideoFrameCallback` yang masih tertunda sehingga `loop.pending` mentok `true` dan loop render berhenti (canvas beku walau video terus bermain) — kini callback direset saat `loadstart` + loop di-*kick* saat `loadeddata`; ditambah watchdog loop tiap 500 ms yang mendaftarkan ulang callback bila tab aktif, video bermain, tapi tak ada tick >700 ms (Chrome bisa membuang callback saat transisi hidden↔visible); `visibilitychange` → aktif menyegarkan frame dan menjadwalkan render
- **Error console CSP `media-src` + error media berantai setelah `player.destroy()`:** Plyr memanggil `cancelRequests()` dengan `blankVideo` bawaan (`https://cdn.plyr.io/static/blank.mp4`) — URL eksternal itu melanggar CSP `media-src 'self' data: blob:` sehingga memicu `MEDIA_ELEMENT_ERROR` berantai. Kini `blankVideo` diset ke data URI lokal di `plyr-config.js` (berlaku untuk video, musik, dan audio) tanpa melonggarkan CSP
- **Recovery jalan saat tab di-background (video freeze saat user kembali):** `triggerPlayerRecovery()` menunda pemulihan sampai `visibilitychange` → aktif (aturan cooldown tetap berlaku untuk pemulihan tertunda), autoplay retry (`pendingPlayRetry`) menunggu tab aktif, dan stuck-detector `RecoveryManager` me-reset baseline `lastTime`/`lastTs` saat hidden — sebelumnya `play()` ditolak senyap dan video freeze
- **AI Upscale freeze saat playback (antrian GPU menumpuk):** chain render + blit di-`submit()` seburst tiap frame video tanpa menunggu GPU selesai — antrian menumpuk lalu GPU idle mendadak (clock mentok ±1100 MHz / busy 80% → jatuh ±2%) dan video berhenti. Kini hanya ada satu submit *in-flight*: saat GPU masih sibuk frame dilewati (`stats.skipped`) dan dirender ulang begitu `onSubmittedWorkDone()` selesai (catch-up; render paksa seek/loadeddata/rebuild ikut antrian, tidak pernah hilang); timeout 3 dtk ×3 berturut-turut → upscale off + toast. Ketersediaan & waktu GPU terukur via `diagnose().perf`
- **Saldo MEeLCoin kadang tidak terpotong setelah upload advanced berhasil:** user yang lama berada di saldo penuh menyimpan "refill tertunda" (timer tidak pernah di-reset saat saldo penuh) sehingga refill di request berikutnya menutup potongan coin
- Countdown refill di halaman profil menampilkan siklus penuh saat sudah waktunya refill (seharusnya "Siap")

## [1.0.0] - 2026-09-19

### Added

- **Video Streaming:** HLS adaptive streaming dengan subtitle, resolusi switching, PiP, resume otomatis, countdown auto-next, ambient glow, dan koneksi recovery
- **Music Player:** Pemutar audio (MP3, FLAC, OGG, M4A) dengan WebAudio visualizer, playlist, antrean cerdas, dan mini-player persisten
- **Lyrics/Karaoke:** Lirik karaoke real-time dengan sinkronisasi timestamp LRC, multi-bahasa, LRC Editor (mode Simple & Synced), keyboard shortcut `K`
- **Books:** Pembaca manga (ZIP/CBZ) dan PDF dengan thumbnail otomatis
- **Cloud Drive:** Penyimpanan file publik/privat dengan kuota per anggota, magic bytes validation
- **Authentication:** Login, registrasi, session management, CSRF protection, MFA (TOTP)
- **RBAC:** 4 level akses (Admin, Member, User, Guest) dengan permission checking di setiap endpoint
- **Admin Dashboard:** Manajemen konten, pengguna, modul, transcode, dan log audit
- **Transcoder:** FFmpeg-based transcoding untuk video dan audio
- **Video Downloader:** Integrasi yt-dlp untuk mengunduh video dari URL
- **Rate Limiting:** Pembatasan laju request per IP
- **Audit Trail:** Logging aktivitas pengguna untuk keamanan
- **IP Banning & Firewall:** Blokir IP dan proteksi brute-force
- **PWA:** Service worker, offline support, precache generator
- **Anti-SSRF Proxy:** Validasi URL untuk mencegah SSRF attacks
- **HTMX Integration:** Pembaruan halaman dinamis tanpa full reload
- **TailwindCSS:** Responsive design dengan dark/light mode
- **Testing:** 369+ test cases (unit, integration, functional, security)
- **Installer:** `install.sh` satu komando untuk setup lengkap

### Changed
- Arcade di-extract menjadi ekstensi terpisah (database dan migration terpisah)
- Router dan autoloader dikembangkan secara mandiri (tanpa framework)

### Fixed
- Duplikasi CSS `light-theme.css` di `index.php`
- Self-referencing link di `docs/en/arcade-optional.md`
- Sitemap `robots.txt` menggunakan path relatif (bukan localhost)

<!--
## [1.1.0] - YYYY-MM-DD
### Added
-
-->
