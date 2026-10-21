<?php
final class SearchQuery
{
    private const STOPWORDS = [
        'a', 'about', 'an', 'are', 'as', 'at', 'be', 'by', 'com', 'de', 'en',
        'for', 'from', 'how', 'i', 'in', 'is', 'it', 'la', 'of', 'on', 'or',
        'that', 'the', 'this', 'to', 'und', 'was', 'what', 'when', 'where',
        'who', 'will', 'with', 'www',
    ];
    public static function boolean(string $q): string
    {
        $q = trim($q);
        if ($q === '') {
            return '';
        }
        preg_match_all('/"[^"]*"|\S+/', $q, $matches);
        $required = [];
        foreach ($matches[0] as $token) {
            if (preg_match('/^"[^"]+"$/', $token)) {
                $required[] = '+' . $token;
                continue;
            }
            foreach (preg_split('/[+\-]+/', $token) as $part) {
                $part = trim($part, '"*');
                if ($part === '' || mb_strlen($part, 'UTF-8') < 2) {
                    continue;
                }
                if (in_array(mb_strtolower($part, 'UTF-8'), self::STOPWORDS, true)) {
                    continue;
                }
                $required[] = '+' . $part . '*';
            }
        }
        $required = array_values(array_unique($required));
        return $required ? implode(' ', $required) : $q;
    }
    public static function terms(string $q): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }
        preg_match_all('/"[^"]*"|\S+/', $q, $matches);
        $terms = [];
        foreach ($matches[0] as $token) {
            $token = trim($token, '"*');
            $token = preg_replace('/[+\-]+/', ' ', $token);
            foreach (preg_split('/\s+/', $token) as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $terms[] = $part;
                }
            }
        }
        return array_values(array_unique($terms));
    }
    public static function like(string $term): string
    {
        return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
    }
}
