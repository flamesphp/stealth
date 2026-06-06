<?php
declare(strict_types=1);


namespace Flames\Stealth\Cdp;

use Flames\Stealth\Exception\StealthException;

/**
 * @internal
 */
final class WebSocket
{
    private mixed $socket = null;

    private string $readBuffer = '';

    public function __construct(
        private readonly string $url,
        private readonly float $timeout = 30.0,
        private readonly ?string $hostHeader = null,
    ) {}

    public static function connect(string $url, float $timeout = 5.0): self
    {
        $parts = parse_url($url);
        $port = (int) ($parts['port'] ?? 9222);
        $lastError = null;

        foreach (Endpoint::cdpHostHeaders($port) as $hostHeader) {
            try {
                $socket = new self($url, $timeout, $hostHeader);
                $socket->doConnect();

                return $socket;
            } catch (StealthException $e) {
                $lastError = $e;
            }
        }

        throw $lastError ?? new StealthException('WebSocket connection failed.');
    }

    private function doConnect(): void
    {
        $parts = $this->parseUrl();
        $this->socket = $this->openSocket($parts);
        $this->performHandshake($parts);
    }

    public function send(string $payload): void
    {
        if (!is_resource($this->socket)) {
            throw new StealthException('WebSocket is not connected.');
        }

        if (fwrite($this->socket, $this->buildFrame($payload)) === false) {
            throw new StealthException('WebSocket send failed.');
        }
    }

    public function readFrameNonBlocking(): ?string
    {
        $frame = $this->decodeFrameFromBuffer($this->readBuffer);

        if ($frame !== null) {
            return $frame;
        }

        $read = [$this->socket];
        $write = $except = null;

        if (@stream_select($read, $write, $except, 0, 0) > 0) {
            $chunk = @fread($this->socket, 65_536);
            if ($chunk !== false && $chunk !== '') {
                $this->readBuffer .= $chunk;
            }
        }

        return $this->decodeFrameFromBuffer($this->readBuffer);
    }

    public function receive(int $timeoutSeconds): ?string
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            $frame = $this->decodeFrameFromBuffer($this->readBuffer);

            if ($frame !== null) {
                return $frame;
            }

            $remaining = min($deadline - microtime(true), 0.1);

            if ($remaining <= 0.0) {
                break;
            }

            $this->fillBuffer($remaining);
        }

        return null;
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }

        $this->socket = null;
        $this->readBuffer = '';
    }

    private function parseUrl(): array
    {
        $parts = parse_url($this->url);

        if (!is_array($parts) || empty($parts['host'])) {
            throw new StealthException("Invalid WebSocket URL: {$this->url}");
        }

        return $parts;
    }

    private function openSocket(array $parts): mixed
    {
        $scheme = $parts['scheme'] ?? 'ws';
        $host = $parts['host'];
        $port = $parts['port'] ?? ($scheme === 'wss' ? 443 : 80);
        $transport = $scheme === 'wss' ? 'ssl' : 'tcp';

        $socket = @stream_socket_client(
            "{$transport}://{$host}:{$port}",
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]),
        );

        if ($socket === false) {
            throw new StealthException("WebSocket connection failed ({$errno}): {$errstr}");
        }

        stream_set_timeout($socket, (int) ceil($this->timeout));

        return $socket;
    }

    private function performHandshake(array $parts): void
    {
        $port = $parts['port'] ?? (($parts['scheme'] ?? 'ws') === 'wss' ? 443 : 80);
        $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        $hostHeader = $this->hostHeader ?? "127.0.0.1:{$port}";
        $key = base64_encode(random_bytes(16));

        $request = implode("\r\n", [
            "GET {$path} HTTP/1.1",
            "Host: {$hostHeader}",
            'Upgrade: websocket',
            'Connection: Upgrade',
            "Sec-WebSocket-Key: {$key}",
            'Sec-WebSocket-Version: 13',
            '',
            '',
        ]);

        if (fwrite($this->socket, $request) === false) {
            throw new StealthException('WebSocket handshake write failed.');
        }

        $response = '';
        while (true) {
            $line = fgets($this->socket, 4_096);
            if ($line === false || $line === "\r\n") {
                break;
            }
            $response .= $line;
        }

        if (!str_contains($response, '101')) {
            throw new StealthException('WebSocket handshake failed.');
        }
    }

    private function buildFrame(string $payload): string
    {
        $length = strlen($payload);
        $frame = chr(0x81);

        if ($length <= 125) {
            $frame .= chr(0x80 | $length);
        } elseif ($length <= 65_535) {
            $frame .= chr(0x80 | 126) . pack('n', $length);
        } else {
            $frame .= chr(0x80 | 127) . pack('J', $length);
        }

        $mask = random_bytes(4);
        $repeated = str_repeat($mask, (int) ceil($length / 4));

        return $frame . $mask . substr($payload ^ $repeated, 0, $length);
    }

    private function fillBuffer(float $seconds): void
    {
        $tvSec = (int) $seconds;
        $tvUsec = (int) (($seconds - $tvSec) * 1_000_000);
        $read = [$this->socket];
        $write = $except = null;

        if (@stream_select($read, $write, $except, $tvSec, $tvUsec) > 0) {
            $chunk = @fread($this->socket, 65_536);
            if ($chunk !== false && $chunk !== '') {
                $this->readBuffer .= $chunk;
            }
        }
    }

    private function decodeFrameFromBuffer(string &$buffer): ?string
    {
        $length = strlen($buffer);

        if ($length < 2) {
            return null;
        }

        $secondByte = ord($buffer[1]);
        $masked = ($secondByte & 0x80) !== 0;
        $payloadLen = $secondByte & 0x7F;
        $offset = 2;

        if ($payloadLen === 126) {
            if ($length < 4) return null;
            $payloadLen = unpack('n', substr($buffer, 2, 2))[1];
            $offset = 4;
        } elseif ($payloadLen === 127) {
            if ($length < 10) return null;
            $payloadLen = unpack('J', substr($buffer, 2, 8))[1];
            $offset = 10;
        }

        $mask = '';
        if ($masked) {
            if ($length < $offset + 4) return null;
            $mask = substr($buffer, $offset, 4);
            $offset += 4;
        }

        if ($length < $offset + $payloadLen) {
            return null;
        }

        $payload = substr($buffer, $offset, $payloadLen);

        if ($masked) {
            $repeated = str_repeat($mask, (int) ceil($payloadLen / 4));
            $payload = substr($payload ^ $repeated, 0, $payloadLen);
        }

        $buffer = substr($buffer, $offset + $payloadLen);

        return $payload;
    }
}
