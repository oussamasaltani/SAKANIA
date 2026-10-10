<?php

declare(strict_types=1);

namespace Boosthis;

/**
 * HTTP transport. Every byte the kit sends leaves through here, and every send
 * passes the no-PII guard FIRST — payload body, then URL query — in the same
 * order as the other runtimes.
 *
 * Best effort by contract: a failed send is a dropped measurement, never an
 * error in the host request. There is no retry storm — one attempt, a short
 * bounded timeout, and the caller decides whether to keep the batch for later.
 */
final class Transmit
{
    /** @var callable(string,string,array<string,string>,int):array{status:int,body:string}|null */
    private static $sender = null;

    /** @var callable(string,string,?string,int):bool|null */
    private static $noWaitSender = null;

    private function __construct()
    {
    }

    public static function userAgent(): string
    {
        return 'boosthis-php/' . Thresholds::RUNTIME_VERSION;
    }

    /**
     * Refuse a URL whose query string carries anything PII-shaped. The path is
     * checked too: an install id is a UUID and is allowed there by design, so
     * only query VALUES go through the value guard.
     */
    public static function assertNoPiiInUrl(string $url): void
    {
        $q = parse_url($url, PHP_URL_QUERY);
        if (!is_string($q) || $q === '') {
            return;
        }
        parse_str($q, $params);
        foreach ($params as $key => $value) {
            $keyStr = (string) $key;
            // installId is the one identifier the API takes by query; it is an
            // opaque UUID minted by the kit, never a user identifier.
            if ($keyStr === 'installId') {
                continue;
            }
            $denied = Pii::findDeniedFragment($keyStr);
            if ($denied !== null) {
                throw new PiiDetectedException('url.' . $keyStr, $keyStr, $denied);
            }
            if (is_string($value)) {
                $deniedValue = Pii::findDeniedValue($value);
                if ($deniedValue !== null) {
                    throw new PiiDetectedException('url.' . $keyStr, $keyStr, $deniedValue);
                }
            }
        }
    }

    /**
     * Guarded POST: assert no PII in the payload and the URL, honour
     * BOOSTHIS_DISABLED, then send.
     *
     * @param array<string,mixed> $payload
     * @return array{status:int,body:array<mixed>|null}
     */
    public static function safeTransmit(
        string $url,
        array $payload,
        ?string $authToken = null,
        int $timeoutMs = 5000
    ): array {
        Pii::assertNoPii($payload);
        self::assertNoPiiInUrl($url);
        if (RuntimeFlags::disabled()) {
            return ['status' => 204, 'body' => null];
        }

        return self::post($url, $payload, $authToken, $timeoutMs);
    }

    /**
     * Post a payload whose body legitimately contains a server-issued
     * credential field (the deleteToken on POST /installs/forget) that the
     * field-name guard would otherwise reject. It SKIPS ONLY the payload-body
     * check while still scanning the URL query and honouring BOOSTHIS_DISABLED.
     * The payload shape is fixed and carries no user data.
     *
     * @param array<string,mixed> $payload
     * @return array{status:int,body:array<mixed>|null}
     */
    public static function safeTransmitTrustedPayload(
        string $url,
        array $payload,
        ?string $authToken = null,
        int $timeoutMs = 5000
    ): array {
        self::assertNoPiiInUrl($url);
        if (RuntimeFlags::disabled()) {
            return ['status' => 204, 'body' => null];
        }

        return self::post($url, $payload, $authToken, $timeoutMs);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{status:int,body:array<mixed>|null}
     */
    private static function post(
        string $url,
        array $payload,
        ?string $authToken,
        int $timeoutMs
    ): array {
        $body = Json::encode($payload);
        if ($body === null) {
            return ['status' => 0, 'body' => null];
        }
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => self::userAgent(),
        ];
        if ($authToken !== null && $authToken !== '') {
            $headers['Authorization'] = 'Bearer ' . $authToken;
        }
        $res = self::send('POST', $url, $body, $headers, $timeoutMs);

        return ['status' => $res['status'], 'body' => Json::decode($res['body'])];
    }

    /**
     * Guarded GET used by the live-read helpers.
     *
     * @param array<string,string> $query
     * @return array{status:int,body:array<mixed>|null}
     */
    public static function safeGet(
        string $url,
        array $query = [],
        ?string $authToken = null,
        int $timeoutMs = 5000
    ): array {
        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }
        self::assertNoPiiInUrl($url);
        if (RuntimeFlags::disabled()) {
            return ['status' => 204, 'body' => null];
        }
        $headers = [
            'Accept' => 'application/json',
            'User-Agent' => self::userAgent(),
        ];
        if ($authToken !== null && $authToken !== '') {
            $headers['Authorization'] = 'Bearer ' . $authToken;
        }
        $res = self::send('GET', $url, null, $headers, $timeoutMs);

