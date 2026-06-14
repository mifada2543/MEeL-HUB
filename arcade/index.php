<?php
// Data Game Arcade didefinisikan dalam Array Asosiatif PHP
$games = [
  [
    "id" => 1,
    "title" => "Miku & Teto Run",
    "developer" => "MEeL Teams",
    "year" => 2026,
    "category" => "Action / Beat 'em up",
    "controls" => "Up Arrow to Jump & Down Arrow to Slide",
    "maxPlayers" => "1 Players",
    "rating" => 4.9,
    "description" => "Kendalikan Miku/Teto di track panjang yang menyenangkan dengan rintangan unik dan power-up menarik. Cocok untuk semua usia, game ini menggabungkan elemen puzzle sederhana dengan kecepatan arcade yang seru. Tantang temanmu untuk skor tertinggi atau nikmati mode solo untuk mengasah refleksmu!",
    "image" => "assets/img/dino.png",
    "favorite" => false,
    "play_url" => "dino.html"
  ],
  [
    "id" => 2,
    "title" => "Chess",
    "developer" => "MEeL Teams",
    "year" => 2026,
    "category" => "Strategy",
    "controls" => "Pawn, Knight, Rook, Bishop, Queen, King",
    "maxPlayers" => "2 Players",
    "rating" => 5.0,
    "description" => "Game strategi klasik yang membutuhkan pemikiran taktis dan perencanaan jangka panjang. Susun bidak-bidakmu dengan cermat untuk mengalahkan lawan dan menguasai papan catur.",
    "image" => "assets/img/catur.png",
    "favorite" => false,
    "play_url" => "chess.html"
  ]
];
?>
<!DOCTYPE html>
<html lang="id">

<head>
  <meta charset="UTF-8" />
  <meta name="description" content="Koleksi game arcade klasik yang ikonik, lengkap dengan detail spesifikasi kabinet dan fitur favorit untuk para penggemar nostalgia." />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Koleksi Game Arcade Klasik</title>
  <?php include '../partials/link.php'; ?>
  <link href="../assets/css/font.css" rel="stylesheet" />
  <style>
    .font-retro {
      font-family: "Press Start 2P", cursive;
    }

    .font-modern {
      font-family: "Rajdhani", sans-serif;
    }

    /* Efek Neon Glow */
    .neon-text-pink {
      text-shadow:
        0 0 5px rgba(244, 63, 94, 0.8),
        0 0 20px rgba(244, 63, 94, 0.4);
    }

    .neon-text-blue {
      text-shadow:
        0 0 5px rgba(59, 130, 246, 0.8),
        0 0 20px rgba(59, 130, 246, 0.4);
    }

    .neon-border-pink {
      box-shadow:
        0 0 10px rgba(244, 63, 94, 0.5),
        inset 0 0 5px rgba(244, 63, 94, 0.3);
    }

    .neon-border-blue {
      box-shadow:
        0 0 10px rgba(59, 130, 246, 0.5),
        inset 0 0 5px rgba(59, 130, 246, 0.3);
    }

    /* Custom Scrollbar */
    ::-webkit-scrollbar {
      width: 8px;
    }

    ::-webkit-scrollbar-track {
      background: #0f172a;
    }

    ::-webkit-scrollbar-thumb {
      background: #1e293b;
      border-radius: 4px;
    }

    ::-webkit-scrollbar-thumb:hover {
      background: #3b82f6;
    }
  </style>
</head>

