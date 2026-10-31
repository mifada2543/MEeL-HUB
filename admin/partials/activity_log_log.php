<?php
if (!isset($conn) || !($conn instanceof \mysqli)) {
    http_response_code(403);
    exit;
}
/**
 * @var array $stats
 * @var int $total_rows
 * @var int $page
 *  @var int $total_pages
 * @var string $action_filter
 * @var array $all_actions
 * @var string $search_q
 * @var int $days
 * @var \mysqli_result|null $rows
 * @var string|null $clear_msg
 */
?>
<div class="max-w-7xl mx-auto px-6 md:px-10 xl:px-16 pb-8">

    <div class="flex items-center gap-5 mb-10">
        <div class="w-14 h-14 rounded-2xl bg-blue-500/15 border border-blue-500/25 flex items-center justify-center shrink-0">
            <i data-lucide="activity" class="w-6 h-6 text-blue-500"></i>
        </div>
        <div>
            <h1 class="text-3xl md:text-4xl font-extrabold text-white leading-tight tracking-tight">Log Aktivitas</h1>
            <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400 mt-1.5">Jejak Audit</p>
        </div>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 md:gap-5 mb-8">
        <div class="glass p-5 rounded-2xl border-l-4 border-blue-500">
            <p class="text-[9px] font-bold text-gray-400 uppercase mb-1.5">7 Hari Terakhir</p>
            <span class="text-2xl font-bold text-white"><?= number_format($stats['total']) ?></span>
            <span class="text-[10px] text-gray-400 ml-1.5">events</span>
        </div>
        <div class="glass p-5 rounded-2xl border-l-4 border-green-500">
            <p class="text-[9px] font-bold text-gray-400 uppercase mb-1.5">Pengguna Aktif</p>
            <span class="text-2xl font-bold text-white"><?= number_format($stats['unique_users']) ?></span>
            <span class="text-[10px] text-gray-400 ml-1.5">users</span>
        </div>
        <div class="glass p-5 rounded-2xl border-l-4 border-purple-500">
            <p class="text-[9px] font-bold text-gray-400 uppercase mb-1.5">Total Log</p>
            <span class="text-2xl font-bold text-white"><?= number_format($total_rows) ?></span>
            <span class="text-[10px] text-gray-400 ml-1.5">entries</span>
        </div>
        <div class="glass p-5 rounded-2xl border-l-4 border-orange-500">
            <p class="text-[9px] font-bold text-gray-400 uppercase mb-1.5">Halaman</p>
            <span class="text-2xl font-bold text-white"><?= $page ?>/<?= $total_pages ?></span>
        </div>
    </div>

    <?php if ($clear_msg): ?>
        <div class="mb-8 p-5 rounded-2xl text-sm flex items-center gap-3 bg-green-500/10 text-green-400 border border-green-500/20">
            <i data-lucide="check-circle" class="w-5 h-5 shrink-0"></i>
            <?= htmlspecialchars($clear_msg) ?>
        </div>
    <?php endif; ?>

    <div class="glass p-6 md:p-8 rounded-2xl mb-8 filter-section relative z-40 overflow-visible" id="filter-section">
        <div class="flex flex-col lg:flex-row items-start justify-between gap-6 w-full min-w-0">
            <div class="w-full lg:w-1/4 min-w-0 relative z-30">
                <label class="text-[9px] font-bold text-gray-400 uppercase tracking-widest mb-2.5 block">
                    <i data-lucide="activity" class="w-3 h-3 inline mr-1.5"></i> Action
                </label>
                <div class="relative" id="action-dropdown-container">
                    <input type="hidden" name="action" id="action-input" value="<?= htmlspecialchars($action_filter) ?>">
                    <button type="button"
                        onclick="toggleActionDropdown()"
                        class="action-dropdown-btn w-full rounded-xl pl-4 pr-11 py-3 text-xs text-gray-300 flex items-center justify-between relative z-20 h-[42px]"
                        id="action-dropdown-trigger">
                        <span class="truncate" id="action-dropdown-label">
                            <?= $action_filter ? htmlspecialchars($action_filter) : 'Semua Aksi' ?>
                        </span>
                        <i data-lucide="chevron-down" class="w-3.5 h-3.5 text-gray-400 shrink-0"></i>
                    </button>

                    <div id="action-dropdown-panel"
                        class="action-dropdown-panel hidden absolute left-0 right-0 mt-1.5 rounded-xl z-50 py-1 shadow-2xl bg-[#131720]">
                        <button type="button"
                            onclick="selectAction('')"
                            data-value=""
                            class="action-dropdown-option w-full text-left px-4 py-3 text-xs text-gray-300 <?= empty($action_filter) ? 'active' : '' ?>">
                            <span class="flex items-center gap-2.5">
                                <i data-lucide="circle" class="w-2.5 h-2.5 text-gray-600"></i>
                                Semua Aksi
                            </span>
                        </button>
                        <?php foreach ($all_actions as $act): ?>
                            <button type="button"
                                onclick="selectAction('<?= htmlspecialchars($act, ENT_QUOTES) ?>')"
                                data-value="<?= htmlspecialchars($act, ENT_QUOTES) ?>"
                                class="action-dropdown-option w-full text-left px-4 py-3 text-xs text-gray-300 <?= $action_filter === $act ? 'active' : '' ?>">
                                <span class="flex items-center gap-2.5">
                                    <i data-lucide="circle" class="w-2.5 h-2.5 text-gray-600"></i>
                                    <?= htmlspecialchars($act) ?>
                                </span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="w-full lg:w-1/4 min-w-0 relative z-10">
                <label class="text-[9px] font-bold text-gray-400 uppercase tracking-widest mb-2.5 block">
                    <i data-lucide="search" class="w-3 h-3 inline mr-1.5"></i> Cari Nama Pengguna / IP
                </label>
                <div class="relative">
                    <input type="text" name="q" id="search-input" value="<?= htmlspecialchars($search_q) ?>"
                        placeholder="Cari nama pengguna atau IP..."
                        class="w-full bg-[#131720] border border-white/10 rounded-xl pl-4 pr-4 text-xs text-gray-300 outline-none focus:border-blue-500 transition-all placeholder:text-gray-600 h-[42px]">
                </div>
            </div>

            <div class="w-full lg:flex-1 min-w-0 relative z-10">
                <label class="text-[9px] font-bold text-gray-400 uppercase tracking-widest mb-2.5 block">
                    <i data-lucide="calendar" class="w-3 h-3 inline mr-1.5"></i> Rentang
                </label>
                <input type="hidden" name="days" id="days-input" value="<?= $days ?>">
                <div class="flex flex-wrap gap-1.5 items-center min-h-[42px] max-w-full">
                    <?php foreach ([1 => '24 Jam', 3 => '3 Hari', 7 => '7 Hari', 14 => '14 Hari', 30 => '30 Hari', 90 => '90 Hari', 365 => '1 Tahun'] as $d => $label): ?>
                        <button type="button"
                            onclick="selectDays(<?= $d ?>)"
                            class="pill-btn <?= $days === $d ? 'active-blue' : '' ?>"
                            data-days="<?= $d ?>">
                            <?= $label ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>

        </div>

        <div class="flex items-center gap-4 mt-6 pt-5 border-t border-white/[.04]">
            <button type="button" onclick="submitFilters()"
                class="bg-blue-600 hover:bg-blue-500 text-white text-[10px] font-bold px-6 py-3 rounded-xl transition-all uppercase tracking-wider inline-flex items-center gap-2">
                <i data-lucide="filter" class="w-3.5 h-3.5"></i>
                Terapkan
            </button>
            <a href="activity-log"
                class="text-[10px] text-gray-400 hover:text-white px-4 py-3 transition-all uppercase tracking-wider inline-flex items-center gap-2 rounded-xl hover:bg-white/[.03]">
                <i data-lucide="rotate-ccw" class="w-3.5 h-3.5"></i>
                Reset
            </a>
        </div>
    </div>

    <script src="../assets/js/admin/activity_log.js?v=<?= filemtime(__DIR__ . '/../../assets/js/admin/activity_log.js') ?>"></script>

    <div class="glass rounded-2xl overflow-hidden relative z-0">
        <div class="scroll-table" style="max-height:70vh;">
            <table class="w-full text-left text-[11px]">
                <thead class="text-gray-400 uppercase text-[9px] font-black tracking-widest">
                    <tr>
                        <th class="py-3 px-4 w-14">#</th>
                        <th class="py-3 px-4">Pengguna</th>
                        <th class="py-3 px-4">Aksi</th>
                        <th class="py-3 px-4">Media</th>
                        <th class="py-3 px-4 text-center">ID</th>
                        <th class="py-3 px-4 hidden md:table-cell">Alamat IP</th>
                        <th class="py-3 px-4 text-right">Waktu</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800">
                    <?php if ($rows && $rows->num_rows > 0): ?>
                        <?php while ($row = $rows->fetch_assoc()):
                            $action = $row['action'];
                            if (str_contains($action, 'login') || str_contains($action, 'logout')) {
                                $ac_color = 'text-blue-400 bg-blue-500/10 border-blue-500/20';
                                $ac_icon = $action === 'login' ? 'log-in' : 'log-out';
                            } elseif (str_contains($action, 'upload')) {
                                $ac_color = 'text-green-400 bg-green-500/10 border-green-500/20';
                                $ac_icon = 'upload-cloud';
                            } elseif (str_contains($action, 'ban') || str_contains($action, 'unban')) {
                                $ac_color = 'text-red-400 bg-red-500/10 border-red-500/20';
                                $ac_icon = 'shield-alert';
                            } elseif (str_contains($action, 'delete') || str_contains($action, 'reject') || str_contains($action, 'kick')) {
                                $ac_color = 'text-red-400 bg-red-500/10 border-red-500/20';
                                $ac_icon = 'trash-2';
                            } elseif (str_contains($action, 'approve')) {
                                $ac_color = 'text-green-400 bg-green-500/10 border-green-500/20';
                                $ac_icon = 'user-check';
                            } else {
                                $ac_color = 'text-gray-400 bg-white/5 border-white/10';
                                $ac_icon = 'circle';
                            }
                        ?>
                            <tr class="hover:bg-white/[0.02] transition-colors">
                                <td class="py-3 px-4 font-mono text-gray-600"><?= (int)$row['id'] ?></td>
                                <td class="py-3 px-4">
                                    <span class="font-bold text-white">
                                        <?= htmlspecialchars($row['username'] ?? '—') ?>
                                    </span>
                                    <?php if ($row['user_id'] === null): ?>
                                        <span class="text-[8px] text-gray-600 ml-1">(guest)</span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3 px-4">
                                    <span class="action-badge <?= $ac_color ?>">
                                        <i data-lucide="<?= $ac_icon ?>" class="w-3 h-3"></i>
                                        <?= htmlspecialchars($action) ?>
                                    </span>
                                </td>
                                <td class="py-3 px-4 text-gray-400">
                                    <?= !empty($row['media_type']) ? htmlspecialchars($row['media_type']) : '<span class="text-gray-600">—</span>' ?>
                                </td>
                                <td class="py-3 px-4 text-center font-mono text-gray-400">
                                    <?= !empty($row['media_id']) ? (int)$row['media_id'] : '—' ?>
                                </td>
                                <td class="py-3 px-4 hidden md:table-cell font-mono text-gray-400 text-[10px]">
                                    <?= htmlspecialchars($row['ip_address'] ?? '—') ?>
                                </td>
                                <td class="py-3 px-4 text-right text-gray-400 text-[10px] whitespace-nowrap">
                                    <?= date('d/m/Y H:i', strtotime($row['created_at'])) ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="py-12 text-center text-gray-400 text-xs italic">
                                <i data-lucide="inbox" class="w-8 h-8 mx-auto mb-3 opacity-30"></i>
                                <p>Belum ada data log untuk filter ini.</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($total_pages > 1): ?>
        <div class="flex items-center justify-center gap-2 mt-6">
            <?php if ($page > 1): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>"
                    class="px-3 py-1.5 rounded-lg bg-white/5 border border-white/10 text-[11px] text-gray-400 hover:text-white hover:bg-white/10 transition-all">
                    ‹ Prev
                </a>
            <?php endif; ?>
            <?php
            $start = max(1, $page - 2);
            $end = min($total_pages, $page + 2);
            for ($i = $start; $i <= $end; $i++):
            ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"
                    class="px-3 py-1.5 rounded-lg text-[11px] font-bold transition-all <?= $i === $page ? 'bg-blue-600 text-white' : 'bg-white/5 border border-white/10 text-gray-400 hover:text-white hover:bg-white/10' ?>">
                    <?= $i ?>
                </a>
            <?php endfor; ?>
            <?php if ($page < $total_pages): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>"
                    class="px-3 py-1.5 rounded-lg bg-white/5 border border-white/10 text-[11px] text-gray-400 hover:text-white hover:bg-white/10 transition-all">
                    Next ›
                </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="glass p-6 rounded-2xl mt-8 border border-red-500/20">
        <div class="flex items-center gap-3 mb-4">
            <div class="p-2 rounded-xl bg-red-500/10 border border-red-500/20">
                <i data-lucide="trash-2" class="w-4 h-4 text-red-400"></i>
            </div>
            <div>
                <h3 class="text-xs font-bold text-gray-300">Log Pemeliharaan</h3>
                <p class="text-[9px] text-gray-400">Hapus log lama secara permanen untuk menghemat ruang database.</p>
            </div>
        </div>
        <form method="POST" class="flex items-center gap-3 flex-wrap" onsubmit="return meelConfirmForm(event, { title:'Hapus Log', text:'Hapus permanen semua log yang lebih lama dari periode yang dipilih?', confirmButtonText:'HAPUS' })" id="clear-logs-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="clear_days" id="clear-days-input" value="30">
            <input type="hidden" name="clear_older_than" value="1">
            <div class="flex flex-wrap items-center gap-1.5">
                <?php foreach ([7 => '7 Hari', 14 => '14 Hari', 30 => '30 Hari', 90 => '90 Hari', 365 => '1 Tahun'] as $d => $label): ?>
                    <button type="button"
                        onclick="selectClearDays(<?= $d ?>)"
                        class="pill-btn <?= $d === 30 ? 'active-red' : '' ?>"
                        data-clear-days="<?= $d ?>">
                        <?= $label ?>
                    </button>
                <?php endforeach; ?>
            </div>
            <button type="submit"
                class="bg-red-600/10 text-red-400 border border-red-500/20 hover:bg-red-600 hover:text-white text-[10px] font-bold px-5 py-2.5 rounded-xl transition-all uppercase tracking-wider inline-flex items-center gap-1.5">
                <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                Hapus Log
            </button>
        </form>
    </div>
    <br>

    <div class="glass p-6 rounded-2xl mt-5 border border-red-500/30">
        <div class="flex items-center gap-3 mb-4">
            <div class="p-2 rounded-xl bg-red-600/15 border border-red-500/30">
                <i data-lucide="alert-triangle" class="w-4 h-4 text-red-400"></i>
            </div>
            <div>
                <h3 class="text-xs font-bold text-gray-300">Hapus Semua Log</h3>
                <p class="text-[9px] text-gray-400">Hapus <strong class="text-red-400">seluruh</strong> log aktivitas dan reset auto-increment ke 1. Tindakan ini <strong class="text-red-400">tidak dapat dibatalkan</strong>.</p>
            </div>
        </div>

        <div class="flex items-center gap-3 flex-wrap mb-4 p-3 rounded-xl bg-amber-500/10 border border-amber-500/20">
            <i data-lucide="info" class="w-4 h-4 text-amber-400 shrink-0"></i>
            <p class="text-[10px] text-amber-300 flex-1">
                Backup dulu sebelum menghapus? Semua log akan hilang permanen.
            </p>
            <div class="flex items-center gap-1.5 shrink-0">
                <button type="button" onclick="previewExport('csv')"
                    class="text-[10px] font-bold text-emerald-400 hover:text-emerald-300 px-2 py-2 rounded-xl border border-amber-500/30 hover:bg-emerald-500/10 hover:border-emerald-500/30 transition-all uppercase tracking-wider inline-flex items-center gap-1"
                    title="Pratinjau CSV">
                    <i data-lucide="eye" class="w-3 h-3"></i>
                </button>
                <span class="text-amber-500/20">|</span>
                <button type="button" onclick="previewExport('json')"
                    class="text-[10px] font-bold text-sky-400 hover:text-sky-300 px-2 py-2 rounded-xl border border-amber-500/30 hover:bg-sky-500/10 hover:border-sky-500/30 transition-all uppercase tracking-wider inline-flex items-center gap-1"
                    title="Pratinjau JSON">
                    <i data-lucide="eye" class="w-3 h-3"></i>
                </button>
                <span class="text-amber-500/20">|</span>
                <button type="button" onclick="previewExport('xls')"
                    class="text-[10px] font-bold text-violet-400 hover:text-violet-300 px-2 py-2 rounded-xl border border-amber-500/30 hover:bg-violet-500/10 hover:border-violet-500/30 transition-all uppercase tracking-wider inline-flex items-center gap-1"
                    title="Pratinjau XLS">
                    <i data-lucide="eye" class="w-3 h-3"></i>
                </button>
            </div>
        </div>
                <!-- reference build: c8H0nh2 [ads94koojvsd] -->
        <form method="POST" onsubmit="return meelConfirmForm(event, { title:'⚠️ Hapus Semua Log', text:'Apakah Anda yakin ingin menghapus SELURUH log aktivitas?\n\nAuto-increment ID akan di-reset ke 1. Tindakan ini tidak dapat dibatalkan!', icon:'warning', confirmButtonText:'HAPUS SEMUA', confirmButtonColor:'#dc2626' })" id="clear-all-logs-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="clear_all_logs" value="1">
            <button type="submit"
                class="bg-red-600/20 text-red-400 border border-red-500/30 hover:bg-red-600 hover:text-white text-[10px] font-bold px-6 py-3 rounded-xl transition-all uppercase tracking-wider inline-flex items-center gap-2">
                <i data-lucide="trash-2" class="w-4 h-4"></i>
                Hapus Semua & Reset ID
            </button>
        </form>
    </div>

</div>