<?php
if (!function_exists('convert_srt_to_vtt')) {
function convert_srt_to_vtt(string $srt): string
{
    $srt = strip_utf8_bom($srt);
    $srt = str_replace(["\r\n", "\r"], "\n", $srt);
    $lines = explode("\n", $srt);
    $out = ['WEBVTT', ''];

    foreach ($lines as $line) {
        $trimmed = trim($line);

        if ($trimmed !== '' && ctype_digit($trimmed)) {
            continue;
        }

        if (strpos($line, '-->') !== false) {
            $line = preg_replace('/(\d{1,2}:\d{2}(?::\d{2})?),(\d{3})/', '$1.$2', $line);
        }

        $out[] = $line;
    }

    return implode("\n", $out) . "\n";
}
}

if (!function_exists('strip_utf8_bom')) {
function strip_utf8_bom(string $content): string
{
    return preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
}
}

if (!function_exists('sanitize_subtitle_lang')) {
function sanitize_subtitle_lang(?string $lang, string $default = 'id'): string
{
    $lang = strtolower(trim((string)$lang));
    if (preg_match('/^[a-z]{2,3}(?:-[a-z]{2,8})?$/', $lang)) {
        return $lang;
    }
    return $default;
}
}

if (!function_exists('lang_map')) {
function lang_map(): array
{
    return [
        'id' => 'Indonesia',
        'en' => 'English',
        'ja' => '日本語',
        'zh' => '中文',
        'ko' => '한국어',
        'ms' => 'Melayu',
        'ar' => 'العربية',
        'de' => 'Deutsch',
        'es' => 'Español',
        'fr' => 'Français',
        'it' => 'Italiano',
        'pt' => 'Português',
        'ru' => 'Русский',
        'th' => 'ไทย',
        'vi' => 'Tiếng Việt',
    ];
}
}

if (!function_exists('subtitle_lang_map')) {
function subtitle_lang_map(): array
{
    return lang_map();
}
}

if (!function_exists('lang_label')) {
function lang_label(string $lang): string
{
    $lang = strtolower(trim($lang));
    $labels = lang_map();
    return $labels[$lang] ?? strtoupper($lang);
}
}

if (!function_exists('subtitle_lang_label')) {
function subtitle_lang_label(string $lang): string
{
    return lang_label($lang);
}
}

if (!function_exists('validate_subtitle_file')) {
function validate_subtitle_file(string $tmp_path): bool
{
    return meel_validate_text_upload($tmp_path);
}
}

/* reference build: MEeL-C10H12N2O [984ee2f252e59a9b] */