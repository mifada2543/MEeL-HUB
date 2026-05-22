<?php
// ── Ambil profile picture dari session (nav.php bisa di-include dari mana saja) ──
$_nav_pfp = null;
if (isset($_SESSION['user_id']) && isset($conn)) {
    $stmt_nav = $conn->prepare("SELECT profile_picture FROM users WHERE id = ? LIMIT 1");
    $stmt_nav->bind_param("i", $_SESSION['user_id']);
    $stmt_nav->execute();
    $_nav_user = $stmt_nav->get_result()->fetch_assoc();
    $_nav_pfp  = $_nav_user['profile_picture'] ?? null;
}

// ── Tentukan prefix path relatif (root vs subfolder) ──
// Jika halaman ada di subfolder (music/, video/, dll.) → "../profile/upload/"
// Jika di root → "profile/upload/"
$_nav_pfp_base = (str_contains($_SERVER['PHP_SELF'], '/music/') ||
    str_contains($_SERVER['PHP_SELF'], '/video/') ||
    str_contains($_SERVER['PHP_SELF'], '/books/') ||
    str_contains($_SERVER['PHP_SELF'], '/drive/'))
    ? '../profile/upload/' : 'profile/upload/';
$_nav_root     = (str_contains($_SERVER['PHP_SELF'], '/music/') ||
    str_contains($_SERVER['PHP_SELF'], '/video/') ||
    str_contains($_SERVER['PHP_SELF'], '/books/') ||
    str_contains($_SERVER['PHP_SELF'], '/drive/'))
    ? '../' : '';
?>