        return ['status' => $res['status'], 'body' => Json::decode($res['body'])];
    }

    /**
     * @param array<string,string> $headers
     * @return array{status:int,body:string}
     */
    private static function send(
        string $method,
        string $url,
        ?string $body,
        array $headers,
        int $timeoutMs
    ): array {
        if (self::$sender !== null) {
            return (self::$sender)($method, $url, $headers, $timeoutMs, $body);
        }

        return Safe::run(
            static function () use ($method, $url, $body, $headers, $timeoutMs): array {
                if (function_exists('curl_init')) {
                    return self::sendCurl($method, $url, $body, $headers, $timeoutMs);
                }

                return self::sendStream($method, $url, $body, $headers, $timeoutMs);
            },
            ['status' => 0, 'body' => '']
        );
    }

    /**
     * @param array<string,string> $headers
     * @return array{status:int,body:string}
     */
    private static function sendCurl(
        string $method,
        string $url,
        ?string $body,
        array $headers,
        int $timeoutMs
    ): array {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['status' => 0, 'body' => ''];
        }
        $hdr = [];
        foreach ($headers as $k => $v) {
            $hdr[] = $k . ': ' . $v;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $hdr,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => min($timeoutMs, 3000),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_FAILONERROR => false,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $out = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return ['status' => $status, 'body' => is_string($out) ? $out : ''];
    }

    /**
     * @param array<string,string> $headers
     * @return array{status:int,body:string}
     */
    private static function sendStream(
        string $method,
        string $url,
        ?string $body,
        array $headers,
        int $timeoutMs
    ): array {
        $hdr = '';
        foreach ($headers as $k => $v) {
            $hdr .= $k . ': ' . $v . "\r\n";
        }
        $ctx = stream_context_create(['http' => [
            'method' => $method,
            'header' => $hdr,
            'content' => $body ?? '',
            'timeout' => $timeoutMs / 1000,
            'ignore_errors' => true,
            'follow_location' => 0,
        ]]);
        $out = @file_get_contents($url, false, $ctx);
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        return ['status' => $status, 'body' => is_string($out) ? $out : ''];
    }

    /**
     * Send a payload WITHOUT waiting for the answer, and say only whether the
     * bytes went out.
     *
     * This exists for the one PHP case that cannot be solved any other way. On
     * FPM and LiteSpeed the kit hands the page back before it touches the
     * network, so a five-second read costs a visitor nothing. On every other
     * SAPI — mod_php, the built-in server, a plain CLI script — the shutdown
     * phase still holds the connection, and an ingest host that is merely SLOW
     * (not down: down is instant) would be added straight onto the customer's
     * page time. The reporting channel is not worth one second of anybody's
     * page, let alone ten.
     *
     * So: connect with a short bound, write the request, close. The reply is
     * never read, which is why the return value is `true` for "written", not
     * for "accepted" — the caller has to record it as the weaker thing it is.
     * Both reporting endpoints upsert by signature, so an unconfirmed send that
     * did land is not a duplicate, and one that did not is retried by the next
     * poll.
     *
     * @param array<string,mixed> $payload
     */
    public static function postNoWait(
        string $url,
        array $payload,
        ?string $authToken = null,
        int $connectTimeoutMs = 400
    ): bool {
        // The same guards as a waiting send, in the same order. A channel that
        // skipped them because it is fast would be a hole in the PII contract.
        Pii::assertNoPii($payload);
        self::assertNoPiiInUrl($url);
        if (RuntimeFlags::disabled()) {
            return false;
        }
        $body = Json::encode($payload);
        if ($body === null) {
            return false;
        }
        if (self::$noWaitSender !== null) {
            return (bool) (self::$noWaitSender)($url, $body, $authToken, $connectTimeoutMs);
        }

        return (bool) Safe::run(
            static function () use ($url, $body, $authToken, $connectTimeoutMs): bool {
                $parts = parse_url($url);
                $host = is_array($parts) ? ($parts['host'] ?? null) : null;
                if (!is_string($host) || $host === '') {
                    return false;
                }
                $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
                $secure = $scheme === 'https';
                $port = (int) ($parts['port'] ?? ($secure ? 443 : 80));
                $path = (string) ($parts['path'] ?? '/');
                if (isset($parts['query']) && $parts['query'] !== '') {
                    $path .= '?' . $parts['query'];
                }
                $target = ($secure ? 'tls://' : 'tcp://') . $host . ':' . $port;

                $errno = 0;
                $error = '';
                $socket = @stream_socket_client(
                    $target,
                    $errno,
                    $error,
                    max(0.05, $connectTimeoutMs / 1000),
                    STREAM_CLIENT_CONNECT
                );
                if (!is_resource($socket)) {
                    return false;
                }
                // A write must not wait either: a receiver that never drains
                // its socket would otherwise stall here instead of in the read.
                @stream_set_timeout($socket, 0, $connectTimeoutMs * 1000);
                $head = 'POST ' . $path . " HTTP/1.1\r\n"
                    . 'Host: ' . $host . ($port === ($secure ? 443 : 80) ? '' : ':' . $port) . "\r\n"
                    . "Content-Type: application/json\r\n"
                    . 'Content-Length: ' . strlen($body) . "\r\n"
                    . 'User-Agent: ' . self::userAgent() . "\r\n"
                    . ($authToken !== null && $authToken !== ''
                        ? 'Authorization: Bearer ' . $authToken . "\r\n"
                        : '')
                    . "Connection: close\r\n\r\n";
                $written = @fwrite($socket, $head . $body);
                // fclose flushes what PHP still holds to the kernel, which owns
                // delivery from here. Nothing below this line waits.
                @fclose($socket);

                return is_int($written) && $written > 0;
            },
            false
        );
    }

    /**
     * Test seam: swap the wire for a recorder.
     *
     * @param callable(string,string,array<string,string>,int,?string):array{status:int,body:string}|null $fn
     */
    public static function setSenderForTests(?callable $fn): void
    {
        self::$sender = $fn;
    }

    /**
     * Test seam for the no-wait channel. Separate from the sender above on
     * purpose: a test that swaps one and not the other should FAIL loudly
     * rather than quietly measure the wrong path.
     *
     * @param callable(string,string,?string,int):bool|null $fn
     */
    public static function setNoWaitSenderForTests(?callable $fn): void
    {
        self::$noWaitSender = $fn;
    }
}
