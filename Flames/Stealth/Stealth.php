<?php
declare(strict_types=1);


namespace Flames\Stealth;

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

    private ?string $userAgent = null;

    /** @var array<string, true> */
    private array $fingerprintAppliedTabIds = [];

    private ?string $targetId = null;

    private ?AsyncClient $client = null;

    private readonly Endpoint $endpoint;

    private readonly ?CursorSync $cursorSync;

    private int $timeout;

    public function __construct(?string $host = null, ?int $port = null, ?int $timeout = null)
    {
        $this->timeout = $timeout ?? Endpoint::resolveDefaultTimeout();
        $this->endpoint = Endpoint::fromEnv($host, $port);
        $this->cursorSync = CursorSync::enabled() ? new CursorSync() : null;
    }

    public function setTimeout(int $seconds): void
    {
        if ($seconds < 1) {
            throw new StealthException('Timeout must be at least 1 second');
        }

        $this->timeout = $seconds;
    }

    public function getTimeout(): int
    {
        return $this->timeout;
    }

    /**
     * Opens a new tab and connects to it via CDP, retrying until Chrome is ready.
     */
    public function launch(int $maxRetries = 3, float $retryDelay = 0.2): void
    {
        $this->endpoint->requireMinimumChromeVersion();
        $target = $this->endpoint->createTargetWithRetry(maxAttempts: $maxRetries, delaySeconds: $retryDelay);
        $this->client = AsyncClient::connect((string) $target['webSocketDebuggerUrl']);
        $this->targetId = $target['id'] ?? null;
        $this->bootstrapDomains();

        if ($this->targetId !== null) {
            $this->cursorSync?->register($this->targetId, $this->mouseX, $this->mouseY);
            $this->cursorSync?->focus($this->targetId);
        }
    }

    /**
     * Navigates to a URL and waits until the page finishes loading.
     */
    public function navigate(string $url, ?int $timeoutSeconds = null): void
    {
        $this->assertLaunched();
        $this->armPageLoadWait();
        $this->cdp('Page.navigate', ['url' => $url]);
        $this->awaitPageLoad($timeoutSeconds);
    }

    /**
     * Arms the next navigation wait. Call before an action that navigates
     * (Enter, link click, form submit), then call waitForNavigation().
     */
    public function expectNavigation(): void
    {
        $this->assertLaunched();
        $this->armPageLoadWait();
    }

    /**
     * Waits for the navigation armed by expectNavigation().
     */
    public function waitForNavigation(?int $timeoutSeconds = null): void
    {
        $this->assertLaunched();
        $this->awaitPageLoad($timeoutSeconds);
    }

    /**
     * Alias for navigate().
     */
    public function maps(string $url, ?int $timeoutSeconds = null): void
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
        $this->ensureRuntimeEnabled();

        $result = $this->cdp('Runtime.evaluate', [
            'expression'    => $expression,
            'returnByValue' => true,
            'awaitPromise'  => $awaitPromise,
        ]);

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

    public function waitForSelector(string $selector, ?int $timeoutSeconds = null): void
    {
        if (!$this->hasSelector($selector, $timeoutSeconds)) {
            throw new StealthException("Timeout waiting for selector: {$selector}");
        }
    }

    public function querySelector(string $selector, ?int $timeoutSeconds = null): Element|false
    {
        if (!$this->hasSelector($selector, $timeoutSeconds)) {
            return false;
        }

        $nodeId = $this->findSelectorNodeId($selector);

        if ($nodeId === null) {
            return false;
        }

        return new Element($this, $nodeId, $selector);
    }

    public function click(string $selector, ?int $timeoutSeconds = null): void
    {
        $element = $this->querySelector($selector, $timeoutSeconds);

        if ($element === false) {
            throw new StealthException("Element not found: {$selector}");
        }

        $element->click();
    }

    private function hasSelector(string $selector, ?int $timeoutSeconds = null): bool
    {
        $timeoutSeconds ??= $this->timeout;
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            if ($this->findSelectorNodeId($selector) !== null) {
                return true;
            }

            asleep(0.05);
        }

        return false;
    }

    public function hoverNode(int $nodeId, string $selector): void
    {
        [$x, $y] = $this->resolveTargetPoint($nodeId, $selector);
        $this->moveMouseTo($x, $y);
    }

    public function clickNode(int $nodeId, string $selector): void
    {
        [$x, $y] = $this->resolveTargetPoint($nodeId, $selector);
        $this->moveMouseTo($x, $y);
        asleep(random_int(30, 90) / 1000.0);
        $this->dispatchMouseClick($x, $y);
    }

    public function moveMouseTo(float $x, float $y): void
    {
        $this->bringToFront();
        $nextPauseAt = random_int(4, 9);

        $path = HumanMouse::path($this->mouseX, $this->mouseY, $x, $y);
        $lastStep = count($path) - 1;

        foreach ($path as $step => [$px, $py]) {
            $this->dispatchMouseMove($px, $py);
            $this->syncCursor($px, $py);

            if ($step === $nextPauseAt) {
                asleep(random_int(1, 5) / 1000.0);
                $nextPauseAt = $step + random_int(4, 9);
            }
        }

        $this->cdpFlush();
        $this->mouseX = $x;
        $this->mouseY = $y;
        $this->syncCursor($x, $y);
    }

    /** @return array{0: float, 1: float} */
    private function resolveTargetPoint(int $nodeId, string $selector): array
    {
        $this->ensureDomEnabled();
        $this->cdp('DOM.scrollIntoViewIfNeeded', ['nodeId' => $nodeId]);

        $box = $this->cdp('DOM.getBoxModel', ['nodeId' => $nodeId]);
        $content = $box['model']['content'] ?? null;

        if (!is_array($content) || count($content) < 8) {
            throw new StealthException("Could not resolve click coordinates for: {$selector}");
        }

        return HumanMouse::randomPointInBox($content);
    }

    private function dispatchMouseMove(float $x, float $y): void
    {
        $this->cdpPush('Input.dispatchMouseEvent', [
            'type' => 'mouseMoved',
            'x'    => $x,
            'y'    => $y,
        ]);
    }

    private function dispatchMouseClick(float $x, float $y): void
    {
        $this->animateClick($x, $y);

        $this->cdp('Input.dispatchMouseEvent', [
            'type'       => 'mousePressed',
            'x'          => $x,
            'y'          => $y,
            'button'     => 'left',
            'clickCount' => 1,
        ]);

        asleep(random_int(45, 130) / 1000.0);

        $this->cdp('Input.dispatchMouseEvent', [
            'type'       => 'mouseReleased',
            'x'          => $x + (random_int(-2, 2) / 10.0),
            'y'          => $y + (random_int(-2, 2) / 10.0),
            'button'     => 'left',
            'clickCount' => 1,
        ]);
    }

    public function type(string $text): void
    {
        $this->typeHuman($text);
    }

    public function typeHuman(string $text): void
    {
        $length = strlen($text);

        for ($i = 0; $i < $length; $i++) {
            $this->cdp('Input.insertText', ['text' => $text[$i]]);

            if ($i < $length - 1) {
                asleep(random_int(50, 190) / 1000.0);
            }
        }
    }

    public function press(string $key, bool $waitForNavigation = false, ?int $timeoutSeconds = null): void
    {
        if ($waitForNavigation) {
            $this->expectNavigation();
        }

        $definition = self::keyDefinition($key);

        foreach (['keyDown', 'keyUp'] as $type) {
            $this->cdp('Input.dispatchKeyEvent', ['type' => $type, ...$definition]);
        }

        if ($waitForNavigation) {
            $this->waitForNavigation($timeoutSeconds);
        }
    }

    /**
     * Sends a raw CDP command and returns the result array.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function command(string $method, array $params = [], ?int $timeoutSeconds = null): array
    {
        return $this->cdp($method, $params, $timeoutSeconds);
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
            if ($this->targetId !== null) {
                $this->cursorSync?->unregister($this->targetId);
            }

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

    private bool $runtimeEnabled = false;

    private bool $domEnabled = false;

    private float $mouseX = 960.0;

    private float $mouseY = 540.0;

    /** @var array{dom: bool, load: bool} */
    private array $pageLoadState = ['dom' => false, 'load' => false];

    /** @var array{x: float, y: float}|null */
    private ?array $viewportChromeOffset = null;

    private function bootstrapDomains(): void
    {
        $this->applyUserAgent();
        $this->cdp('Page.enable');
        $this->applyFingerprint();
        $this->cdp('Page.setLifecycleEventsEnabled', ['enabled' => true]);
        $this->cdp('Runtime.enable');
        $this->runtimeEnabled = true;
    }

    private function applyUserAgent(): void
    {
        $this->applyUserAgentToAllTabs();
    }

    private function resolveUserAgent(): string
    {
        if ($this->userAgent !== null) {
            return $this->userAgent;
        }

        $info = $this->endpoint->version();
        $default = (string) ($info['User-Agent'] ?? '');

        $this->userAgent = $default !== ''
            ? str_replace(
                'Mozilla/5.0 (X11; Linux x86_64)',
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
                $default,
            )
            : 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36';

        return $this->userAgent;
    }

    private function applyUserAgentToAllTabs(): void
    {
        $userAgent = $this->resolveUserAgent();

        foreach ($this->endpoint->getTabs() as $tab) {
            if (!$this->isPageTab($tab)) {
                continue;
            }

            if (($tab['id'] ?? null) === $this->targetId && $this->client !== null) {
                $this->cdp('Network.enable');
                $this->cdp('Network.setUserAgentOverride', ['userAgent' => $userAgent]);
                continue;
            }

            $client = AsyncClient::connect((string) $tab['webSocketDebuggerUrl']);

            try {
                $client->send('Network.enable');
                $client->send('Network.setUserAgentOverride', ['userAgent' => $userAgent]);
            } finally {
                $client->close();
            }
        }
    }

    /** @param array<string, mixed> $tab */
    private function isPageTab(array $tab): bool
    {
        return ($tab['type'] ?? 'page') === 'page' && !empty($tab['webSocketDebuggerUrl']);
    }

    private function applyFingerprint(): void
    {
        $this->applyFingerprintToAllTabs();
    }

    private function applyFingerprintToAllTabs(): void
    {
        $params = [
            'source'         => Fingerprint::evaluateOnNewDocumentScript(),
            'runImmediately' => true,
        ];

        foreach ($this->endpoint->getTabs() as $tab) {
            $tabId = (string) ($tab['id'] ?? '');

            if ($tabId === '' || !$this->isPageTab($tab) || isset($this->fingerprintAppliedTabIds[$tabId])) {
                continue;
            }

            if ($tabId === $this->targetId && $this->client !== null) {
                $this->cdp('Page.addScriptToEvaluateOnNewDocument', $params);
            } else {
                $client = AsyncClient::connect((string) $tab['webSocketDebuggerUrl']);

                try {
                    $client->send('Page.addScriptToEvaluateOnNewDocument', $params);
                } finally {
                    $client->close();
                }
            }

            $this->fingerprintAppliedTabIds[$tabId] = true;
        }
    }

    private function assertLaunched(): void
    {
        if ($this->client === null) {
            throw new StealthException('Stealth is not launched. Call launch() first.');
        }
    }

    private function bringToFront(): void
    {
        $this->cdp('Page.bringToFront');

        if ($this->targetId !== null) {
            $this->cursorSync?->focus($this->targetId);
        }
    }

    private function animateClick(float $x, float $y): void
    {
        if ($this->cursorSync === null || $this->targetId === null) {
            return;
        }

        $offset = $this->viewportChromeOffset();
        $this->cursorSync->click(
            $this->targetId,
            $x + $offset['x'],
            $y + $offset['y'],
        );
    }

    private function syncCursor(float $x, float $y): void
    {
        if ($this->cursorSync === null || $this->targetId === null) {
            return;
        }

        $offset = $this->viewportChromeOffset();
        $this->cursorSync->move(
            $this->targetId,
            $x + $offset['x'],
            $y + $offset['y'],
        );
    }

    private function resetViewportOffset(): void
    {
        $this->viewportChromeOffset = null;
    }

    /** @return array{x: float, y: float} */
    private function viewportChromeOffset(): array
    {
        if ($this->viewportChromeOffset !== null) {
            return $this->viewportChromeOffset;
        }

        $this->ensureRuntimeEnabled();

        $result = $this->evaluate('({x:(window.outerWidth-window.innerWidth)/2,y:window.outerHeight-window.innerHeight})');

        $this->viewportChromeOffset = [
            'x' => (float) (is_array($result) ? ($result['x'] ?? 0) : 0),
            'y' => (float) (is_array($result) ? ($result['y'] ?? 0) : 0),
        ];

        return $this->viewportChromeOffset;
    }

    private function armPageLoadWait(): void
    {
        $this->pageLoadState = ['dom' => false, 'load' => false];

        $this->client->once('Page.domContentEventFired', function (): void {
            $this->pageLoadState['dom'] = true;
        });

        $this->client->once('Page.loadEventFired', function (): void {
            $this->pageLoadState['load'] = true;
        });
    }

    private function awaitPageLoad(?int $timeoutSeconds = null): void
    {
        $timeoutSeconds ??= $this->timeout;
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            $this->client->pump();

            if ($this->pageLoadState['load']) {
                $this->resetViewportOffset();
                $this->applyUserAgentToAllTabs();
                $this->applyFingerprintToAllTabs();

                return;
            }

            asleep(0.001);
        }

        if ($this->pageLoadState['dom']) {
            $this->resetViewportOffset();
            $this->applyUserAgentToAllTabs();
            $this->applyFingerprintToAllTabs();

            return;
        }

        throw new StealthException('Timeout waiting for page load.');
    }

    private function ensureRuntimeEnabled(): void
    {
        if ($this->runtimeEnabled) {
            return;
        }

        $this->cdp('Runtime.enable');
        $this->runtimeEnabled = true;
    }

    private function ensureDomEnabled(): void
    {
        if ($this->domEnabled) {
            return;
        }

        $this->cdp('DOM.enable');
        $this->domEnabled = true;
    }

    private function findSelectorNodeId(string $selector): ?int
    {
        $this->ensureDomEnabled();

        $doc = $this->cdp('DOM.getDocument');
        $rootId = (int) ($doc['root']['nodeId'] ?? 0);

        if ($rootId === 0) {
            return null;
        }

        $query = $this->cdp('DOM.querySelector', [
            'nodeId'   => $rootId,
            'selector' => $selector,
        ]);

        $nodeId = (int) ($query['nodeId'] ?? 0);

        return $nodeId > 0 ? $nodeId : null;
    }

    /** @return array<string, mixed> */
    private static function keyDefinition(string $key): array
    {
        return match (strtolower($key)) {
            'enter', 'return' => [
                'key'                   => 'Enter',
                'code'                  => 'Enter',
                'windowsVirtualKeyCode' => 13,
                'nativeVirtualKeyCode'  => 13,
            ],
            default => throw new StealthException("Unknown key: {$key}"),
        };
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    /**
     * @param array<string, mixed> $params
     */
    private function cdpPush(string $method, array $params = []): void
    {
        if ($this->client === null) {
            throw new StealthException('Stealth is not launched. Call launch() first.');
        }

        $this->client->push($method, $params);
    }

    private function cdpFlush(): void
    {
        $this->client?->flush();
    }

    private function cdp(string $method, array $params = [], ?int $timeoutSeconds = null): array
    {
        if ($this->client === null) {
            throw new StealthException('Stealth is not launched. Call launch() first.');
        }

        return $this->client->send($method, $params, $timeoutSeconds ?? $this->timeout);
    }
}
