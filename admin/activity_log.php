<?php
/**
 * @var \mysqli $conn
 */

/* reference build: MEeL-C5H5N5O [c2c3223a89e5e36a] */
include __DIR__ . '/../auth/config.php';
include __DIR__ . '/../auth/auth.php';
include_once __DIR__ . '/../modules/core/helpers.php';
require_admin($conn);
require_once __DIR__ . '/includes/export_columns.php';
require_once __DIR__ . '/../modules/core/helpers/export.php';

$uq_export_cols = meel_export_cols_upload_queue();
$log_export_cols = meel_export_cols_activity_log();
require_once __DIR__ . '/../modules/media/AdminActivityRepository.php';

$logRepo = new AdminActivityRepository($conn);
$valid_tabs = ['log', 'views', 'uploads'];
$active_tab = in_array($_GET['tab'] ?? '', $valid_tabs) ? $_GET['tab'] : 'log';
$preview_limit = 15;

$sync_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sync_views_now'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $sync_msg = 'CSRF Token tidak valid.';
    } else {
        require_once __DIR__ . '/../modules/media/MediaViewer.php';
        MediaViewer::syncViewsFromLogs($conn);
        $throttleFile = dirname(__DIR__, 2) . '/temp/gc_views_sync_last_run.txt';
        @file_put_contents($throttleFile, time());
        $sync_msg = 'Views counter berhasil disinkronkan dari view_logs.';
    }
}

