    <div class="h-4 w-[1px] bg-gray-800"></div>
    <?php if (isset($_SESSION['username'])): ?>
        <a href="../books/index.php" class="flex items-center gap-1.5 text-gray-400 hover:text-green-500 transition-all" title="Buku MEeL">
            <i data-lucide="book" class="w-3.5 h-3.5"></i>
            Books
        </a>
        <div class="h-4 w-[1px] bg-gray-800"></div>
        <div class="flex items-center gap-3">
            <a href="upload.php" class="flex items-center gap-2 text-gray-400 hover:text-blue-500 transition-all mr-2" title="Upload">
                <i data-lucide="upload-cloud" class="w-4 h-4"></i>Upload
            </a>
            <div class="h-4 w-[1px] bg-gray-800"></div>
            <div class="flex flex-col items-end">
                <a href="../profile/?u=<?= urlencode($_SESSION['username']) ?>" class="group flex items-center gap-2" title="Lihat Profil">
                    <?= htmlspecialchars($_SESSION['username']) ?>
                </a>
                <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                    <a href="../system_check.php" class="flex items-center gap-1.5 mt-1 hover:opacity-80 transition-opacity group" title="Dashboard Admin">
                        <span class="relative flex h-2 w-2">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-2 w-2 bg-red-600"></span>
                        </span>
                        <span class="text-[9px] text-red-600 font-black uppercase tracking-[0.2em]">Admin</span>
                    </a>

                <?php elseif (isset($_SESSION['role']) && $_SESSION['role'] === 'member'): ?>
                    <a href="../drive/index.php" class="flex items-center gap-1.5 mt-1 hover:opacity-80 transition-opacity group" title="Akses cloud drive">
                        <span class="h-1.5 w-1.5 rounded-full bg-green-500"></span>
                        <span class="text-[9px] text-green-500 font-medium uppercase tracking-tighter">Berlangganan</span>
                    </a>

                <?php else: ?>
                    <div class="flex items-center gap-1 mt-1" title="Member">
                        <span class="h-1.5 w-1.5 rounded-full bg-gray-700"></span>
                        <span class="text-[9px] text-gray-500 font-medium uppercase tracking-tighter">Member</span>
                    </div>
                <?php endif; ?>
            </div>
            <a href="../auth/logout.php" title="Logout" class="bg-gray-800/50 p-2 rounded-xl hover:bg-red-600 group transition-all duration-300" onclick="return confirm('Yakin mau logout?')"><i data-lucide="log-out" class="w-4 h-4 text-gray-400 group-hover:text-white"></i></a>
        </div>
    <?php else: ?>
        <a href="../auth/login.php" title="Login" class="bg-blue-600 hover:bg-blue-500 text-white px-5 py-2 rounded-xl text-xs font-bold transition-all shadow-lg shadow-blue-900/40">LOGIN</a>
    <?php endif; ?>
    <a href="../introduction.php" class="text-gray-500 hover:text-white transition-all p-2 rounded-lg hover:bg-white/5 group" title="Cara Bernavigasi"><i data-lucide="compass" class="w-4.5 h-4.5 group-hover:rotate-12 transition-transform"></i></a>