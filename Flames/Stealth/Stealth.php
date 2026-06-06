<?php
declare(strict_types=1);


namespace Flames\Stealth;

use Flames\Async\Container;
use Flames\Stealth\Cdp\AsyncClient;
use Flames\Stealth\Cdp\Endpoint;
use Flames\Stealth\Exception\StealthException;

/**
 * Async Stealth browser automation via Chrome DevTools Protocol (CDP).
 *
 * All methods that communicate with the browser use await() internally, so they
 * work correctly whether called from the main thread or from inside an async closure.
 *
 * Typical usage:
 *
 *   $browser = new Stealth();
 *
 *   await(async(function() use ($browser) {
 *       $browser->launch();
 *       $browser->navigate('https://example.com');
 *       $html = $browser->content();
 *       $browser->close();
 *   }));
 */
final class Stealth
{
    private bool $closed = false;

    private ?string $targetId = null;

    private ?AsyncClient $client = null;

    private readonly Endpoint $endpoint;

    public function __construct(?string $host = null, ?int $port = null)
    {
        $this->endpoint = Endpoint::fromEnv($host, $port);
    }

    /**
     * Connects to the Chrome debugger, retrying until Chrome is ready.
     */
    public function launch(int $maxRetries = 3, float $retryDelay = 0.2): void
    {
        $target = $this->endpoint->acquireTargetWithRetry(maxAttempts: $maxRetries, delaySeconds: $retryDelay);
        $this->client = AsyncClient::connect((string) $target['webSocketDebuggerUrl']);
        $this->targetId = $target['id'] ?? null;
        $this->bootstrapDomains();
    }

    /**
     * Navigates to a URL and waits for the page to load.
     */
    public function navigate(string $url, int $timeoutSeconds = 10): void
    {
        await($this->cdp('Page.navigate', ['url' => $url]));
        $this->waitForLoad($timeoutSeconds);
    }

    /**
     * Alias for navigate().
     */
    public function maps(string $url, int $timeoutSeconds = 30): void
    {
        $this->navigate($url, $timeoutSeconds);
    }

    /**
     * Returns all open tabs in the browser.
     *
     * @return list<array<string, mixed>>
     */
    public function getTabs(): array
    {
        return $this->endpoint->getTabs();
    }

    /**
     * Closes a specific tab by its target ID.
     */
    public function closeTab(string $tabId): void
    {
        $this->endpoint->closeTarget($tabId);
    }

    /**
     * Evaluates a JavaScript expression and returns its value.
     */
    public function evaluate(string $expression, bool $awaitPromise = false): mixed
    {
        $result = await($this->cdp('Runtime.evaluate', [
            'expression'    => $expression,
            'returnByValue' => true,
            'awaitPromise'  => $awaitPromise,
        ]));

        if (isset($result['exceptionDetails'])) {
            throw new StealthException(
                (string) ($result['exceptionDetails']['text'] ?? 'JavaScript evaluation failed'),
            );
        }

        return $result['result']['value'] ?? null;
    }

    public function content(): string
    {
        return (string) $this->evaluate('document.documentElement.outerHTML');
    }

    public function title(): string
    {
        return (string) $this->evaluate('document.title');
    }

    /**
     * Sends a raw CDP command and returns the result array.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function command(string $method, array $params = [], int $timeoutSeconds = 8): array
    {
        return await($this->cdp($method, $params, $timeoutSeconds));
    }

    public function close(): void
    {
        if ($this->closed || $this->client === null) {
            return;
        }

        $this->closed = true;

        try {
            if ($this->targetId !== null) {
                $this->endpoint->closeTarget($this->targetId);
            }
        } catch (StealthException) {
        } finally {
            $this->client->close();
            $this->client = null;
            $this->targetId = null;
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    // ── Internals ────────────────────────────────────────────────────────────

    private function bootstrapDomains(): void
    {
        foreach (['Page.enable', 'Runtime.enable', 'Network.enable'] as $method) {
            await($this->cdp($method));
        }
    }

    private function waitForLoad(int $timeoutSeconds = 10): void
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            $state = $this->evaluate('document.readyState');

            if ($state === 'complete' || $state === 'interactive') {
                return;
            }

            asleep(0.02);
        }

        throw new StealthException('Timeout waiting for page load.');
    }

    /**
     * @param array<string, mixed> $params
     */
    private function cdp(string $method, array $params = [], int $timeoutSeconds = 8): Container
    {
        if ($this->client === null) {
            throw new StealthException('Stealth is not launched. Call launch() first.');
        }

        return $this->client->send($method, $params, $timeoutSeconds);
    }
}
