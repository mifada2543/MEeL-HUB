<?php
use PHPUnit\Framework\TestCase;

require_once MEEL_ROOT . '/modules/auth/ValidatingProxyProtocol.php';

/**
 * Aturan protokol proxy validasi.
 *
 * DULU
 * ----
 * Semua aturan ini berada di `validating_proxy_server.php` — berkas yang
 * top-level-nya langsung menjalankan server._artinya berkas itu tidak bisa
 * di-include tanpa menjalankan proxy, jadi tidak ada satu pun aturan
 * protokolnya yang punya test.
 *
 * SEKARANG
 * --------
 * Aturannya dipisah ke `modules/auth/ValidatingProxyProtocol.php`:
 * parsing kepala permintaan, parsing target, penyusunan permintaan yang
 * diteruskan, dan pemindahan data antar soket. Semuanya bisa diuji tanpa
 * server dan tanpa jaringan.
 *
 * @coversNothing
 */
class ValidatingProxyProtocolTest extends TestCase
{
    // ---------- parseRequestHead ----------

    public function testValidRequestHeadIsParsed(): void
    {
        $head = "GET http://example.com/ HTTP/1.1\r\nHost: example.com\r\nAccept: */*\r\n\r\n";

        $parsed = parseRequestHead($head);

        $this->assertNotNull($parsed);
        $this->assertSame('GET', $parsed[0]);
        $this->assertSame('http://example.com/', $parsed[1]);
        $this->assertSame('HTTP/1.1', $parsed[2]);
        $this->assertSame(
            ['Host: example.com', 'Accept: */*', '', ''],
            $parsed[3],
            'Kepala yang diakhiri \r\n\r\n menyisakan dua baris kosong — itu perilaku asli'
        );
    }

    public function testRequestLineWithExtraWhitespaceIsSplit(): void
    {
        $parsed = parseRequestHead("GET   http://example.com/   HTTP/1.1\r\n\r\n");

        $this->assertNotNull($parsed);
        $this->assertSame('GET', $parsed[0]);
        $this->assertSame('http://example.com/', $parsed[1]);
        $this->assertSame('HTTP/1.1', $parsed[2]);
    }

    public function testRequestLineWithFewerThanThreePartsIsRejected(): void
    {
        $this->assertNull(parseRequestHead("GET /\r\n\r\n"));
        $this->assertNull(parseRequestHead("GET\r\n\r\n"));
    }

    public function testNullByteInRequestLineIsRejected(): void
    {
        $this->assertNull(parseRequestHead("GET http://exa\x00mple.com/ HTTP/1.1\r\n\r\n"));
    }

    public function testNullByteInHeaderIsRejected(): void
    {
        $this->assertNull(parseRequestHead("GET http://example.com/ HTTP/1.1\r\nHost: exa\x00mple\r\n\r\n"));
    }

    public function testTooManyHeadersIsRejected(): void
    {
        // Kepala selalu diakhiri \r\n\r\n, jadi dua baris kosong ikut
        // terhitung dalam batas.
        $atLimit = PROXY_MAX_HEADERS - 2;
        $ok = "GET http://example.com/ HTTP/1.1\r\n" . str_repeat("X-H: v\r\n", $atLimit) . "\r\n";
        $tooMany = "GET http://example.com/ HTTP/1.1\r\n" . str_repeat("X-H: v\r\n", $atLimit + 1) . "\r\n";

        $this->assertNotNull(parseRequestHead($ok), 'Tepat batas jumlah header harus diterima');
        $this->assertNull(parseRequestHead($tooMany), 'Satu header lebih banyak harus ditolak');
    }

    public function testOverlongHeaderLineIsRejected(): void
    {
        $prefix = 'X-L: ';
        $ok = "GET http://example.com/ HTTP/1.1\r\n" . $prefix . str_repeat('a', PROXY_MAX_HEADER_LINE - strlen($prefix)) . "\r\n\r\n";
        $tooLong = "GET http://example.com/ HTTP/1.1\r\n" . $prefix . str_repeat('a', PROXY_MAX_HEADER_LINE - strlen($prefix) + 1) . "\r\n\r\n";

        $this->assertNotNull(parseRequestHead($ok));
        $this->assertNull(parseRequestHead($tooLong));
    }

    // ---------- parseTarget ----------

    public function testTargetWithExplicitPort(): void
    {
        $this->assertSame(['example.com', 8080], parseTarget('example.com:8080', 443));
    }

    public function testTargetWithoutPortUsesDefault(): void
    {
        $this->assertSame(['example.com', 443], parseTarget('example.com', 443));
    }

    public function testTargetWithInvalidPortUsesDefault(): void
    {
        $this->assertSame(['example.com', 443], parseTarget('example.com:0', 443));
        $this->assertSame(['example.com', 443], parseTarget('example.com:70000', 443));
        $this->assertSame(['example.com', 443], parseTarget('example.com:abc', 443));
    }

    public function testIpv6TargetInBrackets(): void
    {
        $this->assertSame(['2001:db8::1', 8443], parseTarget('[2001:db8::1]:8443', 443));
        $this->assertSame(['2001:db8::1', 443], parseTarget('[2001:db8::1]', 443));
    }

    public function testUnclosedBracketYieldsEmptyHost(): void
    {
        $this->assertSame(['', 443], parseTarget('[2001:db8::1', 443));
    }

    // ---------- buildForwardedRequest ----------