<?php if (isset($_SESSION['username'])): ?>

    <!-- ── AVATAR DROPDOWN (desktop) ── -->
    <div class="relative hidden sm:block" id="nav-dropdown-wrap">
        <button id="nav-avatar-btn"
            onclick="toggleNavDropdown()"
            class="flex items-center gap-2 p-1 rounded-xl hover:bg-white/[.05] transition-all group"
            title="Menu Akun">

            <!-- Avatar -->
            <div class="w-8 h-8 rounded-full overflow-hidden border border-white/10 flex-shrink-0 bg-gradient-to-br from-orange-500 to-red-600 flex items-center justify-center">
                <?php if (!empty($_nav_pfp)): ?>
                    <img src="<?= $_nav_pfp_base . htmlspecialchars($_nav_pfp) ?>"
                        class="w-full h-full object-cover"
                        onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                    <span class="hidden w-full h-full items-center justify-center text-white text-xs font-bold">
                        <?= strtoupper(substr($_SESSION['username'], 0, 1)) ?>
                    </span>
                <?php else: ?>
                    <span class="text-white text-xs font-bold">
                        <?= strtoupper(substr($_SESSION['username'], 0, 1)) ?>
                    </span>
                <?php endif; ?>
            </div>

            <i data-lucide="chevron-down" class="w-3 h-3 text-gray-600 transition-transform duration-200" id="nav-chevron"></i>
        </button>

        <!-- Dropdown panel -->
        <div id="nav-dropdown"
            class="hidden absolute right-0 top-full mt-2 w-52 bg-[#0f1319] border border-white/[.07] rounded-2xl shadow-2xl shadow-black/60 overflow-hidden z-[200]">

            <!-- User info header -->
            <div class="px-4 py-3 border-b border-white/[.05] flex items-center gap-3">
                <div class="w-9 h-9 rounded-full overflow-hidden border border-white/10 flex-shrink-0 bg-gradient-to-br from-orange-500 to-red-600 flex items-center justify-center">
                    <?php if (!empty($_nav_pfp)): ?>
                        <img src="<?= $_nav_pfp_base . htmlspecialchars($_nav_pfp) ?>"
                            class="w-full h-full object-cover"
                            onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                        <span class="hidden w-full h-full items-center justify-center text-white text-xs font-bold">
                            <?= strtoupper(substr($_SESSION['username'], 0, 1)) ?>
                        </span>
                    <?php else: ?>
                        <span class="text-white text-xs font-bold">
                            <?= strtoupper(substr($_SESSION['username'], 0, 1)) ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="min-w-0">
                    <div class="text-xs font-bold text-white truncate">@<?= htmlspecialchars($_SESSION['username']) ?></div>
                    <?php if (isset($_SESSION['role'])): ?>
                        <?php if ($_SESSION['role'] === 'admin'): ?>
                            <div class="flex items-center gap-1 mt-0.5">
                                <span class="relative flex h-1.5 w-1.5">
                                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                                    <span class="relative inline-flex rounded-full h-1.5 w-1.5 bg-red-500"></span>
                                </span>
                                <span class="text-[9px] text-red-500 font-black uppercase tracking-wider">Admin</span>
                            </div>
                        <?php elseif ($_SESSION['role'] === 'member'): ?>
                            <div class="flex items-center gap-1 mt-0.5">
                                <span class="h-1.5 w-1.5 rounded-full bg-green-500"></span>
                                <span class="text-[9px] text-green-500 font-medium uppercase tracking-tighter">Berlangganan</span>
                            </div>
                        <?php else: ?>
                            <div class="flex items-center gap-1 mt-0.5">
                                <span class="h-1.5 w-1.5 rounded-full bg-gray-600"></span>
                                <span class="text-[9px] text-gray-500 font-medium uppercase tracking-tighter">Member</span>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Menu items -->
            <div class="py-1.5">
                <a href="<?= $_nav_root ?>profile/?u=<?= urlencode($_SESSION['username']) ?>"
                    class="flex items-center gap-3 px-4 py-2.5 text-[11px] text-gray-400 hover:text-white hover:bg-white/[.04] transition-all no-underline">
                    <i data-lucide="user" class="w-3.5 h-3.5 flex-shrink-0"></i>
                    <span>Profil</span>
                </a>

                <a href="<?= $_nav_root ?>books/index.php"
                    class="flex items-center gap-3 px-4 py-2.5 text-[11px] text-gray-400 hover:text-green-400 hover:bg-white/[.04] transition-all no-underline">
                    <i data-lucide="book-open" class="w-3.5 h-3.5 flex-shrink-0"></i>
                    <span>Books</span>
                </a>

                <a href="<?= $_nav_root ?>introduction.php"
                    class="flex items-center gap-3 px-4 py-2.5 text-[11px] text-gray-400 hover:text-white hover:bg-white/[.04] transition-all no-underline">
                    <i data-lucide="compass" class="w-3.5 h-3.5 flex-shrink-0"></i>
                    <span>Cara Navigasi</span>
                </a>

                <?php if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['member', 'admin'])): ?>
                    <a href="<?= $_nav_root ?>drive/index.php"
                        class="flex items-center gap-3 px-4 py-2.5 text-[11px] text-gray-400 hover:text-blue-400 hover:bg-white/[.04] transition-all no-underline">
                        <i data-lucide="hard-drive" class="w-3.5 h-3.5 flex-shrink-0"></i>
                        <span>Drive</span>
                    </a>
                <?php endif; ?>

                <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                    <a href="<?= $_nav_root ?>upload_advanced.php"
                        class="flex items-center gap-3 px-4 py-2.5 text-[11px] text-gray-400 hover:text-blue-400 hover:bg-white/[.04] transition-all no-underline">
                        <i data-lucide="upload-cloud" class="w-3.5 h-3.5 flex-shrink-0"></i>
                        <span>Upload Media</span>
                    </a>
                    <a href="<?= $_nav_root ?>system_check.php"
                        class="flex items-center gap-3 px-4 py-2.5 text-[11px] text-gray-400 hover:text-red-400 hover:bg-white/[.04] transition-all no-underline">
                        <i data-lucide="settings" class="w-3.5 h-3.5 flex-shrink-0"></i>
                        <span>Admin Panel</span>
                    </a>
                <?php elseif (isset($_SESSION['role']) && $_SESSION['role'] === 'member'): ?>
                    <!-- Upload hanya untuk member juga (sesuai nav lama) -->
                    <a href="upload.php"
                        class="flex items-center gap-3 px-4 py-2.5 text-[11px] text-gray-400 hover:text-blue-400 hover:bg-white/[.04] transition-all no-underline">
                        <i data-lucide="upload-cloud" class="w-3.5 h-3.5 flex-shrink-0"></i>
                        <span>Upload</span>
                    </a>
                <?php endif; ?>
            </div>

            <div class="border-t border-white/[.05] py-1.5">
                <a href="<?= $_nav_root ?>auth/logout.php"
                    onclick="return confirm('Yakin mau logout?')"
                    class="flex items-center gap-3 px-4 py-2.5 text-[11px] text-gray-500 hover:text-red-400 hover:bg-red-500/[.06] transition-all no-underline">
                    <i data-lucide="log-out" class="w-3.5 h-3.5 flex-shrink-0"></i>
                    <span>Logout</span>
                </a>
            </div>
        </div>
    </div>

    <!-- ── HAMBURGER (mobile only) ── -->
    <button id="nav-hamburger"
        onclick="toggleNavDrawer()"
        class="sm:hidden flex items-center justify-center w-9 h-9 rounded-xl bg-white/[.04] border border-white/[.06] text-gray-500 hover:text-white transition-all"
        title="Menu">
        <i data-lucide="menu" class="w-4 h-4"></i>
    </button>

    <!-- ── MOBILE DRAWER ── -->
    <div id="nav-drawer-overlay"
        class="fixed inset-0 bg-black/60 backdrop-blur-sm z-[300] hidden sm:hidden"
        onclick="toggleNavDrawer()"></div>

    <div id="nav-drawer"
        class="fixed top-0 right-0 h-full w-72 bg-[#0a0d14] border-l border-white/[.06] z-[310] transform translate-x-full transition-transform duration-300 ease-out sm:hidden flex flex-col">

        <!-- Drawer header -->
        <div class="flex items-center justify-between px-5 py-4 border-b border-white/[.05]">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-full overflow-hidden border border-white/10 flex-shrink-0 bg-gradient-to-br from-orange-500 to-red-600 flex items-center justify-center">
                    <?php if (!empty($_nav_pfp)): ?>
                        <img src="<?= $_nav_pfp_base . htmlspecialchars($_nav_pfp) ?>"
                            class="w-full h-full object-cover"
                            onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                        <span class="hidden w-full h-full items-center justify-center text-white text-sm font-bold">
                            <?= strtoupper(substr($_SESSION['username'], 0, 1)) ?>
                        </span>
                    <?php else: ?>
                        <span class="text-white text-sm font-bold">
                            <?= strtoupper(substr($_SESSION['username'], 0, 1)) ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div>
                    <div class="text-sm font-bold text-white">@<?= htmlspecialchars($_SESSION['username']) ?></div>
                    <?php if (isset($_SESSION['role'])): ?>
                        <?php if ($_SESSION['role'] === 'admin'): ?>
                            <div class="flex items-center gap-1 mt-0.5">
                                <span class="relative flex h-1.5 w-1.5">
                                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                                    <span class="relative inline-flex rounded-full h-1.5 w-1.5 bg-red-500"></span>
                                </span>
                                <span class="text-[9px] text-red-500 font-black uppercase tracking-wider">Admin</span>
                            </div>
                        <?php elseif ($_SESSION['role'] === 'member'): ?>
                            <div class="flex items-center gap-1 mt-0.5">
                                <span class="h-1.5 w-1.5 rounded-full bg-green-500"></span>
                                <span class="text-[9px] text-green-500 font-medium uppercase">Berlangganan</span>
                            </div>
                        <?php else: ?>
                            <div class="flex items-center gap-1 mt-0.5">
                                <span class="h-1.5 w-1.5 rounded-full bg-gray-600"></span>
                                <span class="text-[9px] text-gray-500 uppercase">Member</span>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            <button onclick="toggleNavDrawer()" class="text-gray-600 hover:text-white p-1 transition-all">
                <i data-lucide="x" class="w-5 h-5"></i>
            </button>
        </div>

        <!-- Drawer menu items -->
        <nav class="flex-1 overflow-y-auto py-3">
            <a href="<?= $_nav_root ?>profile/?u=<?= urlencode($_SESSION['username']) ?>"
                class="flex items-center gap-4 px-5 py-3.5 text-sm text-gray-400 hover:text-white hover:bg-white/[.04] transition-all no-underline">
                <i data-lucide="user" class="w-4 h-4 flex-shrink-0"></i>
                <span>Profil Saya</span>
            </a>
            <a href="<?= $_nav_root ?>books/index.php"
                class="flex items-center gap-4 px-5 py-3.5 text-sm text-gray-400 hover:text-green-400 hover:bg-white/[.04] transition-all no-underline">
                <i data-lucide="book-open" class="w-4 h-4 flex-shrink-0"></i>
                <span>Books</span>
            </a>
            <a href="<?= $_nav_root ?>introduction.php"
                class="flex items-center gap-4 px-5 py-3.5 text-sm text-gray-400 hover:text-white hover:bg-white/[.04] transition-all no-underline">
                <i data-lucide="compass" class="w-4 h-4 flex-shrink-0"></i>
                <span>Cara Navigasi</span>
            </a>
            <?php if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['member', 'admin'])): ?>
                <a href="<?= $_nav_root ?>drive/index.php"
                    class="flex items-center gap-4 px-5 py-3.5 text-sm text-gray-400 hover:text-blue-400 hover:bg-white/[.04] transition-all no-underline">
                    <i data-lucide="hard-drive" class="w-4 h-4 flex-shrink-0"></i>
                    <span>Drive</span>
                </a>
            <?php endif; ?>
            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                <a href="<?= $_nav_root ?>upload_advanced.php"
                    class="flex items-center gap-4 px-5 py-3.5 text-sm text-gray-400 hover:text-blue-400 hover:bg-white/[.04] transition-all no-underline">
                    <i data-lucide="upload-cloud" class="w-4 h-4 flex-shrink-0"></i>
                    <span>Upload Media</span>
                </a>
                <a href="<?= $_nav_root ?>system_check.php"
                    class="flex items-center gap-4 px-5 py-3.5 text-sm text-gray-400 hover:text-red-400 hover:bg-white/[.04] transition-all no-underline">
                    <i data-lucide="settings" class="w-4 h-4 flex-shrink-0"></i>
                    <span>Admin Panel</span>
                </a>
            <?php elseif (isset($_SESSION['role']) && $_SESSION['role'] === 'member'): ?>
                <a href="upload.php"
                    class="flex items-center gap-4 px-5 py-3.5 text-sm text-gray-400 hover:text-blue-400 hover:bg-white/[.04] transition-all no-underline">
                    <i data-lucide="upload-cloud" class="w-4 h-4 flex-shrink-0"></i>
                    <span>Upload</span>
                </a>
            <?php endif; ?>

            <div class="mx-5 my-2 h-px bg-white/[.05]"></div>

            <a href="<?= $_nav_root ?>update.php"
                class="flex items-center gap-4 px-5 py-3.5 text-sm text-gray-500 hover:text-white hover:bg-white/[.04] transition-all no-underline">
                <i data-lucide="radio" class="w-4 h-4 flex-shrink-0"></i>
                <span>Changelog</span>
            </a>
        </nav>

        <!-- Logout -->
        <div class="border-t border-white/[.05] p-4">
            <a href="<?= $_nav_root ?>auth/logout.php"
                onclick="return confirm('Yakin mau logout?')"
                class="flex items-center justify-center gap-3 w-full py-3 rounded-xl bg-red-600/10 border border-red-600/20 text-sm text-red-400 hover:bg-red-600/20 transition-all no-underline font-bold">
                <i data-lucide="log-out" class="w-4 h-4"></i>
                Logout
            </a>
        </div>
    </div>

