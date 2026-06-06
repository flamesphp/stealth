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
    public const MIN_CHROME_MAJOR = 148;

    public const DEFAULT_TIMEOUT = 10;

    private const HTTP_TIMEOUT = 2;

    private const CONNECT_TIMEOUT = 1.0;

    private const TARGET_LIST_TIMEOUT = 2;

    private const LAUNCH_CDP_TIMEOUT = 8;

    private const MAX_ATTEMPTS = 3;

    private const RETRY_DELAY = 0.2;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
    ) {}

    public static function resolveDefaultTimeout(): int
    {
        return max(1, (int) (Env::get('STEALTH_TIMEOUT') ?? self::DEFAULT_TIMEOUT));
    }

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

    /** @return array<string, mixed> */
    public function version(): array
    {
        return $this->request('GET', '/json/version');
    }

    public function requireMinimumChromeVersion(int $minimumMajor = self::MIN_CHROME_MAJOR): void
    {
        $info = $this->version();
        $browser = (string) ($info['Browser'] ?? '');

        if ($browser === '' || !preg_match('#/(\d+)#', $browser, $matches)) {
            throw new StealthException(
                'Could not determine Chrome version from /json/version (Browser: '
                . ($browser !== '' ? $browser : 'missing')
                . ')',
            );
        }

        $major = (int) $matches[1];

        if ($major < $minimumMajor) {
            throw new StealthException(
                "Chrome {$major} is below the minimum required version {$minimumMajor} ({$browser})",
            );
        }
    }

    /**
     * Opens a new tab (Target.createTarget via browser WS, PUT /json/new as fallback).
     *
     * @return array<string, mixed>
     */
    public function createTargetWithRetry(
        string $url = 'about:blank',
        ?int $maxAttempts = null,
        ?float $delaySeconds = null,
    ): array {
        $maxAttempts ??= self::MAX_ATTEMPTS;
        $delaySeconds ??= self::RETRY_DELAY;
        $lastError = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return $this->createTarget($url);
            } catch (StealthException $e) {
                $lastError = $e;
                if ($attempt < $maxAttempts) {
                    asleep($delaySeconds);
                }
            }
        }

        throw new StealthException(
            "Stealth unavailable after {$maxAttempts} attempts ({$this->baseUrl()}): "
            . $lastError?->getMessage(),
            previous: $lastError,
        );
    }

    /** @return array<string, mixed> */
    public function createTarget(string $url = 'about:blank'): array
    {
        try {
            return $this->createTargetViaBrowser($url);
        } catch (StealthException) {
            return $this->createTargetViaPutWithRecovery($url);
        }
    }

    /** @return array<string, mixed> */
    private function createTargetViaBrowser(string $url): array
    {
        $version = $this->version();
        $browserWs = (string) ($version['webSocketDebuggerUrl'] ?? '');

        if ($browserWs === '') {
            throw new StealthException('No browser webSocketDebuggerUrl in /json/version');
        }

        $browser = AsyncClient::connect($this->rewriteWebSocketUrl($browserWs), self::CONNECT_TIMEOUT);

        try {
            $result = $browser->send('Target.createTarget', ['url' => $url], self::LAUNCH_CDP_TIMEOUT);
        } finally {
            $browser->close();
        }

        $targetId = (string) ($result['targetId'] ?? '');

        if ($targetId === '') {
            throw new StealthException('Target.createTarget returned no targetId');
        }

        return $this->waitForTargetInList($targetId);
    }

    /**
     * PUT is required by Chromium but socat may drop the response body.
     * Recover by finding the new tab in /json/list when PUT times out.
     *
     * @return array<string, mixed>
     */
    private function createTargetViaPutWithRecovery(string $url): array
    {
        $knownIds = array_column($this->getTabs(), 'id');
        $path = '/json/new?' . rawurlencode($url);

        try {
            return $this->normalizeTarget($this->request('PUT', $path));
        } catch (StealthException) {
            foreach ($this->getTabs() as $tab) {
                $id = (string) ($tab['id'] ?? '');

                if ($id !== '' && !in_array($id, $knownIds, true) && !empty($tab['webSocketDebuggerUrl'])) {
                    return $this->normalizeTarget($tab);
                }
            }

            throw new StealthException("Failed to open new tab ({$this->baseUrl()}{$path})");
        }
    }

    /** @return array<string, mixed> */
    private function waitForTargetInList(string $targetId): array
    {
        $deadline = microtime(true) + self::TARGET_LIST_TIMEOUT;

        while (microtime(true) < $deadline) {
            foreach ($this->getTabs() as $tab) {
                if (($tab['id'] ?? '') === $targetId && !empty($tab['webSocketDebuggerUrl'])) {
                    return $this->normalizeTarget($tab);
                }
            }

            asleep(0.05);
        }

        throw new StealthException("Target {$targetId} not found in /json/list after create");
    }

    public function rewriteWebSocketUrl(string $url): string
    {
        $parts = parse_url($url);

        if (!is_array($parts)) {
            return $url;
        }

        $scheme = $parts['scheme'] ?? 'ws';
        $path = ($parts['path'] ?? '/')
            . (isset($parts['query']) ? '?' . $parts['query'] : '');

        return "{$scheme}://{$this->host}:{$this->port}{$path}";
    }

    /** @return list<array<string, mixed>> */
    public function getTabs(): array
    {
        $result = $this->requestList('GET', '/json/list');
        $tabs = [];

        foreach ($result as $tab) {
            if (!is_array($tab)) {
                continue;
            }

            $tabs[] = $this->normalizeTarget($tab);
        }

        return $tabs;
    }

    public function closeTarget(string $targetId): void
    {
        $this->request('GET', '/json/close/' . rawurlencode($targetId));
    }

    /** @param array<string, mixed> $target */
    private function normalizeTarget(array $target): array
    {
        if (!empty($target['webSocketDebuggerUrl'])) {
            $target['webSocketDebuggerUrl'] = $this->rewriteWebSocketUrl((string) $target['webSocketDebuggerUrl']);
        }

        return $target;
    }

    /** @return list<mixed> */
    private function requestList(string $method, string $path): array
    {
        $decoded = $this->requestRaw($method, $path);

        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new StealthException("Expected JSON list from {$method} {$this->baseUrl()}{$path}");
        }

        return $decoded;
    }

    /** @return array<string, mixed> */
    private function request(string $method, string $path): array
    {
        $decoded = $this->requestRaw($method, $path);

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new StealthException("Expected JSON object from {$method} {$this->baseUrl()}{$path}");
        }

        return $decoded;
    }

    /** @return array<mixed> */
    private function requestRaw(string $method, string $path): array
    {
        $url = $this->baseUrl() . $path;
        $lastError = null;

        foreach (self::cdpHostHeaders($this->port) as $hostHeader) {
            try {
                return $this->doRequest($method, $path, $url, $hostHeader);
            } catch (StealthException $e) {
                $lastError = $e;
                if (!self::isHostHeaderError($e)) {
                    throw $e;
                }
            }
        }

        throw $lastError ?? new StealthException("CDP HTTP failed ({$method} {$url})");
    }

    /** @return array<mixed> */
    private function doRequest(string $method, string $path, string $url, string $hostHeader): array
    {
        $context = stream_context_create([
            'http' => [
                'method'        => $method,
                'timeout'       => self::HTTP_TIMEOUT,
                'ignore_errors' => true,
                'header'        => "Host: {$hostHeader}\r\nConnection: close\r\n",
            ],
        ]);

        $body = @file_get_contents($url, false, $context);

        if ($body === false) {
            throw new StealthException("CDP HTTP failed ({$method} {$url})");
        }

        if (str_starts_with($path, '/json/close/')) {
            return [];
        }

        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            $preview = substr(trim($body), 0, 120);
            throw new StealthException("Invalid CDP response ({$method} {$url}): {$preview}");
        }

        return $decoded;
    }

    /** @return list<string> */
    public static function cdpHostHeaders(int $port): array
    {
        return ["127.0.0.1:{$port}", "localhost:{$port}"];
    }

    private static function isHostHeaderError(StealthException $e): bool
    {
        return str_contains($e->getMessage(), 'Host header');
    }
}