$uq_status = '';
$uq_search = '';
$uq_days = 30;
$uq_page = 1;
$uq_total_rows = 0;
$uq_total_pages = 1;
$uq_rows = null;
$uq_stats = ['total' => 0, 'completed_count' => 0, 'failed_count' => 0, 'processing_count' => 0];
$uq_clear_msg = '';
if ($active_tab === 'uploads') {
    $uq_status = $_GET['status'] ?? '';
    $uq_search = trim($_GET['q'] ?? '');
    $uq_days = max(1, min(365, (int)($_GET['days'] ?? 30)));
    $uq_page = max(1, (int)($_GET['page'] ?? 1));
    $uq_per_page = 50;
    require_once __DIR__ . '/../modules/media/AdminUploadQueueRepository.php';
    $uqRepo = new AdminUploadQueueRepository($conn);
    $uqRepo->buildFilter($uq_status, $uq_search, $uq_days);

    $uq_total_rows = $uqRepo->countFiltered();
    $uq_total_pages = max(1, (int)ceil($uq_total_rows / $uq_per_page));
    $uq_page = min($uq_page, $uq_total_pages);
    $uq_offset = ($uq_page - 1) * $uq_per_page;

    $uq_rows = $uqRepo->fetchPage($uq_per_page, $uq_offset);
    $uq_stats = $uqRepo->getStats();

    $uq_clear_msg = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_uq_older_than'])) {
        if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
            $uq_clear_msg = 'CSRF Token tidak valid.';
        } else {
            $uq_clear_days = max(1, (int)($_POST['clear_uq_days'] ?? 30));
            $uq_deleted = $uqRepo->clearOlderThan($uq_clear_days);
            $uq_clear_msg = "Berhasil menghapus {$uq_deleted} record upload queue lebih dari {$uq_clear_days} hari.";
        }
    }

    $uq_export_format = $_GET['export'] ?? '';
    if (in_array($uq_export_format, ['csv', 'json', 'xls'], true)) {
        $timestamp = date('Y-m-d_H-i-s');
        $filename_base = "upload-queue-export-{$timestamp}";
        $all_uq_rows = $uqRepo->fetchAll();
        $uq_labels = meel_export_labels($uq_export_cols);

        switch ($uq_export_format) {
            case 'csv':
                header('Content-Type: text/csv; charset=utf-8');
                header("Content-Disposition: attachment; filename=\"{$filename_base}.csv\"");

                $output = fopen('php://output', 'w');
                fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
                fputcsv($output, $uq_labels);

                foreach ($all_uq_rows as $row) {
                    fputcsv($output, meel_export_values($row, $uq_export_cols));
                }
                fclose($output);
                break;

            case 'json':
                header('Content-Type: application/json; charset=utf-8');
                header("Content-Disposition: attachment; filename=\"{$filename_base}.json\"");

                $json_data = array_map(
                    static fn(array $r): array => meel_export_map($r, $uq_export_cols),
                    $all_uq_rows
                );

                echo json_encode($json_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                break;

            case 'xls':
                header('Content-Type: application/vnd.ms-excel; charset=utf-8');
                header("Content-Disposition: attachment; filename=\"{$filename_base}.xls\"");

                meel_xls_begin('Antrean Unggah');
                meel_xls_header($uq_labels);
                foreach ($all_uq_rows as $row) {
                    meel_xls_data_row(meel_export_values($row, $uq_export_cols), $uq_export_cols);
                }
                meel_xls_end();
                break;
        }
        exit;
    }

    if (isset($_GET['preview']) && $_GET['preview'] === '1' && in_array($_GET['format'] ?? '', ['csv', 'json', 'xls'], true)) {
        $preview_format = $_GET['format'];
        $preview_total = $uqRepo->countFiltered();
        $preview_rows = $uqRepo->fetchPage($preview_limit, 0);
        $preview_rows = is_array($preview_rows) ? $preview_rows : [];

        $content = '';
        switch ($preview_format) {
            case 'csv':

                $quote = static fn($v): string => '"' . str_replace('"', '""', (string) $v) . '"';
                $content = implode(',', array_map($quote, meel_export_labels($uq_export_cols))) . "\n";
                foreach ($preview_rows as $r) {
                    $content .= implode(',', array_map($quote, meel_export_values($r, $uq_export_cols))) . "\n";
                }
                if ($preview_total > $preview_limit) {
                    $content .= "\n... dan " . ($preview_total - $preview_limit) . " baris lainnya\n";
                }
                break;

            case 'json':
                $json_out = array_map(
                    static fn(array $r): array => meel_export_map($r, $uq_export_cols),
                    $preview_rows
                );
                $content = (string)(json_encode($json_out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]');
                if ($preview_total > $preview_limit) {
                    $content .= "\n\n/* ... dan {$preview_total} total baris (ditampilkan {$preview_limit}) */";
                }
                break;

            case 'xls':
                $content = implode("\t", meel_export_labels($uq_export_cols)) . "\n";
                foreach ($preview_rows as $r) {
                    $content .= implode("\t", meel_export_values($r, $uq_export_cols)) . "\n";
                }
                if ($preview_total > $preview_limit) {
                    $content .= "\n... dan " . ($preview_total - $preview_limit) . " baris lainnya\n";
                }
                break;
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'format' => $preview_format,
            'total' => $preview_total,
            'preview_count' => min($preview_total, $preview_limit),
            'content' => $content,
        ]);
        exit;
    }
}

$action_filter = $_GET['action'] ?? '';
$search_q = trim($_GET['q'] ?? '');
$days = max(1, min(365, (int)($_GET['days'] ?? 7)));
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 50;

$clear_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_older_than'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $clear_msg = 'CSRF Token tidak valid.';
    } else {
        $clear_days = max(1, (int)($_POST['clear_days'] ?? 30));
        $deleted = $logRepo->clearOlderThan($clear_days);
        $next_id = 1;
        $max_res = $conn->query('SELECT MAX(id) AS max_id FROM activity_log');
        if ($max_res) {
            $max_row = $max_res->fetch_assoc();
            $next_id = $max_row['max_id'] ? (int)$max_row['max_id'] + 1 : 1;
        }
        $clear_msg = "Berhasil menghapus {$deleted} log lebih dari {$clear_days} hari. Auto-increment di-reset ke {$next_id}.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_all_logs'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $clear_msg = 'CSRF Token tidak valid.';
    } else {
        if ($logRepo->clearAll()) {
            $clear_msg = 'Semua log aktivitas berhasil dihapus. Auto-increment telah di-reset ke 1.';
        } else {
            $clear_msg = 'Gagal menghapus log: ' . $conn->error;
        }
    }
}

