<?php
if (!isset($conn) || !($conn instanceof \mysqli)) {
    http_response_code(403);
    exit;
}
?>
        <div class="max-w-7xl mx-auto px-6 md:px-10 xl:px-16 pb-8">
            <div class="flex items-center gap-5 mb-8">
                <div class="w-14 h-14 rounded-2xl bg-green-500/15 border border-green-500/25 flex items-center justify-center shrink-0">
                    <i data-lucide="upload-cloud" class="w-6 h-6 text-green-500"></i>
                </div>
                <div>
                    <h1 class="text-3xl md:text-4xl font-extrabold text-white leading-tight tracking-tight">Antrean Unggah</h1>
                    <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400 mt-1.5">Riwayat Unggah &amp; Unduh</p>
                </div>
            </div>
            <script src="../assets/js/admin/activity_log.js?v=<?= filemtime(__DIR__ . '/../../assets/js/admin/activity_log.js') ?>"></script>
            <?php if (!empty($uq_clear_msg)): ?>
                <div class="mb-6 p-5 rounded-2xl text-sm flex items-center gap-3 bg-green-500/10 text-green-400 border border-green-500/20">
                    <i data-lucide="check-circle" class="w-5 h-5 shrink-0"></i>
                    <?= htmlspecialchars($uq_clear_msg) ?>
                </div>
            <?php endif; ?>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 md:gap-5 mb-8">
                <div class="glass p-5 rounded-2xl border-l-4 border-blue-500">
                    <p class="text-[9px] font-bold text-gray-400 uppercase mb-1.5">Total</p>
                    <span class="text-2xl font-bold text-white"><?= number_format($uq_stats['total']) ?></span>
                    <span class="text-[10px] text-gray-400 ml-1.5">jobs</span>
                </div>
                <div class="glass p-5 rounded-2xl border-l-4 border-green-500">
                    <p class="text-[9px] font-bold text-gray-400 uppercase mb-1.5">Selesai</p>
                    <span class="text-2xl font-bold text-white"><?= number_format($uq_stats['completed_count']) ?></span>
                    <span class="text-[10px] text-gray-400 ml-1.5">jobs</span>
                </div>
                <div class="glass p-5 rounded-2xl border-l-4 border-red-500">
                    <p class="text-[9px] font-bold text-gray-400 uppercase mb-1.5">Gagal</p>
                    <span class="text-2xl font-bold text-white"><?= number_format($uq_stats['failed_count']) ?></span>
                    <span class="text-[10px] text-gray-400 ml-1.5">jobs</span>
                </div>
                <div class="glass p-5 rounded-2xl border-l-4 border-yellow-500">
                    <p class="text-[9px] font-bold text-gray-400 uppercase mb-1.5">Diproses</p>
                    <span class="text-2xl font-bold text-white"><?= number_format($uq_stats['processing_count']) ?></span>
                    <span class="text-[10px] text-gray-400 ml-1.5">jobs</span>
                </div>
            </div>
            <div class="glass p-6 md:p-8 rounded-2xl mb-8 filter-section relative z-40 overflow-visible">
                <div class="flex flex-col lg:flex-row items-start justify-between gap-6 w-full min-w-0">
                    <div class="w-full lg:w-1/4 min-w-0 relative z-30">
                        <label class="text-[9px] font-bold text-gray-400 uppercase tracking-widest mb-2.5 block">
                            <i data-lucide="filter" class="w-3 h-3 inline mr-1.5"></i> Status
                        </label>
                        <div class="relative" id="uq-status-dropdown-container">
                            <input type="hidden" name="status" id="uq-status-input" value="<?= htmlspecialchars($uq_status) ?>">
                            <button type="button"
                                onclick="toggleUqStatusDropdown()"
                                class="action-dropdown-btn w-full rounded-xl pl-4 pr-11 py-3 text-xs text-gray-300 flex items-center justify-between relative z-20 h-[42px]"
                                id="uq-status-dropdown-trigger">
                                <span class="truncate" id="uq-status-dropdown-label">
                                    <?php
                                    $uq_status_labels = ['' => 'Semua Status', 'processing' => 'Diproses', 'completed' => 'Selesai', 'failed' => 'Gagal'];
                                    echo htmlspecialchars($uq_status_labels[$uq_status] ?? 'Semua Status');
                                    ?>
                                </span>
                                <i data-lucide="chevron-down" class="w-3.5 h-3.5 text-gray-400 shrink-0"></i>
                            </button>
                            <div id="uq-status-dropdown-panel"
                                class="action-dropdown-panel hidden absolute left-0 right-0 mt-1.5 rounded-xl z-50 py-1 shadow-2xl bg-[#131720]">
                                <?php foreach ($uq_status_labels as $val => $label): ?>
                                    <button type="button"
                                        onclick="selectUqStatus('<?= htmlspecialchars($val) ?>')"
                                        data-value="<?= htmlspecialchars($val) ?>"
                                        class="action-dropdown-option w-full text-left px-4 py-3 text-xs text-gray-300 <?= $uq_status === $val ? 'active' : '' ?>">
                                        <span class="flex items-center gap-2.5">
                                            <i data-lucide="circle" class="w-2.5 h-2.5 text-gray-600"></i>
                                            <?= htmlspecialchars($label) ?>
                                        </span>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <div class="w-full lg:w-1/4 min-w-0 relative z-10">
                        <label class="text-[9px] font-bold text-gray-400 uppercase tracking-widest mb-2.5 block">
                            <i data-lucide="search" class="w-3 h-3 inline mr-1.5"></i> Cari Nama Pengguna / URL
                        </label>
                        <div class="relative">
                            <input type="text" name="q" id="uq-search-input" value="<?= htmlspecialchars($uq_search) ?>"
                                placeholder="Cari nama pengguna atau URL..."
                                class="w-full bg-[#131720] border border-white/10 rounded-xl pl-4 pr-4 text-xs text-gray-300 outline-none focus:border-green-500 transition-all placeholder:text-gray-600 h-[42px]">
                        </div>
                    </div>

                    <div class="w-full lg:flex-1 min-w-0 relative z-10">
                        <label class="text-[9px] font-bold text-gray-400 uppercase tracking-widest mb-2.5 block">
                            <i data-lucide="calendar" class="w-3 h-3 inline mr-1.5"></i> Rentang
                        </label>
                        <input type="hidden" name="days" id="uq-days-input" value="<?= $uq_days ?>">
                        <div class="flex flex-wrap gap-1.5 items-center min-h-[42px] max-w-full">
                            <?php foreach ([1 => '24 Jam', 3 => '3 Hari', 7 => '7 Hari', 14 => '14 Hari', 30 => '30 Hari', 90 => '90 Hari', 365 => '1 Tahun'] as $d => $label): ?>
                                <button type="button"
                                    onclick="selectUqDays(<?= $d ?>)"
                                    class="pill-btn <?= $uq_days === $d ? 'active-green' : '' ?>"
                                    data-days="<?= $d ?>">
                                    <?= $label ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-4 mt-6 pt-5 border-t border-white/[.04]">
                    <button type="button" onclick="submitUqFilters()"
                        class="bg-green-600 hover:bg-green-500 text-white text-[10px] font-bold px-6 py-3 rounded-xl transition-all uppercase tracking-wider inline-flex items-center gap-2">
                        <i data-lucide="filter" class="w-3.5 h-3.5"></i>
                        Terapkan
                    </button>
                    <a href="activity-log?tab=uploads"
                        class="text-[10px] text-gray-400 hover:text-white px-4 py-3 transition-all uppercase tracking-wider inline-flex items-center gap-2 rounded-xl hover:bg-white/[.03]">
                        <i data-lucide="rotate-ccw" class="w-3.5 h-3.5"></i>
                        Reset
                    </a>
                </div>
            </div>

            <div class="glass rounded-2xl overflow-hidden relative z-0">
                <div class="scroll-table" style="max-height:70vh;">
                    <table class="w-full text-left text-[11px]">
                        <thead class="text-gray-400 uppercase text-[9px] font-black tracking-widest">
                            <tr>
                                <th class="py-3 px-4 w-14">#</th>
                                <th class="py-3 px-4">Pengguna</th>
                                <th class="py-3 px-4">URL</th>
                                <th class="py-3 px-4 text-center">Tipe</th>
                                <th class="py-3 px-4 text-center">Status</th>
                                <th class="py-3 px-4 text-right">Waktu</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-800">
                            <?php if ($uq_rows && $uq_rows->num_rows > 0): ?>
                                <?php while ($row = $uq_rows->fetch_assoc()):
                                    $st = $row['status'];
                                    if ($st === 'completed') {
                                        $st_color = 'text-green-400 bg-green-500/10 border-green-500/20';
                                        $st_icon = 'check-circle';
                                    } elseif ($st === 'failed') {
                                        $st_color = 'text-red-400 bg-red-500/10 border-red-500/20';
                                        $st_icon = 'x-circle';
                                    } else {
                                        $st_color = 'text-yellow-400 bg-yellow-500/10 border-yellow-500/20';
                                        $st_icon = 'loader';
                                    }
                                    $type_color = $row['media_type'] === 'video'
                                        ? 'text-red-400 bg-red-500/10 border-red-500/20'
                                        : 'text-orange-400 bg-orange-500/10 border-orange-500/20';
                                    $truncated_url = mb_strimwidth($row['url'] ?? '', 0, 60, '...');
                                ?>
                                    <tr class="hover:bg-white/[0.02] transition-colors">
                                        <td class="py-3 px-4 font-mono text-gray-600"><?= (int)$row['id'] ?></td>
                                        <td class="py-3 px-4">
                                            <span class="font-bold text-white">
                                                <?= htmlspecialchars($row['username'] ?? '—') ?>
                                            </span>
                                        </td>
                                        <td class="py-3 px-4 text-gray-400 text-[10px] max-w-[300px] truncate" title="<?= htmlspecialchars($row['url'] ?? '') ?>">
                                            <?= htmlspecialchars($truncated_url) ?>
                                        </td>
                                        <td class="py-3 px-4 text-center">
                                            <span class="action-badge <?= $type_color ?>">
                                                <?= htmlspecialchars(strtoupper($row['media_type'])) ?>
                                            </span>
                                        </td>
                                        <td class="py-3 px-4 text-center">
                                            <span class="action-badge <?= $st_color ?>">
                                                <i data-lucide="<?= $st_icon ?>" class="w-3 h-3"></i>
                                                <?= htmlspecialchars(ucfirst($st)) ?>
                                            </span>
                                        </td>
                                        <td class="py-3 px-4 text-right text-gray-400 text-[10px] whitespace-nowrap">
                                            <?= date('d/m/Y H:i', strtotime($row['created_at'])) ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="py-12 text-center text-gray-400 text-xs italic">
                                        <i data-lucide="inbox" class="w-8 h-8 mx-auto mb-3 opacity-30"></i>
                                        <p>Belum ada data antrean unggah untuk filter ini.</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php if ($uq_total_pages > 1): ?>
                <div class="flex items-center justify-center gap-2 mt-6">
                    <?php if ($uq_page > 1): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['tab' => 'uploads', 'page' => $uq_page - 1])) ?>"
                            class="px-3 py-1.5 rounded-lg bg-white/5 border border-white/10 text-[11px] text-gray-400 hover:text-white hover:bg-white/10 transition-all">
                            ‹ Prev
                        </a>
                    <?php endif; ?>
                    <?php
                    $uq_start = max(1, $uq_page - 2);
                    $uq_end = min($uq_total_pages, $uq_page + 2);
                    for ($i = $uq_start; $i <= $uq_end; $i++):
                    ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['tab' => 'uploads', 'page' => $i])) ?>"
                            class="px-3 py-1.5 rounded-lg text-[11px] font-bold transition-all <?= $i === $uq_page ? 'bg-green-600 text-white' : 'bg-white/5 border border-white/10 text-gray-400 hover:text-white hover:bg-white/10' ?>">
                            <?= $i ?>
                        </a>
                    <?php endfor; ?>
                    <?php if ($uq_page < $uq_total_pages): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['tab' => 'uploads', 'page' => $uq_page + 1])) ?>"
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
                        <h3 class="text-xs font-bold text-gray-300">Antrean Unggah Pemeliharaan</h3>
                        <p class="text-[9px] text-gray-400">Hapus record upload queue lama secara permanen untuk menghemat ruang database.</p>
                    </div>
                </div>

                <div class="flex items-center gap-3 flex-wrap mb-4 p-3 rounded-xl bg-amber-500/10 border border-amber-500/20">
                    <i data-lucide="info" class="w-4 h-4 text-amber-400 shrink-0"></i>
                    <p class="text-[10px] text-amber-300 flex-1">
                        Backup dulu sebelum menghapus? Semua record akan hilang permanen.
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

                <form method="POST" class="flex items-center gap-3 flex-wrap" onsubmit="return meelConfirmForm(event, { title:'Hapus Antrean Unggah', text:'Hapus permanen semua record upload queue yang lebih lama dari periode yang dipilih?', confirmButtonText:'HAPUS' })">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="clear_uq_days" id="uq-clear-days-input" value="30">
                    <input type="hidden" name="clear_uq_older_than" value="1">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <?php foreach ([7 => '7 Hari', 14 => '14 Hari', 30 => '30 Hari', 90 => '90 Hari', 365 => '1 Tahun'] as $d => $label): ?>
                            <button type="button"
                                onclick="selectUqClearDays(<?= $d ?>)"
                                class="pill-btn <?= $d === 30 ? 'active-red' : '' ?>"
                                data-clear-days="<?= $d ?>">
                                <?= $label ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                    <button type="submit"
                        class="bg-red-600/10 text-red-400 border border-red-500/20 hover:bg-red-600 hover:text-white text-[10px] font-bold px-5 py-2.5 rounded-xl transition-all uppercase tracking-wider inline-flex items-center gap-1.5">
                        <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                        Hapus Record
                    </button>
                </form>
            </div>
        </div>