<?php else: ?>
    <!-- Belum login -->
    <a href="<?= $_nav_root ?>auth/login.php"
        title="Login"
        class="bg-blue-600 hover:bg-blue-500 text-white px-4 py-2 rounded-xl text-xs font-bold transition-all shadow-lg shadow-blue-900/40">
        LOGIN
    </a>
    <a href="<?= $_nav_root ?>introduction.php"
        class="hidden sm:flex text-gray-500 hover:text-white transition-all p-2 rounded-lg hover:bg-white/5"
        title="Cara Bernavigasi">
        <i data-lucide="compass" class="w-4 h-4"></i>
    </a>
<?php endif; ?>

<script>
    // ── Dropdown desktop ──
    function toggleNavDropdown() {
        const dd = document.getElementById('nav-dropdown');
        const ch = document.getElementById('nav-chevron');
        if (!dd) return;
        dd.classList.toggle('hidden');
        if (ch) ch.style.transform = dd.classList.contains('hidden') ? '' : 'rotate(180deg)';
    }
    // Tutup dropdown jika klik di luar
    document.addEventListener('click', function(e) {
        const wrap = document.getElementById('nav-dropdown-wrap');
        if (wrap && !wrap.contains(e.target)) {
            const dd = document.getElementById('nav-dropdown');
            const ch = document.getElementById('nav-chevron');
            if (dd) dd.classList.add('hidden');
            if (ch) ch.style.transform = '';
        }
    });

    // ── Drawer mobile ──
    function toggleNavDrawer() {
        const drawer = document.getElementById('nav-drawer');
        const overlay = document.getElementById('nav-drawer-overlay');
        if (!drawer) return;
        const open = drawer.style.transform === 'translateX(0px)' || drawer.classList.contains('open');
        if (open) {
            drawer.style.transform = '';
            overlay.classList.add('hidden');
            drawer.classList.remove('open');
            document.body.style.overflow = '';
        } else {
            drawer.style.transform = 'translateX(0)';
            overlay.classList.remove('hidden');
            drawer.classList.add('open');
            document.body.style.overflow = 'hidden';
        }
    }
</script>