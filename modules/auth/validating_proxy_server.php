<?php
if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/SsrfGuard.php';
require_once __DIR__ . '/ValidatingProxyProtocol.php';

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');

$server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "ERR bind: $errstr\n");
    exit(1);
}
$name = stream_socket_get_name($server, false);
$port = (int) substr(strrchr($name, ':'), 1);

fwrite(STDOUT, "PORT $port\nREADY\n");
fflush(STDOUT);

$guard = new SsrfGuard();

if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGCHLD, static function (): void {
        pcntl_waitpid(-1, $status, WNOHANG);
    });
}

function readRequestHead($client): ?array
{
    $head = '';
    $deadline = microtime(true) + 30;
    while (strpos($head, "\r\n\r\n") === false) {
        $chunk = @fread($client, 8192);
        if ($chunk === false || ($chunk === '' && feof($client))) {
            return null;
        }
        if ($chunk !== '') {
            $head .= $chunk;
            if (strlen($head) > 65536) {
                return null;
            }
        }
        if (microtime(true) > $deadline) {
            return null;
        }

        usleep(2000);
    }

    return parseRequestHead($head);
}

function connectValidated(SsrfGuard $guard, string $host, int $port)
{
    if ($host === '') {
        throw new RuntimeException('target kosong');
    }

    $addresses = $guard->resolvePublicAddresses($host);
    if ($addresses === []) {
        throw new RuntimeException('tidak ada alamat publik');
    }

    $ip = $addresses[0];
    $url = (strpos($ip, ':') !== false) ? "tcp://[$ip]:$port" : "tcp://$ip:$port";

    $errno = 0;
    $errstr = '';
    $upstream = @stream_socket_client($url, $errno, $errstr, 15);
    if ($upstream === false) {
        throw new RuntimeException('koneksi ke target gagal');
    }
    stream_set_timeout($upstream, PROXY_IDLE_TIMEOUT);
    return $upstream;
}

function refuse($client, string $reason): void
{
    @fwrite($client, "HTTP/1.1 502 Bad Gateway\r\nConnection: close\r\nContent-Type: text/plain\r\n\r\nPROXY REFUSED: $reason");
    @fclose($client);
}

function tunnel($client, $upstream): void
{
    $clientEof = false;
    $upEof = false;

    while (!($clientEof && $upEof)) {
        $read = [];
        if (!$clientEof) {
            $read[] = $client;
        }
        if (!$upEof) {
            $read[] = $upstream;
        }
        if ($read === []) {
            break;
        }
        $write = null;
        $except = null;
        $selected = @stream_select($read, $write, $except, PROXY_IDLE_TIMEOUT);
        if ($selected === false || $selected === 0) {
            break;
        }

        foreach ($read as $socket) {
            $status = forwardTunnelChunk($socket, $socket === $client ? $upstream : $client);
            if ($status === TUNNEL_FORWARDED || $status === TUNNEL_IDLE) {
                continue;
            }
            if ($socket === $client) {
                $clientEof = true;
                @stream_socket_shutdown($upstream, STREAM_SHUT_WR);
            } else {
                $upEof = true;
                @stream_socket_shutdown($client, STREAM_SHUT_WR);
            }
        }
    }

    @fclose($client);
    @fclose($upstream);
}

function relayHttp(SsrfGuard $guard, $client, string $method, string $target, string $version, array $headerLines): void
{
    $parts = parse_url($target);
    if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
        refuse($client, 'URL target tidak valid');
        return;
    }
    $scheme = strtolower((string) $parts['scheme']);
    if ($scheme !== 'http') {
        refuse($client, 'skema tidak didukung');
        return;
    }

    $host = strtolower(rtrim((string) $parts['host'], '.'));
    $port = isset($parts['port']) ? (int) $parts['port'] : 80;
    if ($port <= 0 || $port > 65535) {
        $port = 80;
    }

    try {
        $upstream = connectValidated($guard, $host, $port);
    } catch (RuntimeException $e) {
        refuse($client, 'target tidak diizinkan atau tidak terjangkau');
        return;
    }

    @fwrite($upstream, buildForwardedRequest($method, $parts, $host, $port, $version, $headerLines));
    relayResponse($upstream, $client);

    @fclose($upstream);
    @fclose($client);
}

function handleClient($client, SsrfGuard $guard): void
{
    stream_set_timeout($client, PROXY_IDLE_TIMEOUT);

    $head = readRequestHead($client);
    if ($head === null) {
        @fclose($client);
        return;
    }
    [$method, $target, $version, $headerLines] = $head;
    $method = strtoupper($method);

    if ($method === 'CONNECT') {
        [$host, $port] = parseTarget($target, 443);
        try {
            $upstream = connectValidated($guard, $host, $port);
        } catch (RuntimeException $e) {
            refuse($client, 'target tidak diizinkan atau tidak terjangkau');
            return;
        }
        @fwrite($client, "HTTP/1.1 200 Connection Established\r\n\r\n");
        tunnel($client, $upstream);
        return;
    }

    relayHttp($guard, $client, $method, $target, $version, $headerLines);
}

$startedAt = time();
while (time() - $startedAt < PROXY_MAX_LIFETIME) {
    $client = @stream_socket_accept($server, 300);
    if ($client === false) {
        if (function_exists('pcntl_waitpid')) {
            pcntl_waitpid(-1, $status, WNOHANG);
        }
        continue;
    }

    if (function_exists('pcntl_fork')) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            @fclose($client);
            continue;
        }
        if ($pid === 0) {
            fclose($server);
            handleClient($client, $guard);
            exit(0);
        }

        fclose($client);
        pcntl_waitpid(-1, $status, WNOHANG);
        continue;
    }

    handleClient($client, $guard);
}

fclose($server);
exit(0);

/* reference build: MEeL-C5H5N5O [422fe9a962b88ae7] */
