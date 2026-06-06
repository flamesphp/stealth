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

    public function __construct(
        private readonly string $url,
        private readonly float $timeout = 30.0,
    ) {}

    public function connect(): void
    {
        $parts = parse_url($this->url);
        if (!is_array($parts) || empty($parts['host'])) {
            throw new StealthException("Invalid WebSocket URL: {$this->url}");
        }

        $scheme = $parts['scheme'] ?? 'ws';
        $host   = $parts['host'];
        $port   = $parts['port'] ?? ($scheme === 'wss' ? 443 : 80);
        $path   = ($parts['path'] ?? '/') . ($parts['query'] ?? '' ? '?' . $parts['query'] : '');

        $transport = $scheme === 'wss' ? 'ssl' : 'tcp';
        $target    = "{$transport}://{$host}:{$port}";

        $socket = @stream_socket_client(
            $target,
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            stream_context_create([
                'ssl' => [
                    'verify_peer'      => false,
                    'verify_peer_name' => false,
                ],
            ]),
        );

        if ($socket === false) {
            throw new StealthException("WebSocket connection failed ({$errno}): {$errstr}");
        }

        stream_set_timeout($socket, (int) ceil($this->timeout));

        $key  = base64_encode(random_bytes(16));
        $hostHeader = $host . (($scheme === 'wss' && $port !== 443) || ($scheme === 'ws' && $port !== 80) ? ':' . $port : '');

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

        if (fwrite($socket, $request) === false) {
            throw new StealthException('WebSocket handshake write failed.');
        }

        $response = stream_get_contents($socket, 8192);
        if (!is_string($response) || !str_contains($response, '101')) {
            throw new StealthException('WebSocket handshake failed.');
        }

        $this->socket = $socket;
    }

    public function send(string $payload): void
    {
        if (!is_resource($this->socket)) {
            throw new StealthException('WebSocket is not connected.');
        }

        $length = strlen($payload);
        $frame  = chr(0x81);

        if ($length <= 125) {
            $frame .= chr(0x80 | $length);
        } elseif ($length <= 65535) {
            $frame .= chr(0x80 | 126) . pack('n', $length);
        } else {
            $frame .= chr(0x80 | 127) . pack('J', $length);
        }

        $mask = random_bytes(4);
        $frame .= $mask;

        for ($i = 0; $i < $length; $i++) {
            $frame .= $payload[$i] ^ $mask[$i % 4];
        }

        if (fwrite($this->socket, $frame) === false) {
            throw new StealthException('WebSocket send failed.');
        }
    }

    public function receive(int $timeoutSeconds): ?string
    {
        if (!is_resource($this->socket)) {
            throw new StealthException('WebSocket is not connected.');
        }

        stream_set_timeout($this->socket, max(1, $timeoutSeconds));

        $data = '';
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            $chunk = fread($this->socket, 8192);
            if ($chunk === false) {
                throw new StealthException('WebSocket read failed.');
            }

            if ($chunk === '') {
                $meta = stream_get_meta_data($this->socket);
                if (($meta['timed_out'] ?? false) === true) {
                    return null;
                }
                usleep(10_000);
                continue;
            }

            $data .= $chunk;
            $decoded = $this->decodeFrame($data);
            if ($decoded !== null) {
                return $decoded;
            }
        }

        return null;
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
    }

    private function decodeFrame(string $buffer): ?string
    {
        $length = strlen($buffer);
        if ($length < 2) {
            return null;
        }

        $secondByte = ord($buffer[1]);
        $masked     = ($secondByte & 0x80) !== 0;
        $payloadLen = $secondByte & 0x7F;
        $offset     = 2;

        if ($payloadLen === 126) {
            if ($length < 4) {
                return null;
            }
            $payloadLen = unpack('n', substr($buffer, 2, 2))[1];
            $offset     = 4;
        } elseif ($payloadLen === 127) {
            if ($length < 10) {
                return null;
            }
            $payloadLen = unpack('J', substr($buffer, 2, 8))[1];
            $offset     = 10;
        }

        if ($masked) {
            if ($length < $offset + 4) {
                return null;
            }
            $mask   = substr($buffer, $offset, 4);
            $offset += 4;
        }

        if ($length < $offset + $payloadLen) {
            return null;
        }

        $payload = substr($buffer, $offset, $payloadLen);

        if ($masked) {
            $unmasked = '';
            for ($i = 0; $i < $payloadLen; $i++) {
                $unmasked .= $payload[$i] ^ $mask[$i % 4];
            }
            return $unmasked;
        }

        return $payload;
    }
}
