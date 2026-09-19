<?php
if (!function_exists('parse_lrc')) {
function parse_lrc(string $content): array
{
    $content = strip_utf8_bom($content);
    $content = str_replace(["\r\n", "\r"], "\n", $content);
    $lines = explode("\n", $content);
    $result = [];

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;

        if (preg_match('/^\[(\d{1,2}):(\d{2})(?:\.(\d{1,3}))?\](.*)$/', $line, $m)) {
            $min  = (int)$m[1];
            $sec  = (int)$m[2];
            $ms_str = $m[3] ?? '';
            if ($ms_str !== '') {
                $ms_len = strlen($ms_str);
                if ($ms_len <= 2) {
                    $ms = intval(str_pad($ms_str, 2, '0', STR_PAD_RIGHT));
                    $time = $min * 60 + $sec + $ms / 100.0;
                } else {
                    $ms = intval(str_pad($ms_str, 3, '0', STR_PAD_RIGHT));
                    $time = $min * 60 + $sec + $ms / 1000.0;
                }
            } else {
                $time = $min * 60 + $sec;
            }
            $text = trim($m[4]);

            $result[] = [
                'time' => $time,
                'text' => $text,
            ];
        } elseif (preg_match('/^\[ti:(.+)\]$/i', $line, $m)) {
            // metadata tag, skip
        } elseif (preg_match('/^\[ar:(.+)\]$/i', $line, $m)) {
            // metadata tag, skip
        } elseif (preg_match('/^\[al:(.+)\]$/i', $line, $m)) {
            // metadata tag, skip
        }
    }

    usort($result, fn($a, $b) => $a['time'] <=> $b['time']);
    return $result;
}
}

if (!function_exists('generate_lrc')) {
function generate_lrc(array $lines, ?string $title = null, ?string $artist = null): string
{
    $out = [];

    if ($title !== null && $title !== '') {
        $out[] = '[ti:' . $title . ']';
    }
    if ($artist !== null && $artist !== '') {
        $out[] = '[ar:' . $artist . ']';
    }
    $out[] = '';

    foreach ($lines as $line) {
        $time = (float)($line['time'] ?? 0);
        $text = $line['text'] ?? '';
        $min  = (int)floor($time / 60);
        $sec  = (int)floor($time % 60);
        $ms   = (int)round(($time - floor($time)) * 100);
        $out[] = sprintf('[%02d:%02d.%02d]%s', $min, $sec, $ms, $text);
    }

    return implode("\n", $out) . "\n";
}
}

if (!function_exists('validate_lyrics_file')) {
function validate_lyrics_file(string $tmp_path): bool
{
    if (!is_file($tmp_path) || filesize($tmp_path) > 2 * 1024 * 1024) {
        return false;
    }
    $content = @file_get_contents($tmp_path);
    if ($content === false) return false;

    if (strpos($content, "\x00") !== false) return false;
    if (preg_match('/<\?php|<\?=/i', $content)) return false;

    return true;
}
}

if (!function_exists('get_music_lyrics_dir')) {
function get_music_lyrics_dir(): string
{
    return meel_media_base_path('music') . '/lyrics/';
}
}

if (!function_exists('get_music_lyrics_path')) {
function get_music_lyrics_path(int $music_id, ?string $lang = null): string
{
    $dir = get_music_lyrics_dir();
    $lang = sanitize_subtitle_lang($lang, 'id');
    return $dir . $music_id . '.' . $lang . '.lrc';
}
}

if (!function_exists('get_music_lyrics_list')) {
function get_music_lyrics_list(int $music_id): array
{
    $dir = get_music_lyrics_dir();
    $pattern = $dir . $music_id . '.*.lrc';
    $results = [];

    foreach (glob($pattern) ?: [] as $file) {
        $base = basename($file);
        if (preg_match('/^\d+\.([a-z]{2,3}(?:-[a-z]{2,8})?)\.lrc$/i', $base, $m)) {
            $lang = strtolower($m[1]);
            $results[] = [
                'lang'  => $lang,
                'label' => lang_label($lang),
                'file'  => $base,
            ];
        }
    }

    usort($results, fn($a, $b) => $a['lang'] === 'id' ? -1 : ($b['lang'] === 'id' ? 1 : strcmp($a['lang'], $b['lang'])));
    return $results;
}
}

if (!function_exists('delete_music_lyrics')) {
function delete_music_lyrics(int $music_id, string $lang): bool
{
    $path = get_music_lyrics_path($music_id, $lang);
    if (is_file($path)) {
        return @unlink($path);
    }
    return false;
}
}

if (!function_exists('save_music_lyrics')) {
function save_music_lyrics(int $music_id, string $lang, string $content): bool
{
    $dir = get_music_lyrics_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    if (!is_writable($dir)) {
        @chmod($dir, 0777);
    }

    $lang = sanitize_subtitle_lang($lang, 'id');
    $content = strip_utf8_bom($content);

    $path = $dir . $music_id . '.' . $lang . '.lrc';
    return file_put_contents($path, $content, LOCK_EX) !== false;
}
}

/* reference build: MEeL-KARAOKE-v1 */
