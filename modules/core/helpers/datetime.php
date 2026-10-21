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

/* reference build: MEeL-C1H1N1O2 [2f6a0d5b8c41e937] */