$logRepo->buildFilter($action_filter, $search_q, $days);

$total_rows = $logRepo->countFiltered();
$total_pages = max(1, (int)ceil($total_rows / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

$rows = $logRepo->fetchPage($per_page, $offset);
$all_actions = $logRepo->getDistinctActions();
$stats = $logRepo->getWeeklyStats();

$export_format = $_GET['export'] ?? '';

if (in_array($export_format, ['csv', 'json', 'xls'], true)) {
    $timestamp = date('Y-m-d_H-i-s');
    $filename_base = "activity-log-export-{$timestamp}";
    $rows = $logRepo->fetchAll();
    $log_labels = meel_export_labels($log_export_cols);

    switch ($export_format) {
        case 'csv':
            header('Content-Type: text/csv; charset=utf-8');
            header("Content-Disposition: attachment; filename=\"{$filename_base}.csv\"");

            $output = fopen('php://output', 'w');
            fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($output, $log_labels);

            foreach ($rows as $row) {
                fputcsv($output, meel_export_values($row, $log_export_cols));
            }
            fclose($output);
            break;

        case 'json':
            header('Content-Type: application/json; charset=utf-8');
            header("Content-Disposition: attachment; filename=\"{$filename_base}.json\"");

            $json_data = array_map(
                static fn(array $r): array => meel_export_map($r, $log_export_cols),
                $rows
            );

            echo json_encode($json_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            break;

        case 'xls':
            header('Content-Type: application/vnd.ms-excel; charset=utf-8');
            header("Content-Disposition: attachment; filename=\"{$filename_base}.xls\"");

            meel_xls_begin('Log Aktivitas');
            meel_xls_header($log_labels);
            foreach ($rows as $row) {
                meel_xls_data_row(meel_export_values($row, $log_export_cols), $log_export_cols);
            }
            meel_xls_end();
            break;
    }
    exit;
}

if (isset($_GET['preview']) && $_GET['preview'] === '1' && in_array($_GET['format'] ?? '', ['csv', 'json', 'xls'], true)) {
    $preview_format = $_GET['format'];
    $preview_total = $logRepo->countFiltered();
    $preview_rows = $logRepo->fetchPage($preview_limit, 0);
    $preview_rows = is_array($preview_rows) ? $preview_rows : [];

    $content = '';
    switch ($preview_format) {
        case 'csv':
            $quote = static fn($v): string => '"' . str_replace('"', '""', (string) $v) . '"';
            $content = implode(',', array_map($quote, meel_export_labels($log_export_cols))) . "\n";
            foreach ($preview_rows as $r) {
                $content .= implode(',', array_map($quote, meel_export_values($r, $log_export_cols))) . "\n";
            }
            if ($preview_total > $preview_limit) {
                $content .= "\n... dan " . ($preview_total - $preview_limit) . " baris lainnya\n";
            }
            break;

        case 'json':
            $json_out = array_map(
                static fn(array $r): array => meel_export_map($r, $log_export_cols),
                $preview_rows
            );
            $content = (string)(json_encode($json_out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]');
            if ($preview_total > $preview_limit) {
                $content .= "\n\n/* ... dan {$preview_total} total baris (ditampilkan {$preview_limit}) */";
            }
            break;

        case 'xls':
            $content = implode("\t", meel_export_labels($log_export_cols)) . "\n";
            foreach ($preview_rows as $r) {
                $content .= implode("\t", meel_export_values($r, $log_export_cols)) . "\n";
            }
            if ($preview_total > $preview_limit) {
                $content .= "\n... dan " . ($preview_total - $preview_limit) . " baris lainnya\n";
            }
            break;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'format' => $preview_format,
        'total' => $preview_total,
        'preview_count' => min($preview_total, $preview_limit),
        'content' => $content,
    ]);
    exit;
}

?>
<!DOCTYPE html>
<html lang="id">

<head>
    <?php
    $_META_TITLE = 'MEeL | Log Aktivitas';
    $_META_DESC = 'MEeL Log Aktivitas — Audit trail untuk monitoring aktivitas pengguna.';
    include __DIR__ . '/../partials/link.php';
    $scripts_root = '../';
    include __DIR__ . '/../partials/scripts.php';
    ?>
    <?php meel_css_links('admin', '../assets/css/admin'); ?>
    <link rel="stylesheet" href="../assets/css/admin/activity_log.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin/activity_log.css') ?>">
</head>

<body class="text-gray-300 min-h-screen">

    <?php
    $page_title = 'Log Aktivitas';
    $media_type = 'analytics';
    $back_url = 'index.php';
    include __DIR__ . '/header-admin.php';
    ?>
    <div class="max-w-7xl mx-auto px-6 md:px-10 xl:px-16 pt-4">
        <div class="flex gap-0 rounded-xl overflow-hidden border border-white/10 mb-6">
            <a href="activity-log"
                class="flex-1 text-center text-[10px] font-black uppercase tracking-widest py-3 transition-all border-r border-white/10 <?= $active_tab === 'log' ? 'bg-blue-600 text-white' : 'text-gray-400 hover:text-gray-300 hover:bg-white/5' ?>">
                <i data-lucide="activity" class="w-3 h-3 inline mr-1.5"></i> Log Aktivitas
            </a>
            <a href="activity-log?tab=views"
                class="flex-1 text-center text-[10px] font-black uppercase tracking-widest py-3 transition-all <?= $active_tab === 'views' ? 'bg-purple-600 text-white' : 'text-gray-400 hover:text-gray-300 hover:bg-white/5' ?>">
                <i data-lucide="bar-chart-3" class="w-3 h-3 inline mr-1.5"></i> Analitik Tayangan
            </a>
            <a href="activity-log?tab=uploads"
                class="flex-1 text-center text-[10px] font-black uppercase tracking-widest py-3 transition-all border-l border-white/10 <?= $active_tab === 'uploads' ? 'bg-green-600 text-white' : 'text-gray-400 hover:text-gray-300 hover:bg-white/5' ?>">
                <i data-lucide="upload-cloud" class="w-3 h-3 inline mr-1.5"></i> Antrean Unggah
            </a>
        </div>
    </div>

    <?php if ($active_tab === 'log'): ?>
        <?php include __DIR__ . '/partials/activity_log_log.php'; ?>
    <?php endif; ?>

    <?php if ($active_tab === 'views'): ?>
        <?php include __DIR__ . '/partials/activity_log_views.php'; ?>
    <?php endif; ?>
    <?php if ($active_tab === 'uploads'): ?>
        <?php include __DIR__ . '/partials/activity_log_uploads.php'; ?>
    <?php endif; ?>

    <script>
        if (typeof lucide !== 'undefined') lucide.createIcons();
        <?php if ($clear_msg): ?>
            Swal.fire({
                title: 'Selesai!',
                text: <?= json_encode($clear_msg) ?>,
                icon: 'success',
                confirmButtonColor: '#2563eb',
                background: '#0e1118',
                color: '#fff'
            });
        <?php endif; ?>
        <?php if (!empty($uq_clear_msg)): ?>
            Swal.fire({
                title: 'Selesai!',
                text: <?= json_encode($uq_clear_msg) ?>,
                icon: 'success',
                confirmButtonColor: '#15803d',
                background: '#0e1118',
                color: '#fff'
            });
        <?php endif; ?>
    </script>
</body>

</html>
