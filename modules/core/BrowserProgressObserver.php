<?php
require_once __DIR__ . '/ProgressObserver.php';

/**
 * Observer progres yang menuliskan JS langsung ke respons.
 *
 * Peta stage ke handler di bawah adalah satu-satunya tempat yang tahu
 * stage apa saja yang ada, sehingga menambah stage baru berarti menyentuh
 * satu daftar, bukan satu switch yang panjang.
 *
 * Setiap handler menerima isi data stage itu dan tidak tahu apa pun
 * tentang stage lain.
 */
class BrowserProgressObserver implements ProgressObserver
{
    private const STAGE_HANDLERS = [
        'download_start' => 'handleDownloadStart',
        'transcode_start' => 'handleTranscodeStart',
        'phase' => 'handlePhase',
        'download_progress' => 'handleDownloadProgress',
        'transcode_progress' => 'handleTranscodeProgress',
        'sprite_progress' => 'handleSpriteProgress',
        'done' => 'handleDone',
        'done_transcode' => 'handleDoneTranscode',
        'error' => 'handleError',
    ];

    private bool $overlayInjected = false;
    private bool $isAdmin;

    public function __construct(bool $isAdmin = false)
    {
        $this->isAdmin = $isAdmin;
    }

    public function onProgress(string $stage, array $data = []): void
    {
        $handler = self::STAGE_HANDLERS[$stage] ?? null;
        if ($handler === null) {
            return;
        }
        $this->$handler($data);
    }

    private function handleDownloadStart(array $data): void
    {
        $this->injectOverlay('download');
        $this->emitJs('meelDlInfo(' . json_encode($data['url'] ?? '') . ');');
    }

    private function handleTranscodeStart(array $data): void
    {
        $this->injectOverlay('transcode');
    }

    private function handlePhase(array $data): void
    {
        $this->emitJs(
            'if(typeof meelPhase==="function"){meelPhase(' . json_encode($data['phase'] ?? '') . ');}'
        );
    }

    private function handleDownloadProgress(array $data): void
    {
        if (array_key_exists('eta', $data)) {
            $args = implode(',', array_map(
                'json_encode',
                [
                    $data['pct'] ?? 0,
                    $data['eta'] ?? '',
                    $data['speed'] ?? '',
                    $data['size'] ?? '',
                    $data['frag'] ?? '',
                ]
            ));
            $this->emitJs('meelDlPct(' . $args . ');');
            return;
        }

        if (isset($data['pct'])) {
            $this->emitJs('meelDlPct(' . json_encode($data['pct']) . ');');
        }
    }

    private function handleTranscodeProgress(array $data): void
    {
        $this->emitPercent('meelTcPct', $data);
    }

    private function handleSpriteProgress(array $data): void
    {
        $this->emitPercent('meelSpPct', $data);
    }

    private function emitPercent(string $fn, array $data): void
    {
        $this->emitJs(
            $fn
            . '(' . json_encode($data['pct'] ?? 0)
            . (isset($data['label']) ? ', ' . json_encode($data['label']) : '')
            . ');'
        );
    }

    private function handleDone(array $data): void
    {
        $this->emitJs(
            'meelDone(' . json_encode($data['title'] ?? '') . ', '
            . json_encode($data['url'] ?? '') . ');'
        );
    }

    private function handleDoneTranscode(array $data): void
    {
        $this->emitJs(
            'meelDoneTranscode(' . json_encode($data['title'] ?? '') . ', '
            . json_encode($data['download_link'] ?? '') . ');'
        );
    }

    private function handleError(array $data): void
    {
        if (!$this->isAdmin) {
            $this->emitJs('window.location.href="err/index.php?code=server_error";');
            return;
        }

        if (!$this->overlayInjected) {
            $this->injectOverlay('error');
        }
        $this->emitJs('meelError(' . json_encode($data['message'] ?? '') . ');');
    }

    private function emitJs(string $js): void
    {
        try {
            echo '<script>' . $js . '</script>';
            echo str_repeat(' ', 1024);
            if (connection_status() === CONNECTION_NORMAL) {
                flush();
            }
        } catch (\Throwable $e) {
            error_log('[MEeL] BrowserProgressObserver::emitJs error: ' . $e->getMessage());
        }
    }

    private function injectOverlay(string $initialPhase): void
    {
        if ($this->overlayInjected) {
            return;
        }
        $this->overlayInjected = true;

        try {
            while (ob_get_level()) {
                ob_end_clean();
            }

            header('X-Accel-Buffering: no');
            header('Content-Encoding: none');

            $ui_file = dirname(__DIR__, 2) . '/partials/ui.php';
            if (file_exists($ui_file)) {
                include $ui_file;
            } else {
                error_log('[MEeL] WARN: partials/ui.php tidak ditemukan: ' . $ui_file);
            }

            echo str_repeat(' ', 65536);
            echo '<script>if(typeof meelPhase==="function"){meelPhase(' . json_encode($initialPhase) . ');}</script>';
            if (connection_status() === CONNECTION_NORMAL) {
                flush();
            }
        } catch (\Throwable $e) {
            error_log('[MEeL] BrowserProgressObserver::injectOverlay error: ' . $e->getMessage());
        }
    }
}

/* reference build: MEeL-C2H5NO2 [805bbc5bedf1fb2a] */
