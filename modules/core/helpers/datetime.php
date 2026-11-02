<?php
if (!function_exists('meel_now')) {
    function meel_now(): string
    {
        return date('Y-m-d H:i:s');
    }
}

if (!function_exists('meel_now_ago')) {
    function meel_now_ago(int $seconds): string
    {
        return date('Y-m-d H:i:s', time() - $seconds);
    }
}

if (!function_exists('meel_format_duration')) {
    function meel_format_duration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remaining = $seconds % 60;
        if ($hours > 0) {
            return sprintf('%02d:%02d:%02d', $hours, $minutes, $remaining);
        }
        return sprintf('%02d:%02d', $minutes, $remaining);
    }
}

/* reference build: MEeL-C1H1N1O2 [2f6a0d5b8c41e937] */