    public function testForwardedRequestKeepsPathAndQuery(): void
    {
        $parts = parse_url('http://example.com/a/b?x=1&y=2');

        $out = buildForwardedRequest('GET', $parts, 'example.com', 80, 'HTTP/1.1', []);

        $this->assertStringStartsWith("GET /a/b?x=1&y=2 HTTP/1.1\r\n", $out);
        $this->assertStringEndsWith("Host: example.com:80\r\nConnection: close\r\n\r\n", $out);
    }

    public function testForwardedRequestUsesRootPathWhenTargetHasNone(): void
    {
        $parts = parse_url('http://example.com');

        $out = buildForwardedRequest('GET', $parts, 'example.com', 80, 'HTTP/1.1', []);

        $this->assertStringStartsWith("GET / HTTP/1.1\r\n", $out);
    }

    /**
     * Header hop-to-hop milik klien tidak boleh diteruskan — kalau
     * diteruskan, `Connection` milik klien akan mengatur koneksi proxy.
     *
     * @dataProvider hopByHopProvider
     */
    public function testHopByHopHeadersAreNotForwarded(string $header): void
    {
        $parts = parse_url('http://example.com/');

        $out = buildForwardedRequest('GET', $parts, 'example.com', 80, 'HTTP/1.1', [$header, 'X-Keep: yes']);

        $this->assertStringNotContainsString($header . "\r\n", $out);
        $this->assertStringContainsString("X-Keep: yes\r\n", $out);
    }

    public static function hopByHopProvider(): array
    {
        return [
            'Host' => ['Host:evil.example'],
            'Connection' => ['Connection: keep-alive'],
            'Proxy-Connection' => ['Proxy-Connection: keep-alive'],
            'Keep-Alive' => ['Keep-Alive: 300'],
            'Te' => ['Te: trailers'],
            'Upgrade' => ['Upgrade: h2c'],
            'Transfer-Encoding' => ['Transfer-Encoding: chunked'],
        ];
    }

    public function testEmptyHeaderLinesAreSkipped(): void
    {
        $parts = parse_url('http://example.com/');

        $out = buildForwardedRequest('GET', $parts, 'example.com', 80, 'HTTP/1.1', ['', '   ', 'X-A: 1']);

        $this->assertSame(1, substr_count($out, 'X-A: 1'));
        $this->assertStringNotContainsString("\r\n\r\n\r\n", $out);
    }

    public function testForwardedRequestAlwaysSetsItsOwnHostAndConnection(): void
    {
        $parts = parse_url('http://example.com/');

        $out = buildForwardedRequest('GET', $parts, 'example.com', 8080, 'HTTP/1.1', ['Host: spoofed.example']);

        $this->assertStringContainsString("Host: example.com:8080\r\n", $out);
        $this->assertStringNotContainsString('Host: spoofed.example', $out);
        $this->assertSame(1, substr_count($out, 'Host: '));
    }

    // ---------- forwardTunnelChunk ----------

    /**
     * Dua pasang soket: satu untuk sumber (data ditulis ke $srcPeer, dibaca
     * dari $src) dan satu untuk tujuan (data ditulis ke $dst, dibaca dari
     * $dstPeer).
     *
     * @return array{0:resource, 1:resource, 2:resource, 3:resource}
     */
    private function socketQuads(): array
    {
        $source = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $target = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($source);
        $this->assertIsArray($target);
        return [$source[0], $source[1], $target[0], $target[1]];
    }

    public function testTunnelChunkForwardsData(): void
    {
        [$src, $srcPeer, $dst, $dstPeer] = $this->socketQuads();

        fwrite($srcPeer, 'halo');
        $status = forwardTunnelChunk($src, $dst);

        $this->assertSame(TUNNEL_FORWARDED, $status);
        $this->assertSame('halo', fread($dstPeer, 1024));

        fclose($src);
        fclose($srcPeer);
        fclose($dst);
        fclose($dstPeer);
    }

    public function testTunnelChunkReportsIdleWhenNothingIsAvailable(): void
    {
        [$src, $srcPeer, $dst, $dstPeer] = $this->socketQuads();

        // Soket blocking di PHP mengembalikan `false` saat tidak ada data,
        // jadi cabang "kosong tapi belum EOF" hanya terjangkau lewat mode
        // non-blocking.
        stream_set_blocking($src, false);
        $status = forwardTunnelChunk($src, $dst);

        $this->assertSame(TUNNEL_IDLE, $status, 'Soket yang masih terbuka tapi kosong harus IDLE');

        fclose($src);
        fclose($srcPeer);
        fclose($dst);
        fclose($dstPeer);
    }

    public function testTunnelChunkReportsEofWhenSourceIsClosed(): void
    {
        [$src, $srcPeer, $dst, $dstPeer] = $this->socketQuads();

        fclose($srcPeer);
        $status = forwardTunnelChunk($src, $dst);

        $this->assertSame(TUNNEL_EOF, $status);

        fclose($src);
        fclose($dst);
        fclose($dstPeer);
    }

    // ---------- relayResponse ----------

    public function testRelayResponseCopiesUpstreamBodyToClient(): void
    {
        [$upstream, $upstreamPeer, $client, $clientPeer] = $this->socketQuads();

        fwrite($upstreamPeer, "HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\nhello");
        fclose($upstreamPeer);

        relayResponse($upstream, $client);

        $received = fread($clientPeer, 4096);

        $this->assertStringContainsString('HTTP/1.1 200 OK', $received);
        $this->assertStringEndsWith('hello', $received);

        fclose($upstream);
        fclose($client);
    }
}

/* reference build: MEeL-C9H25N12O [validating-proxy-protocol] */
