<?php
if (!isset($conn) || !($conn instanceof \mysqli)) {
    http_response_code(403);
    exit;
}
require_once __DIR__ . '/../../modules/media/MediaViewer.php';
$view_stats = MediaViewer::getViewStats($conn);
?>
<div class="max-w-7xl mx-auto px-6 md:px-10 xl:px-16 pb-8">
    <div class="flex items-center gap-5 mb-8">
        <div class="w-14 h-14 rounded-2xl bg-purple-500/15 border border-purple-500/25 flex items-center justify-center shrink-0">
            <i data-lucide="bar-chart-3" class="w-6 h-6 text-purple-500"></i>
        </div>
        <div>
            <h1 class="text-3xl md:text-4xl font-extrabold text-white leading-tight tracking-tight">Analitik Tayangan</h1>
            <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400 mt-1.5">Pemantauan Log Tayangan</p>
        </div>
    </div>

    <?php if ($sync_msg): ?>
        <div class="mb-6 p-5 rounded-2xl text-sm flex items-center gap-3 bg-green-500/10 text-green-400 border border-green-500/20">
            <i data-lucide="check-circle" class="w-5 h-5 shrink-0"></i>
            <?= htmlspecialchars($sync_msg) ?>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 md:gap-5 mb-8">
        <div class="glass p-5 rounded-2xl border-l-4 border-purple-500">
            <p class="text-[9px] font-bold text-gray-400 uppercase mb-1.5">Total Baris Log</p>
            <span class="text-2xl font-bold text-white"><?= number_format($view_stats['total_log_rows']) ?></span>
            <span class="text-[10px] text-gray-400 ml-1.5">baris</span>
        </div>
        <div class="glass p-5 rounded-2xl border-l-4 border-red-500">
            <p class="text-[9px] font-bold text-gray-400 uppercase mb-1.5">Baris Log Video</p>
            <span class="text-2xl font-bold text-white"><?= number_format($view_stats['video_log_rows']) ?></span>
            <span class="text-[10px] text-gray-400 ml-1.5">baris</span>
        </div>
        <div class="glass p-5 rounded-2xl border-l-4 border-orange-500">
            <p class="text-[9px] font-bold text-gray-400 uppercase mb-1.5">Baris Log Musik</p>
            <span class="text-2xl font-bold text-white"><?= number_format($view_stats['music_log_rows']) ?></span>
            <span class="text-[10px] text-gray-400 ml-1.5">baris</span>
        </div>
        <div class="glass p-5 rounded-2xl border-l-4 border-green-500">
            <p class="text-[9px] font-bold text-gray-400 uppercase mb-1.5">Status Sinkronisasi</p>
            <?php
            $synced = ($view_stats['video_views_counter'] + $view_stats['music_views_counter']) > 0;
            ?>
            <?php if ($synced): ?>
                <span class="text-lg font-bold text-green-400"><i data-lucide="check-circle" class="w-5 h-5 inline"></i> Tersinkron</span>
            <?php else: ?>
                <span class="text-lg font-bold text-gray-400"><i data-lucide="minus-circle" class="w-5 h-5 inline"></i> Tidak Ada Data</span>
            <?php endif; ?>
        </div>
    </div>
    <!-- reference build: ch2892sa2 [21321343nfrey8] -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 md:gap-5 mb-8">
        <div class="glass p-5 rounded-2xl border-l-4 border-red-500">
            <p class="text-[9px] font-bold text-gray-400 uppercase mb-1.5">Penghitung Tayangan Video</p>
            <span class="text-2xl font-bold text-white"><?= number_format($view_stats['video_views_counter']) ?></span>
            <span class="text-[10px] text-gray-400 ml-1.5">tayangan</span>
        </div>
        <div class="glass p-5 rounded-2xl border-l-4 border-orange-500">
            <p class="text-[9px] font-bold text-gray-400 uppercase mb-1.5">Penghitung Tayangan Musik</p>
            <span class="text-2xl font-bold text-white"><?= number_format($view_stats['music_views_counter']) ?></span>
            <span class="text-[10px] text-gray-400 ml-1.5">tayangan</span>
        </div>
    </div>

    <div class="glass p-6 rounded-2xl mb-8 border border-purple-500/20">
        <div class="flex items-center gap-3 mb-5">
            <div class="p-2 rounded-xl bg-purple-500/10 border border-purple-500/20">
                <i data-lucide="refresh-cw" class="w-4 h-4 text-purple-400"></i>
            </div>
            <div>
                <h3 class="text-xs font-bold text-gray-300">Sinkron Manual</h3>
                <p class="text-[9px] text-gray-400">Sinkronkan penghitung tayangan dari view_logs sekarang. Sinkron otomatis berjalan setiap 1 jam.</p>
            </div>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="sync_views_now" value="1">
            <button type="submit"
                class="bg-purple-600/10 text-purple-400 border border-purple-500/20 hover:bg-purple-600 hover:text-white text-[10px] font-bold px-6 py-3 rounded-xl transition-all uppercase tracking-wider inline-flex items-center gap-2">
                <i data-lucide="refresh-cw" class="w-3.5 h-3.5"></i>
                Sinkronkan Sekarang
            </button>
        </form>
    </div>
    <?php if (!empty($view_stats['chart'])): ?>
        <div class="glass p-6 rounded-2xl mb-8">
            <div class="flex items-center gap-3 mb-5">
                <div class="p-2 rounded-xl bg-purple-500/10 border border-purple-500/20">
                    <i data-lucide="trending-up" class="w-4 h-4 text-purple-400"></i>
                </div>
                <div>
                    <h3 class="text-xs font-bold text-gray-300">Pertumbuhan Log Tayangan (30 Hari)</h3>
                    <p class="text-[9px] text-gray-400">Pertumbuhan jumlah view_logs per hari.</p>
                </div>
            </div>
            <div id="view-chart" class="w-full" style="height:280px;"></div>
            <script src="../assets/js/compatibilitas/chart.umd.min.js<?= meel_asset_version('assets/js/compatibilitas/chart.umd.min.js') ?>"></script>
            <script>
                (function() {
                    const data = <?= json_encode($view_stats['chart']) ?>;
                    const labels = data.map(d => {
                        const dt = new Date(d.date);
                        return dt.toLocaleDateString('id-ID', {
                            day: 'numeric',
                            month: 'short'
                        });
                    });
                    const values = data.map(d => d.views);
                    const ctx = document.getElementById('view-chart');
                    const isLight = document.documentElement.getAttribute('data-theme') === 'light';
                    const gridColor = isLight ? 'rgba(0,0,0,0.06)' : 'rgba(255,255,255,0.03)';
                    const tickColor = isLight ? '#52525b' : '#6b7280';
                    new Chart(ctx, {
                        type: 'bar',
                        data: {
                            labels: labels,
                            datasets: [{
                                label: 'Tayangan',
                                data: values,
                                backgroundColor: isLight ? 'rgba(168,85,247,0.55)' : 'rgba(168,85,247,0.4)',
                                borderColor: 'rgba(168,85,247,1)',
                                borderWidth: 1,
                                borderRadius: 4
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    display: false
                                }
                            },
                            scales: {
                                x: {
                                    grid: {
                                        color: gridColor
                                    },
                                    ticks: {
                                        color: tickColor,
                                        font: {
                                            size: 9
                                        }
                                    }
                                },
                                y: {
                                    beginAtZero: true,
                                    grid: {
                                        color: gridColor
                                    },
                                    ticks: {
                                        color: tickColor,
                                        font: {
                                            size: 9
                                        },
                                        precision: 0
                                    }
                                }
                            }
                        }
                    });
                })();
            </script>
        </div>
    <?php endif; ?>
</div>