<body
  class="bg-slate-950 text-slate-100 font-modern min-h-screen flex flex-col selection:bg-pink-500 selection:text-white">
  <!-- Header / Navbar -->
  <header
    class="border-b border-slate-800 bg-slate-900/80 backdrop-blur-md sticky top-0 z-40 transition-all duration-300">
    <div
      class="max-w-7xl mx-auto px-4 py-4 sm:px-6 lg:px-8 flex flex-col sm:flex-row justify-between items-center gap-4">
      <div class="flex items-center gap-3">
        <div
          class="p-2 bg-pink-500/10 rounded-lg border border-pink-500/30 animate-pulse cursor-pointer"
          onclick="window.location.href='../index.php'" title="Kembali ke MEeL Hub">
          <img
            src="../assets/MEeL.png"
            alt="Logo MEeL-Arcade"
            class="w-10 h-10 object-contain" />
        </div>
        <div>
          <h1
            class="text-xl sm:text-2xl font-bold tracking-wider font-retro text-transparent bg-clip-text bg-gradient-to-r from-pink-500 to-blue-500 neon-text-pink">
            MEeL-Arcade
          </h1>
          <p class="text-xs text-slate-400 tracking-widest uppercase">
            Koleksi Game & Kabinet Klasik
          </p>
        </div>
      </div>

      <!-- Quick Stats -->
      <div class="flex gap-4 text-sm">
        <div
          class="bg-slate-950/60 border border-slate-800 px-3 py-1.5 rounded-lg flex items-center gap-2">
          <span
            class="w-2.5 h-2.5 rounded-full bg-emerald-500 animate-ping"></span>
          <span class="text-slate-400">Total Game:</span>
          <span id="stat-total" class="font-bold text-pink-400"><?= count($games) ?></span>
        </div>
        <div
          class="bg-slate-950/60 border border-slate-800 px-3 py-1.5 rounded-lg flex items-center gap-2">
          <i
            data-lucide="star"
            class="w-4 h-4 text-yellow-500 fill-yellow-500"></i>
          <span class="text-slate-400">Favorit:</span>
          <span id="stat-fav" class="font-bold text-blue-400">0</span>
        </div>
      </div>
    </div>
  </header>

  <!-- Main Content -->
  <main class="flex-grow max-w-7xl w-full mx-auto px-4 py-8 sm:px-6 lg:px-8">
    <!-- Controls (Search & Filter) -->
    <section
      class="mb-8 bg-slate-900/40 border border-slate-800/80 rounded-2xl p-6 backdrop-blur-sm">
      <div
        class="flex flex-col md:flex-row gap-4 items-center justify-between">
        <!-- Search Input -->
        <div class="relative w-full md:max-w-md">
          <span
            class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
            <i data-lucide="search" class="h-5 w-5 text-slate-500"></i>
          </span>
          <input
            type="text"
            id="search-input"
            placeholder="Cari judul game atau developer..."
            class="w-full pl-10 pr-4 py-2.5 bg-slate-950 border border-slate-800 focus:border-pink-500 rounded-xl text-slate-200 placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-pink-500/20 transition-all duration-200" />
        </div>

        <!-- Filters & Sort -->
        <div
          class="flex flex-wrap gap-3 w-full md:w-auto justify-start md:justify-end">
          <!-- Category Filter -->
          <select
            id="category-filter"
            class="px-4 py-2.5 bg-slate-950 border border-slate-800 focus:border-blue-500 rounded-xl text-slate-300 focus:outline-none focus:ring-2 focus:ring-blue-500/20 transition-all duration-200 cursor-pointer">
            <option value="all">Semua Kategori</option>
            <option value="Action">Action / Beat 'em up</option>
            <option value="Shooter">Space Shooter / Shmup</option>
            <option value="Fighting">Fighting</option>
            <option value="Platformer">Platformer</option>
            <option value="Puzzle">Puzzle</option>
            <option value="Racing">Racing</option>
          </select>

          <!-- Sort Filter -->
          <select
            id="sort-filter"
            class="px-4 py-2.5 bg-slate-950 border border-slate-800 focus:border-blue-500 rounded-xl text-slate-300 focus:outline-none focus:ring-2 focus:ring-blue-500/20 transition-all duration-200 cursor-pointer">
            <option value="newest">Tahun (Terbaru)</option>
            <option value="oldest">Tahun (Terlama)</option>
            <option value="alphabetical">Nama (A - Z)</option>
            <option value="rating">Rating Tertinggi</option>
          </select>

          <!-- Favorites Toggle -->
          <button
            id="fav-toggle-btn"
            class="flex items-center gap-2 px-4 py-2.5 bg-slate-950 border border-slate-800 hover:border-pink-500 hover:text-pink-500 rounded-xl text-slate-300 transition-all duration-200">
            <i data-lucide="heart" class="w-5 h-5"></i>
            <span>Hanya Favorit</span>
          </button>
        </div>
      </div>
    </section>

    <!-- Grid Tampilan Game -->
    <section>
      <div
        id="game-grid"
        class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
        <!-- Kartu-kartu game di-render pertama kali menggunakan PHP loop -->
        <?php foreach ($games as $game): ?>
          <div
            class="bg-slate-900 border border-slate-800/80 rounded-2xl overflow-hidden hover:border-pink-500/50 transition-all duration-300 group flex flex-col h-full hover:scale-[1.02] hover:shadow-lg hover:shadow-pink-500/5 cursor-pointer game-card-item"
            data-id="<?= $game['id'] ?>"
            data-title="<?= htmlspecialchars(strtolower($game['title'])) ?>"
            data-developer="<?= htmlspecialchars(strtolower($game['developer'])) ?>"
            data-category="<?= htmlspecialchars($game['category']) ?>"
            data-year="<?= $game['year'] ?>"
            data-rating="<?= $game['rating'] ?>"
            onclick="openModal(<?= $game['id'] ?>)">
            <div class="relative h-48 overflow-hidden bg-slate-950">
              <img
                src="<?= htmlspecialchars($game['image']) ?>"
                alt="<?= htmlspecialchars($game['title']) ?>"
                class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-110 opacity-85 group-hover:opacity-100">
              <div class="absolute top-3 left-3 bg-slate-950/80 backdrop-blur-md border border-slate-800 px-2.5 py-1 rounded-lg text-xs font-semibold text-slate-300">
                <?= $game['year'] ?>
              </div>
              <button
                onclick="toggleFavorite(event, <?= $game['id'] ?>)"
                class="fav-btn absolute top-3 right-3 p-2 rounded-lg backdrop-blur-md border transition-all duration-200"
                data-game-id="<?= $game['id'] ?>">
                <i data-lucide="heart" class="w-4 h-4"></i>
              </button>
            </div>
            <div class="p-5 flex flex-col flex-grow">
              <div class="flex items-center justify-between gap-2 mb-2">
                <span class="text-xs font-bold text-blue-400 tracking-wider uppercase"><?= htmlspecialchars($game['category']) ?></span>
                <div class="flex items-center gap-1 text-xs text-amber-400">
                  <i data-lucide="star" class="w-3.5 h-3.5 fill-amber-400"></i>
                  <span><?= number_format($game['rating'], 1) ?></span>
                </div>
              </div>
              <h3 class="text-lg font-bold text-white group-hover:text-pink-400 transition-colors duration-200 line-clamp-1 mb-1">
                <?= htmlspecialchars($game['title']) ?>
              </h3>
              <p class="text-xs text-slate-500 font-semibold mb-4"><?= htmlspecialchars($game['developer']) ?></p>
              <p class="text-sm text-slate-400 line-clamp-2 mb-4 flex-grow"><?= htmlspecialchars($game['description']) ?></p>
              <div class="pt-4 border-t border-slate-800 flex items-center justify-between text-xs text-slate-500">
                <span class="flex items-center gap-1">
                  <i data-lucide="users" class="w-3.5 h-3.5 text-slate-600"></i>
                  <?= explode(' ', $game['maxPlayers'])[0] ?> Pemain
                </span>
                <span class="flex items-center gap-1">
                  <i data-lucide="sliders" class="w-3.5 h-3.5 text-slate-600"></i>
                  <?= explode(' ', $game['controls'])[0] ?>
                </span>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <!-- State Kosong (Ketika Pencarian Tidak Ditemukan) -->
      <div
        id="empty-state"
        class="hidden flex-col items-center justify-center py-16 text-center">
        <div
          class="p-4 bg-slate-900 rounded-full border border-slate-800 mb-4">
          <i data-lucide="frown" class="w-12 h-12 text-slate-600"></i>
        </div>
        <h3 class="text-xl font-bold text-slate-300">Game Tidak Ditemukan</h3>
        <p class="text-slate-500 mt-1">
          Coba sesuaikan kata kunci pencarian atau filter kategori Anda.
        </p>
        <button
          onclick="resetFilters()"
          class="mt-4 px-4 py-2 bg-pink-500 hover:bg-pink-600 active:scale-95 text-white font-semibold rounded-xl transition-all">
          Reset Filter
        </button>
      </div>
    </section>
  </main>

  <!-- Modal Detail Game -->
  <div
    id="detail-modal"
    class="hidden fixed inset-0 z-50 overflow-y-auto bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
    <div
      class="bg-slate-900 border border-slate-800 w-full max-w-2xl rounded-2xl overflow-hidden shadow-2xl relative transform scale-95 opacity-0 transition-all duration-300"
      id="modal-container">
      <!-- Modal Banner -->
      <div class="relative h-48 sm:h-64 bg-slate-950">
        <img
          id="modal-banner"
          src=""
          alt="Banner Game"
          class="w-full h-full object-cover opacity-60" />
        <div
          class="absolute inset-0 bg-gradient-to-t from-slate-900 via-slate-900/40 to-transparent"></div>
        <button
          onclick="closeModal()"
          class="absolute top-4 right-4 p-2 bg-slate-900/80 hover:bg-slate-800 text-slate-400 hover:text-white rounded-full border border-slate-800 transition-all">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <!-- Modal Body -->
      <div class="p-6 sm:p-8">
        <div class="flex flex-wrap items-center gap-2 mb-3">
          <span
            id="modal-category"
            class="px-2.5 py-1 text-xs font-semibold bg-blue-500/10 text-blue-400 border border-blue-500/20 rounded-full">Kategori</span>
          <span
            id="modal-year"
            class="px-2.5 py-1 text-xs font-semibold bg-slate-800 text-slate-300 rounded-full">Tahun</span>
        </div>

        <h2
          id="modal-title"
          class="text-2xl sm:text-3xl font-bold text-white mb-2">
          Nama Game
        </h2>
        <p
          id="modal-developer"
          class="text-sm text-pink-400 mb-6 font-semibold">
          Developer
        </p>

        <!-- Spesifikasi Kabinet & Detail -->
        <div
          class="grid grid-cols-2 sm:grid-cols-3 gap-4 mb-6 bg-slate-950/50 border border-slate-800 p-4 rounded-xl">
          <div>
            <span class="block text-xs text-slate-500">Tipe Kontrol</span>
            <span
              id="modal-control"
              class="text-sm font-semibold text-slate-300">-</span>
          </div>
          <div>
            <span class="block text-xs text-slate-500">Maks. Pemain</span>
            <span
              id="modal-players"
              class="text-sm font-semibold text-slate-300">-</span>
          </div>
          <div>
            <span class="block text-xs text-slate-500">Rating</span>
            <div class="flex items-center gap-1 mt-0.5">
              <i
                data-lucide="star"
                class="w-4 h-4 text-yellow-500 fill-yellow-500"></i>
              <span
                id="modal-rating"
                class="text-sm font-semibold text-slate-300">-</span>
            </div>
          </div>
        </div>

        <!-- Deskripsi -->
        <div class="mb-6">
          <h3
            class="text-sm font-bold uppercase tracking-wider text-slate-400 mb-2">
            Sinopsis / Deskripsi
          </h3>
          <p
            id="modal-description"
            class="text-slate-300 leading-relaxed text-sm sm:text-base">
            Deskripsi lengkap dari game arcade.
          </p>
        </div>

        <!-- Footer Modal -->
        <div class="flex justify-end gap-3 border-t border-slate-800 pt-6">
          <button
            id="modal-fav-btn"
            class="flex items-center gap-2 px-4 py-2 border border-slate-800 hover:border-pink-500 rounded-xl text-slate-300 transition-all duration-200">
            <i data-lucide="heart" class="w-5 h-5"></i>
            <span>Tambah Favorit</span>
          </button>
            <button
              id="modal-play-btn"
              type="button"
              class="px-5 py-2 bg-slate-800 hover:bg-slate-700 text-white rounded-xl transition-all"
            >
              Play
            </button>
        </div>
      </div>
    </div>
  </div>

  <!-- Footer -->
  <?php include "./partials/footer.php";?>

  <!-- JavaScript Data & Logic -->
  <script>
    // Kita passing data PHP array ke JavaScript sebagai format JSON agar JavaScript tetap sinkron dengan data server
    const initialGames = <?php echo json_encode($games); ?>;

