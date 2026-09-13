<?php


require_once __DIR__ . '/../core/helpers.php';

trait FfmpegUtils
{

    protected function getEnvPrefix(): string
    {
        return "export LD_LIBRARY_PATH=''; export PATH=/usr/local/bin:/usr/bin:/bin; export LC_ALL=en_US.UTF-8; ";
    }

    
    protected function probeDuration(string $file_path): float
    {
        $cmd = $this->getEnvPrefix() . escapeshellarg($this->ffprobe_bin)
            . " -v error -show_entries format=duration"
            . " -of default=noprint_wrappers=1:nokey=1 "
            . escapeshellarg($file_path);
        return (float)trim((string)shell_exec($cmd));
    }

    

    protected function ensureDir(string $dir, int $perms = 0755): bool
    {
        if (is_dir($dir)) {
            return true;
        }
        if (!mkdir($dir, $perms, true) && !is_dir($dir)) {
            error_log("[MEeL] ensureDir GAGAL: {$dir}");
            return false;
        }
        return true;
    }

    
    protected function removeFile(string $path): void
    {
        if (!is_file($path) && !is_link($path)) {
            return;
        }
        if (!unlink($path)) {
            error_log("[MEeL] removeFile GAGAL: {$path}");
        }
    }

    
    protected function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob(rtrim($dir, '/') . "/*") ?: [] as $f) {
            $this->removeFile($f);
        }
        if (!rmdir($dir)) {
            error_log("[MEeL] removeDir GAGAL: {$dir}");
        }
    }

    
    protected function moveFile(string $src, string $dst): bool
    {
        if (!is_file($src)) {
            error_log(sprintf('[MEeL] moveFile GAGAL (sumber tidak ada): src=%s dst=%s', $src, $dst));
            return false;
        }
        if (!is_dir(dirname($dst)) || !is_writable(dirname($dst))) {
            error_log(sprintf(
                '[MEeL] moveFile GAGAL (direktori tujuan tidak writable): src=%s dst=%s',
                $src,
                $dst
            ));
            return false;
        }

        $src_stat = stat($src);
        $dst_stat = stat(dirname($dst));
        $crossDevice = $src_stat !== false
            && $dst_stat !== false
            && ($src_stat['dev'] ?? 0) !== ($dst_stat['dev'] ?? 0);

        if (!$crossDevice) {
            error_clear_last();
            if (rename($src, $dst)) return true;

            $rename_err = error_get_last();
            $rename_msg = is_array($rename_err) ? ($rename_err['message'] ?? 'unknown') : 'unknown';
        } else {
            $rename_msg = 'skipped (cross-device: src/dst berbeda filesystem) — langsung copy';
        }

        error_clear_last();
        if (copy($src, $dst)) {
            if (!unlink($src)) {
                error_log("[MEeL] moveFile: source tidak terhapus setelah copy: {$src}");
            }
            return true;
        }

        $copy_err = error_get_last();
        $copy_msg = is_array($copy_err) ? ($copy_err['message'] ?? 'unknown') : 'unknown';

        error_log(sprintf(
            '[MEeL] moveFile GAGAL: src=%s dst=%s | rename: %s | copy: %s',
            $src,
            $dst,
            $rename_msg,
            $copy_msg
        ));
        return false;
    }

    
    protected function sanitizeFilename(string $title): string
    {
        $name = trim($title);
        if (empty($name)) {
            $name = 'untitled-media';
        }

        $name = preg_replace("/[^a-zA-Z0-9_\x{3000}-\x{9fff}\x{30a0}-\x{30ff}\x{3040}-\x{309f}\x{ff00}-\x{ffef}]+/u", "-", $name);
        $name = str_replace(['..', './'], '', $name);
        $name = mb_substr($name, 0, 120);
        
        $name = trim($name, "- \t\n\r\0\x0B");

        return $name ?: 'untitled-media';
    }

    protected function calculateSpriteParams(float $duration): array
    {
        $w    = 160;
        $h    = 90;
        $cols = 5;

        if ($duration > 3600) {
            $interval = 300;
        } elseif ($duration > 1800) {
            $interval = 180;
        } elseif ($duration > 300) {
            $interval = 60;
        } else {
            $interval = 10;
        }

        $total_frames = (int)ceil($duration / $interval);
        $rows         = max(1, (int)ceil($total_frames / $cols));

        return compact('w', 'h', 'cols', 'rows', 'interval', 'total_frames');
    }

    protected function buildSpriteCommand(string $video_path, string $sprite_output): ?array
    {
        $duration = $this->probeDuration($video_path);
        if ($duration <= 0) return null;

        $p   = $this->calculateSpriteParams($duration);
        $filter = "fps=1/{$p['interval']},scale={$p['w']}:{$p['h']},tile={$p['cols']}x{$p['rows']}";

        $lib_path = '/usr/lib/x86_64-linux-gnu:/usr/local/lib';
        $env = ['LD_LIBRARY_PATH' => $lib_path, 'PATH' => '/usr/local/bin:/usr/bin:/bin', 'LC_ALL' => 'en_US.UTF-8'];

        $cmd = [
            $this->ffmpeg_bin,
            '-y',
            '-threads', '8',
            '-i', $video_path,
            '-vf', $filter,
            '-c:v', 'libwebp',
            '-q:v', '78',
            $sprite_output,
        ];

        return ['cmd' => $cmd, 'env' => $env, 'duration' => $duration] + $p;
    }

    protected function generateVTT(string $vtt_path, int $total_frames, int $interval, float $duration, int $w, int $cols): void
    {
        $vtt_content = "WEBVTT\n\n";
        for ($i = 0; $i < $total_frames; $i++) {
            $start = $i * $interval;
            $end   = min(($i + 1) * $interval, $duration);

            $start_time = gmdate("H:i:s", (int)$start) . ".000";
            $end_time   = gmdate("H:i:s", (int)$end)   . ".000";

            $x = ($i % $cols) * $w;
            $y = (int)floor($i / $cols) * 90;

            $vtt_content .= "$start_time --> $end_time\n";
            $vtt_content .= "thumb_sprite.webp#xywh=$x,$y,$w,90\n\n";
        }
        file_put_contents($vtt_path, $vtt_content);
    }

    protected function generateSpriteAndVTT(string $video_path, string $target_folder): void
    {
        $sprite_file = $target_folder . 'thumb_sprite.webp';
        $data = $this->buildSpriteCommand($video_path, $sprite_file);
        if (!$data) return;

        $ffmpeg_out = [];
        exec($this->getEnvPrefix() . escapeshellarg($this->ffmpeg_bin) . ' '
            . implode(' ', array_map('escapeshellarg', array_slice($data['cmd'], 1))) . ' 2>&1',
            $ffmpeg_out
        );

        if (!file_exists($sprite_file) || filesize($sprite_file) === 0) {
            error_log("[MEeL] ERROR: Sprite gagal. Output: " . implode(" | ", array_slice($ffmpeg_out, -10)));
            return;
        }

        $this->generateVTT(
            $target_folder . 'thumbnails.vtt',
            $data['total_frames'],
            $data['interval'],
            $data['duration'],
            $data['w'],
            $data['cols']
        );
    }
}
