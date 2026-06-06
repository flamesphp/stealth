<?php
declare(strict_types=1);

namespace Flames\Stealth\Cdp;

use Flames\Docker\Docker;
use Flames\Env\Env;
use Flames\Stealth\Exception\StealthException;

/**
 * @internal
 */
final class Endpoint
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
    ) {}

    public static function fromEnv(?string $host = null, ?int $port = null): self
    {
        if (Docker::isDocker()) {
            $host ??= (string) (Env::get('STEALTH_HOST') ?? 'stealth');
            $port ??= (int) (Env::get('STEALTH_REMOTE_PORT') ?? 9222);
        } else {
            $host ??= '127.0.0.1';
            $port ??= (int) (Env::get('STEALTH_REMOTE_PORT') ?? Env::get('STEALTH_REMOTE_PORT_FORWARDED') ?? 9222);
        }

        return new self($host, $port);
    }

    public function baseUrl(): string
    {
        return "http://{$this->host}:{$this->port}";
    }

    /**
     * @return array<string, mixed>
     */
    public function version(): array
    {
        return $this->request('GET', '/json/version');
    }

    /**
     * @return array<string, mixed>
     */
    public function createTarget(string $url = 'about:blank'): array
    {
        $encoded = rawurlencode($url);
        $result  = $this->request('PUT', "/json/new?{$encoded}");

        if (!is_array($result) || empty($result['webSocketDebuggerUrl'])) {
            throw new StealthException('Failed to create a new browser target.');
        }

        return $result;
    }

    public function closeTarget(string $targetId): void
    {
        $this->request('GET', '/json/close/' . rawurlencode($targetId));
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $method, string $path): array
    {
        $url = $this->baseUrl() . $path;

        $context = stream_context_create([
            'http' => [
                'method'        => $method,
                'timeout'       => 15,
                'ignore_errors' => true,
                'header'        => "Connection: close\r\n",
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            throw new StealthException("CDP HTTP request failed: {$method} {$url}");
        }

        if (str_starts_with($path, '/json/close/')) {
            return [];
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new StealthException("Invalid CDP HTTP response: {$method} {$url}");
        }

        return $decoded;
    }
}
