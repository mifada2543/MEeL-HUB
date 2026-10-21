<?php
/**
 * Aturan protokol untuk proxy validasi: parsing kepala permintaan,
 * penyusunan permintaan yang diteruskan, dan pemindahan data antar soket.
 * Dipisah dari `validating_proxy_server.php` supaya bisa diuji tanpa
 * menjalankan server.
 *
 * @see docs/{id,en}/security.md
 */

const PROXY_IDLE_TIMEOUT = 300;
const PROXY_MAX_LIFETIME = 3600;
const PROXY_CHUNK = 65536;
const PROXY_MAX_HEADERS = 100;
const PROXY_MAX_HEADER_LINE = 8192;

const TUNNEL_FORWARDED = 0;
const TUNNEL_IDLE = 1;
const TUNNEL_EOF = 2;

/**
 * Header yang tidak boleh diteruskan ke target: milik hop-to-hop.
 */
const PROXY_HOP_BY_HOP = '/^(host|connection|proxy-connection|keep-alive|te|upgrade|transfer-encoding):/i';

/**
 * Urai kepala permintaan HTTP menjadi method, target, versi, dan header.
 *
 * Mengembalikan null kalau kepala itu tidak layak diteruskan: jumlah
 * header kebanyakan, ada baris header yang terlalu panjang, ada karakter
 * CR/LF/NUL yang menyamar, atau baris request-nya tidak punya tiga bagian.
 *
 * @return ?array{0:string, 1:string, 2:string, 3:list<string>}
 */
function parseRequestHead(string $head): ?array
{
    $lines = explode("\r\n", $head);
    $request = array_shift($lines);

    if (count($lines) > PROXY_MAX_HEADERS) {
        return null;
    }
    if (preg_match('/[\r\n\x00]/', $request)) {
        return null;
    }
    foreach ($lines as $line) {
        if (strlen($line) > PROXY_MAX_HEADER_LINE || preg_match('/[\r\n\x00]/', $line)) {
            return null;
        }
    }

    $parts = preg_split('/\s+/', trim($request));
    if (count($parts) < 3) {
        return null;
    }

    return [$parts[0], $parts[1], $parts[2], $lines];
}

/**
 * Pecah target `host:port` — Address Form dipakai oleh CONNECT.
 *
 * @return array{0:string, 1:int}
 */
function parseTarget(string $target, int $defaultPort): array
{
    $target = trim($target);
    if (str_starts_with($target, '[')) {
        $end = strpos($target, ']');
        if ($end === false) {
            return ['', $defaultPort];
        }
        $host = substr($target, 1, $end - 1);
        $rest = substr($target, $end + 1);
        $port = (int) ltrim($rest, ':');
        if ($port <= 0) {
            $port = $defaultPort;
        }
        return [$host, $port];
    }

    $parts = explode(':', $target);
    $host = $parts[0];
    $port = isset($parts[1]) && is_numeric($parts[1]) ? (int) $parts[1] : $defaultPort;
    if ($port <= 0 || $port > 65535) {
        $port = $defaultPort;
    }
    return [$host, $port];
}

/**
 * Susun permintaan yang diteruskan ke target.
 *
 * Header hop-to-hop dibuang — Proxy harus mengendalikan sendiri, bukan meneruskan
 * `Connection` milik klien — lalu `Host` dan `Connection: close` ditulis
 * ulang.
 */
function buildForwardedRequest(
    string $method,
    array $parts,
    string $host,
    int $port,
    string $version,
    array $headerLines
): string {
    $path = ($parts['path'] ?? '');
    if ($path === '') {
        $path = '/';
    }
    if (isset($parts['query']) && $parts['query'] !== '') {
        $path .= '?' . $parts['query'];
    }

    $out = "$method $path $version\r\n";
    foreach ($headerLines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') {
            continue;
        }
        if (preg_match(PROXY_HOP_BY_HOP, $trimmed)) {
            continue;
        }
        $out .= $trimmed . "\r\n";
    }
    $out .= "Host: $host:$port\r\n";
    $out .= "Connection: close\r\n\r\n";

    return $out;
}

/**
 * Pindahkan satu potongan data dari satu soket ke soket lain.
 *
 * Mengembalikan TUNNEL_EOF kalau sisi sumber sudah selesai, TUNNEL_IDLE
 * kalau belum ada data yang tersedia, dan TUNNEL_FORWARDED kalau data sudah
 * diteruskan.
 */
function forwardTunnelChunk($socket, $target): int
{
    $data = @fread($socket, PROXY_CHUNK);
    if ($data === false) {
        return TUNNEL_EOF;
    }
    if ($data === '') {
        return feof($socket) ? TUNNEL_EOF : TUNNEL_IDLE;
    }
    @fwrite($target, $data);
    return TUNNEL_FORWARDED;
}

/**
 * Teruskan respons target ke klien sampai selesai atau lewat tenggat.
 */
function relayResponse($upstream, $client): void
{
    $relayDeadline = microtime(true) + PROXY_IDLE_TIMEOUT;
    while (!feof($upstream)) {
        if (microtime(true) > $relayDeadline) {
            break;
        }
        $chunk = @fread($upstream, PROXY_CHUNK);
        if ($chunk === false) {
            break;
        }
        if ($chunk === '') {
            $meta = stream_get_meta_data($upstream);
            if (!empty($meta['timed_out'])) {
                break;
            }
            continue;
        }
        if (@fwrite($client, $chunk) === false) {
            break;
        }
    }
}

/* reference build: MEeL-C6H24N11O [validating-proxy-protocol] */