// 1. Ambil data game lama yang tersimpan di localStorage (jika ada)
const savedGames = JSON.parse(localStorage.getItem("arcade_games")) || [];

// 2. Buat map untuk mencatat game mana saja yang ditandai favorit oleh user
const favMap = {};
savedGames.forEach(g => { favMap[g.id] = g.favorite; });

// 3. SELALU gunakan data terbaru dari PHP (termasuk urutan, judul, dan IMAGE baru),
//    tetapi tetap pertahankan status favorit dari localStorage.
let games = initialGames.map(ig => ({
  ...ig,
  favorite: favMap[ig.id] !== undefined ? favMap[ig.id] : ig.favorite
}));

// 4. Simpan kembali data yang telah disinkronkan ke localStorage
localStorage.setItem("arcade_games", JSON.stringify(games));


    // State filter aktif
    let filterFavOnly = false;

    // DOM Elements
    const gameGrid = document.getElementById("game-grid");
    const emptyState = document.getElementById("empty-state");
    const searchInput = document.getElementById("search-input");
    const categoryFilter = document.getElementById("category-filter");
    const sortFilter = document.getElementById("sort-filter");
    const favToggleBtn = document.getElementById("fav-toggle-btn");
    const statTotal = document.getElementById("stat-total");
    const statFav = document.getElementById("stat-fav");

    // Modal Elements
    const detailModal = document.getElementById("detail-modal");
    const modalContainer = document.getElementById("modal-container");
    const modalBanner = document.getElementById("modal-banner");
    const modalCategory = document.getElementById("modal-category");
    const modalYear = document.getElementById("modal-year");
    const modalTitle = document.getElementById("modal-title");
    const modalDeveloper = document.getElementById("modal-developer");
    const modalControl = document.getElementById("modal-control");
    const modalPlayers = document.getElementById("modal-players");
    const modalRating = document.getElementById("modal-rating");
    const modalDescription = document.getElementById("modal-description");
    const modalFavBtn = document.getElementById("modal-fav-btn");
    const modalPlayBtn = document.getElementById("modal-play-btn");

    // Fungsi Simpan State ke Local Storage
    function saveGamesState() {
      localStorage.setItem("arcade_games", JSON.stringify(games));
      updateStats();
    }

    // Update Statistik Header & Status Tombol Favorit
    function updateStats() {
      statTotal.textContent = games.length;
      const totalFav = games.filter((g) => g.favorite).length;
      statFav.textContent = totalFav;

      // Sync visual tombol favorit di card berdasarkan status `favorite` terkini
      games.forEach(game => {
        const btn = document.querySelector(`.fav-btn[data-game-id="${game.id}"]`);
        if (btn) {
          if (game.favorite) {
            btn.className = "fav-btn absolute top-3 right-3 p-2 rounded-lg backdrop-blur-md border transition-all duration-200 bg-pink-500/20 border-pink-500 text-pink-500";
            btn.innerHTML = `<i data-lucide="heart" class="w-4 h-4 fill-pink-500"></i>`;
          } else {
            btn.className = "fav-btn absolute top-3 right-3 p-2 rounded-lg backdrop-blur-md border transition-all duration-200 bg-slate-950/80 border-slate-800 text-slate-400 hover:text-pink-500 hover:border-pink-500/50";
            btn.innerHTML = `<i data-lucide="heart" class="w-4 h-4"></i>`;
          }
        }
      });
      lucide.createIcons();
    }

    // Render Kartu Game ke Grid secara dinamis (berdasarkan filter & pencarian)
    function renderGames() {
      const searchTerm = searchInput.value.toLowerCase().trim();
      const selectedCategory = categoryFilter.value;
      const selectedSort = sortFilter.value;

      // Ambil semua elemen card yang di-render oleh PHP
      const cards = Array.from(document.querySelectorAll(".game-card-item"));
      let visibleCount = 0;

      // Cari tahu game mana saja yang memenuhi kriteria
      const filteredGameIds = games.filter((game) => {
        const matchesSearch =
          game.title.toLowerCase().includes(searchTerm) ||
          game.developer.toLowerCase().includes(searchTerm);
        const matchesCategory =
          selectedCategory === "all" || game.category === selectedCategory;
        const matchesFav = !filterFavOnly || game.favorite;

        return matchesSearch && matchesCategory && matchesFav;
      }).map(g => g.id);

      // Filter visibilitas kartu di DOM
      cards.forEach(card => {
        const cardId = parseInt(card.getAttribute("data-id"));
        if (filteredGameIds.includes(cardId)) {
          card.classList.remove("hidden");
          visibleCount++;
        } else {
          card.classList.add("hidden");
        }
      });

      // Lakukan pengurutan elemen di dalam Grid berdasarkan pilihan user
      if (visibleCount > 0) {
        cards.sort((a, b) => {
          const idA = parseInt(a.getAttribute("data-id"));
          const idB = parseInt(b.getAttribute("data-id"));
          const gameA = games.find(g => g.id === idA);
          const gameB = games.find(g => g.id === idB);

          if (selectedSort === "newest") {
            return gameB.year - gameA.year;
          } else if (selectedSort === "oldest") {
            return gameA.year - gameB.year;
          } else if (selectedSort === "alphabetical") {
            return gameA.title.localeCompare(gameB.title);
          } else if (selectedSort === "rating") {
            return gameB.rating - gameA.rating;
          }
          return 0;
        });

        // Lampirkan kembali kartu yang sudah terurut ke kontainer grid
        cards.forEach(card => gameGrid.appendChild(card));
      }

      // Tampilkan atau sembunyikan grid/empty state
      if (visibleCount === 0) {
        gameGrid.classList.add("hidden");
        emptyState.classList.remove("hidden");
        emptyState.classList.add("flex");
      } else {
        gameGrid.classList.remove("hidden");
        emptyState.classList.add("hidden");
        emptyState.classList.remove("flex");
      }

      lucide.createIcons();
    }

    // Toggle Status Favorit
    function toggleFavorite(event, gameId) {
      // Mencegah trigger click modal pada kartu induk
      if (event) event.stopPropagation();

      games = games.map((game) => {
        if (game.id === gameId) {
          return {
            ...game,
            favorite: !game.favorite
          };
        }
        return game;
      });

      saveGamesState();
      renderGames();

      // Jika modal sedang terbuka, perbarui tombol di modal
      const currentModalId = detailModal.getAttribute("data-active-id");
      if (currentModalId && parseInt(currentModalId) === gameId) {
        updateModalFavBtn(gameId);
      }
    }

    // Update Tombol Favorit di dalam Modal
    function updateModalFavBtn(gameId) {
      const game = games.find((g) => g.id === gameId);
      if (!game) return;

      if (game.favorite) {
        modalFavBtn.className =
          "flex items-center gap-2 px-4 py-2 border border-pink-500 bg-pink-500/10 text-pink-500 rounded-xl transition-all duration-200";
        modalFavBtn.innerHTML = `<i data-lucide="heart" class="w-5 h-5 fill-pink-500"></i> <span>Hapus Favorit</span>`;
      } else {
        modalFavBtn.className =
          "flex items-center gap-2 px-4 py-2 border border-slate-800 hover:border-pink-500 rounded-xl text-slate-300 transition-all duration-200";
        modalFavBtn.innerHTML = `<i data-lucide="heart" class="w-5 h-5"></i> <span>Tambah Favorit</span>`;
      }
      modalFavBtn.onclick = (e) => toggleFavorite(e, gameId);
      lucide.createIcons();
    }

    // Buka Modal Detail Game
    // Buka Modal Detail Game
    function openModal(gameId) {
      const game = games.find((g) => g.id === gameId);
      if (!game) return;

      detailModal.setAttribute("data-active-id", gameId);

      // Set data ke komponen modal
      modalBanner.src = game.image;
      modalBanner.onerror = function() {
        this.src =
          "https://images.unsplash.com/photo-1538481199705-c710c4e965fc?auto=format&fit=crop&w=800&q=80";
      };
      modalCategory.textContent = game.category;
      modalYear.textContent = game.year;
      modalTitle.textContent = game.title;
      modalDeveloper.textContent = game.developer;
      modalControl.textContent = game.controls;
      modalPlayers.textContent = game.maxPlayers;
      modalRating.textContent = game.rating.toFixed(1);
      modalDescription.textContent = game.description;

      // --- UPDATED PLAY BUTTON HANDLER ---
      // Navigate to the game's play URL in the same tab when Play button is clicked
      modalPlayBtn.onclick = function() {
        if (game.play_url) {
          window.location.href = game.play_url;
        } else {
          // Fallback: construct URL based on game ID (e.g., "1.html")
          const fallbackUrl = `${game.id}.html`;
          console.warn('play_url missing, using fallback:', fallbackUrl);
          window.location.href = fallbackUrl;
        }
      };
      // -----------------------------------

      updateModalFavBtn(gameId);

      // Tampilkan Modal dengan animasi lembut
      detailModal.classList.remove("hidden");
      setTimeout(() => {
        modalContainer.classList.remove("scale-95", "opacity-0");
        modalContainer.classList.add("scale-100", "opacity-100");
      }, 50);

      // Cegah scroll pada body utama
      document.body.style.overflow = "hidden";
    }

    // Tutup Modal
    function closeModal() {
      modalContainer.classList.remove("scale-100", "opacity-100");
      modalContainer.classList.add("scale-95", "opacity-0");
      setTimeout(() => {
        detailModal.classList.add("hidden");
        detailModal.removeAttribute("data-active-id");
        document.body.style.overflow = "";
      }, 200);
    }

    // Reset Filter Pencarian
    function resetFilters() {
      searchInput.value = "";
      categoryFilter.value = "all";
      sortFilter.value = "newest";
      filterFavOnly = false;
      updateFavBtnStyle();
      renderGames();
    }

    // Update Tampilan Tombol Toggle Favorit
    function updateFavBtnStyle() {
      if (filterFavOnly) {
        favToggleBtn.classList.remove(
          "bg-slate-950",
          "border-slate-800",
          "text-slate-300",
        );
        favToggleBtn.classList.add(
          "bg-pink-500/20",
          "border-pink-500",
          "text-pink-500",
        );
      } else {
        favToggleBtn.classList.add(
          "bg-slate-950",
          "border-slate-800",
          "text-slate-300",
        );
        favToggleBtn.classList.remove(
          "bg-pink-500/20",
          "border-pink-500",
          "text-pink-500",
        );
      }
    }

    // Event Listeners
    searchInput.addEventListener("input", renderGames);
    categoryFilter.addEventListener("change", renderGames);
    sortFilter.addEventListener("change", renderGames);

    favToggleBtn.addEventListener("click", () => {
      filterFavOnly = !filterFavOnly;
      updateFavBtnStyle();
      renderGames();
    });

    // Tutup modal jika area luar modal diklik
    detailModal.addEventListener("click", (e) => {
      if (e.target === detailModal) {
        closeModal();
      }
    });

    // Dukungan Tombol ESC untuk tutup modal
    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape" && !detailModal.classList.contains("hidden")) {
        closeModal();
      }
    });

    // Memulai Render Pertama Kali
    window.onload = function() {
      updateStats();
      renderGames();
    };
  </script>
</body>

</html>