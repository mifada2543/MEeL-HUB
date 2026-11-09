<?php
if (!function_exists('getMecabPath')) {
    function getMecabPath(): string
    {
        static $path = null;
        if ($path !== null) return $path;
        if (function_exists('resolve_binary')) {
            $path = resolve_binary(['/usr/bin/mecab', '/usr/local/bin/mecab', 'mecab']);
            return $path;
        }
        $candidates = ['/usr/bin/mecab', '/usr/local/bin/mecab', 'mecab'];
        foreach ($candidates as $candidate) {
            if (strpos($candidate, '/') !== false) {
                if (@is_executable($candidate)) {
                    $path = $candidate;
                    return $path;
                }
            }
        }
        $path = 'mecab';
        return $path;
    }
}
if (!function_exists('meel_japanese_normalize_separators')) {
    function meel_japanese_normalize_separators(string $text): string
    {
        static $search = ['×', '*', '&', '/', '【', '】', '「', '」', '(', ')', '鏡音', '巡音', '初音'];
        static $replace = [' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', 'kagamine', 'megurine', 'hatsune'];
        $text = str_replace($search, $replace, $text);
        return preg_replace('/(?<![\p{L}\p{N}])[xX](?![\p{L}\p{N}])/u', ' ', $text);
    }
}
if (!function_exists('meel_japanese_transliterate_slug')) {
    function meel_japanese_transliterate_slug(string $text): string
    {
        $rule = "Katakana-Latin; Any-Latin; NFD; [:Nonspacing Mark:] Remove; NFC; Latin-ASCII; Any-Lower;";
        $transliterator = Transliterator::create($rule);
        if ($transliterator) {
            $text = (string) $transliterator->transliterate($text);
        }
        $clean = preg_replace('/[^a-z0-9\-]/u', '-', $text);
        $clean = preg_replace('/-+/', '-', trim((string) $clean, '-'));
        return (string) $clean;
    }
}
if (!function_exists('meel_japanese_is_ascii_only')) {
    function meel_japanese_is_ascii_only(string $text): bool
    {
        return preg_match('/^[\x20-\x7E]*$/', $text) === 1;
    }
}
if (!function_exists('meel_japanese_log_mecab_failure')) {
    function meel_japanese_log_mecab_failure(): void
    {
        static $logged = false;
        if ($logged) return;
        $logged = true;
        error_log('[japanese.php] mecab tidak bisa dijalankan (' . getMecabPath() . ') — gloss/romaji memakai fallback tanpa analisis morfologi');
    }
}
if (!function_exists('meel_japanese_clean_gloss')) {
    function meel_japanese_clean_gloss(string $raw): string
    {
        $first = explode(';', $raw)[0] ?? '';
        return trim((string) preg_replace('/\s*\([^)]*\)/u', '', $first));
    }
}
if (!function_exists('meel_japanese_gloss_is_usable')) {
    function meel_japanese_gloss_is_usable(string $gloss): bool
    {
        return mb_strlen($gloss, 'UTF-8') >= 3 && preg_match('/^[a-z]{1,2}$/i', $gloss) !== 1;
    }
}
if (!function_exists('meel_japanese_katakana_parts')) {
    /**
     * @param callable(string):?string $lookup
     * @return string[] daftar gloss, kosong bila tidak ada segmentasi yang yakin
     */
    function meel_japanese_katakana_parts(string $word, callable $lookup): array
    {
        $length = mb_strlen($word, 'UTF-8');
        $min_part = 3;
        if ($length < $min_part * 2) return [];
        for ($cut = $min_part; $cut <= $length - $min_part; $cut++) {
            $left = $lookup(mb_substr($word, 0, $cut));
            if ($left === null || !meel_japanese_gloss_is_usable($left)) continue;
            $right = $lookup(mb_substr($word, $cut));
            if ($right === null || !meel_japanese_gloss_is_usable($right)) continue;
            return [$left, $right];
        }
        return [];
    }
}
if (!function_exists('meel_japanese_decompose_katakana')) {
    /**
     * @param callable(string):?string $lookup
     * @return string[]
     */
    function meel_japanese_decompose_katakana(string $word, callable $lookup): array
    {
        $segments = preg_split('/[・･]/u', $word, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($segments === []) return [];
        $glosses = [];
        foreach ($segments as $segment) {
            $segment = trim($segment);
            if ($segment === '') continue;
            $whole = $lookup($segment);
            if ($whole !== null && meel_japanese_gloss_is_usable($whole)) {
                $glosses[] = $whole;
                continue;
            }
            $parts = meel_japanese_katakana_parts($segment, $lookup);
            if ($parts === []) return [];
            $glosses = array_merge($glosses, $parts);
        }
        $glosses = array_values(array_unique($glosses));
        if (count($glosses) < 2 || count($glosses) > 4) return [];
        return $glosses;
    }
}
if (!function_exists('getRomajiName')) {
    function getRomajiName(string $text): string
    {
        if (empty($text)) return 'untitled';
        static $memo = [];
        $memo_key = $text;
        if (isset($memo[$memo_key])) return $memo[$memo_key];
        if (count($memo) > 64) $memo = [];
        $text = Normalizer::normalize($text, Normalizer::FORM_C) ?: $text;
        $original_text = $text;
        $text = meel_japanese_normalize_separators($text);
        if (meel_japanese_is_ascii_only($text)) {
            $slug = meel_japanese_transliterate_slug($text);
            if ($slug === '') $slug = meel_japanese_transliterate_slug($original_text);
            return $memo[$memo_key] = ($slug !== '' ? $slug : 'untitled');
        }
        $mecab_bin = getMecabPath();
        $descriptorspec = [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["file", "/dev/null", "w"]];
        $mecab_cmd = 'export LD_LIBRARY_PATH=\'\'; ' . escapeshellarg($mecab_bin);
        $process = proc_open($mecab_cmd, $descriptorspec, $pipes);
        $parsedText = '';
        if (is_resource($process)) {
            fwrite($pipes[0], $text);
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            proc_close($process);
            foreach (explode("\n", trim($output)) as $line) {
                if ($line === 'EOS' || trim($line) === '') continue;
                $parts = explode("\t", $line);
                if (count($parts) >= 2) {
                    $surface = $parts[0];
                    $features = explode(',', $parts[1]);
                    $yomi = '*';
                    if (isset($features[7]) && $features[7] !== '*') $yomi = $features[7];
                    elseif (isset($features[8]) && $features[8] !== '*') $yomi = $features[8];
                    $parsedText .= ' ' . (($yomi !== '*' && !preg_match('/[a-zA-Z]/', $yomi)) ? $yomi : $surface);
                }
            }
            $text = trim($parsedText);
        } else {
            meel_japanese_log_mecab_failure();
        }
        $clean = meel_japanese_transliterate_slug($text);
        if ($clean === '') {
            $clean = meel_japanese_transliterate_slug($original_text);
        }
        return $memo[$memo_key] = ($clean !== '' ? $clean : 'untitled');
    }
}
if (!function_exists('analyzeJapaneseText')) {
    function analyzeJapaneseText(string $text): array
    {
        $result = ['romaji' => 'untitled-media', 'english' => ''];
        if (empty(trim($text))) return $result;
        static $memo = [];
        $memo_key = $text;
        if (isset($memo[$memo_key])) return $memo[$memo_key];
        if (count($memo) > 64) $memo = [];
        $text = Normalizer::normalize($text, Normalizer::FORM_C) ?: $text;
        $original_text = $text;
        $clean_text = meel_japanese_normalize_separators($text);
        if (meel_japanese_is_ascii_only($text)) {
            $slug = meel_japanese_transliterate_slug($clean_text);
            if ($slug === '') $slug = meel_japanese_transliterate_slug($original_text);
            $result['romaji'] = $slug !== '' ? $slug : 'untitled';
            return $memo[$memo_key] = $result;
        }
        static $aliases = null;
        if ($aliases === null) {
            $alias_path = __DIR__ . '/japanese_aliases.php';
            $aliases = file_exists($alias_path) ? require $alias_path : [];
            uksort($aliases, fn($a, $b) => mb_strlen($b) - mb_strlen($a));
        }
        static $gloss_overrides = null;
        if ($gloss_overrides === null) {
            $override_path = __DIR__ . '/japanese_gloss_overrides.php';
            $gloss_overrides = file_exists($override_path) ? require $override_path : [];
        }
        $alias_glosses = [];
        $full_cover = null;
        foreach ($aliases as $phrase => $translation) {
            if ($phrase !== '' && mb_strpos($original_text, $phrase) !== false) {
                $alias_glosses[$phrase] = $translation;
                if (trim($phrase) === trim($original_text)) {
                    $full_cover = $translation;
                }
            }
        }
        foreach (array_keys($alias_glosses) as $p1) {
            foreach (array_keys($alias_glosses) as $p2) {
                if ($p1 !== $p2 && mb_strlen($p2) > mb_strlen($p1) && mb_strpos($p2, $p1) !== false) {
                    unset($alias_glosses[$p1]);
                    break;
                }
            }
        }
        $matched_phrases = array_keys($alias_glosses);
        $alias_glosses = array_values($alias_glosses);
        $mecab_bin = getMecabPath();
        $descriptorspec = [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["file", "/dev/null", "w"]];
        $mecab_cmd = 'export LD_LIBRARY_PATH=\'\'; ' . escapeshellarg($mecab_bin);
        $process = proc_open($mecab_cmd, $descriptorspec, $pipes);
        if (!is_resource($process)) {
            meel_japanese_log_mecab_failure();
            $result['romaji'] = getRomajiName($text);
            $result['english'] = trim(implode(' ', array_unique($alias_glosses)));
            return $result;
        }
        fwrite($pipes[0], $clean_text);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($process);
        static $pdo = null, $dict_ready = null, $dict_stmt = null;
        if ($dict_ready === null) {
            $dict_path = __DIR__ . '/../../assets/dict/jmdict.sqlite3';
            if (file_exists($dict_path)) {
                try {
                    $pdo = new PDO('sqlite:' . $dict_path);
                    $schema_version = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
                    $dict_stmt = $pdo->prepare(
                        $schema_version >= 2
                            ? "SELECT e.glosses FROM keys k JOIN entries e ON e.id = k.entry_id"
                                . " WHERE k.reading = :w ORDER BY k.pos LIMIT 1"
                            : "SELECT glosses FROM entries WHERE reading = :w ORDER BY rowid LIMIT 1"
                    );
                    $dict_ready = true;
                } catch (RuntimeException $e) {
                    $dict_ready = false;
                    error_log('[japanese.php] Gagal buka jmdict.sqlite3: ' . $e->getMessage());
                }
            } else {
                $dict_ready = false;
                error_log('[japanese.php] Dictionary tidak ditemukan di: ' . $dict_path);
            }
        }
        $dict_lookup = function (string $word) use ($dict_ready, $dict_stmt): ?string {
            if (!$dict_ready || $dict_stmt === null || $word === '' || $word === '*') return null;
            $dict_stmt->execute([':w' => $word]);
            $row = $dict_stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row || empty($row['glosses'])) return null;
            $gloss = meel_japanese_clean_gloss((string) $row['glosses']);
            return $gloss !== '' ? $gloss : null;
        };
        $parsed_romaji = '';
        $glosses = [];
        foreach (explode("\n", trim($output)) as $line) {
            if ($line === 'EOS' || trim($line) === '') continue;
            $parts = explode("\t", $line);
            if (count($parts) < 2) continue;
            $surface = $parts[0];
            $features = explode(',', $parts[1]);
            $yomi = '*';
            if (isset($features[7]) && $features[7] !== '*') $yomi = $features[7];
            elseif (isset($features[8]) && $features[8] !== '*') $yomi = $features[8];
            $parsed_romaji .= ' ' . (($yomi !== '*' && !preg_match('/[a-zA-Z]/', $yomi)) ? $yomi : $surface);
            $pos = $features[0] ?? '';
            $sub = $features[1] ?? '';
            $base_form = $features[6] ?? '*';
            $is_functional = in_array($pos, ['助詞', '助動詞', '接続詞', '感動詞', '連体詞', '記号', '補助記号', '接頭詞'], true)
                || ($pos === '名詞' && $sub === '非自立')
                || ($pos === '名詞' && $sub === '接頭')
                || ($pos === '動詞' && $sub === '接尾')
                || ($pos === '名詞' && $sub === '接尾' && ($features[2] ?? '') === '形容動詞語幹');
            $is_single_kana = mb_strlen($surface, 'UTF-8') === 1
                && preg_match('/^[\x{3040}-\x{30FF}]$/u', $surface) === 1;
            $inside_alias = false;
            foreach ($matched_phrases as $phrase) {
                if ($phrase !== '' && mb_strpos($phrase, $surface) !== false) {
                    $inside_alias = true;
                    break;
                }
            }
            if ($is_functional || $inside_alias || $is_single_kana) continue;
            $override = $gloss_overrides[$surface] ?? $gloss_overrides[$base_form] ?? null;
            if ($override !== null) {
                $glosses[] = $override;
                continue;
            }
            $is_katakana = preg_match('/^[\x{30A0}-\x{30FF}・]+$/u', $surface) === 1;
            $is_proper_noun = $pos === '名詞' && $sub === '固有名詞';
            if ($is_proper_noun) {
                $is_loanword = $is_katakana && mb_strlen($surface, 'UTF-8') >= 4;
                $is_proper_noun = !$is_loanword;
            }
            $gloss_added = false;
            if (!$is_proper_noun) {
                foreach (array_unique([$surface, $base_form]) as $candidate) {
                    $gloss = $dict_lookup((string) $candidate);
                    if ($gloss !== null) {
                        $glosses[] = $gloss;
                        $gloss_added = true;
                        break;
                    }
                }
            }
            if (!$gloss_added && $is_katakana && $pos === '名詞' && mb_strlen($surface, 'UTF-8') >= 4) {
                foreach (meel_japanese_decompose_katakana($surface, $dict_lookup) as $gloss) {
                    $glosses[] = $gloss;
                }
            }
        }
        $romaji_text = trim($parsed_romaji);
        $rule = "Katakana-Latin; Any-Latin; NFD; [:Nonspacing Mark:] Remove; NFC; Latin-ASCII; Any-Lower;";
        $transliterator = Transliterator::create($rule);
        if ($transliterator) $romaji_text = $transliterator->transliterate($romaji_text);
        $clean = preg_replace('/[^a-z0-9\-]/u', '-', $romaji_text);
        $clean = preg_replace('/-+/', '-', trim($clean, '-'));
        if (empty($clean)) {
            $fallback = preg_replace('/[^a-z0-9\-]/u', '-', $original_text);
            $fallback = preg_replace('/-+/', '-', trim($fallback, '-'));
            $result['romaji'] = $fallback ?: 'untitled';
        } else {
            $result['romaji'] = $clean;
        }
        if ($full_cover !== null) {
            $result['english'] = $full_cover;
        } else {
            $glosses = array_merge($alias_glosses, $glosses);
            $result['english'] = trim(implode(' ', array_unique($glosses)));
        }
        return $result;
    }
}
/* reference build: MEeL-C9H11NO2 [923f17227df4569f] */
