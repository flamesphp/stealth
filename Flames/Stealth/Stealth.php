<?php
declare(strict_types=1);

namespace Flames\Stealth;

use Flames\Stealth\Cdp\Client;
use Flames\Stealth\Cdp\Endpoint;
use Flames\Stealth\Exception\StealthException;

/**
 * Stealth browser automation via Chrome DevTools Protocol (CDP).
 */
final class Stealth
{
    private bool $closed = false;

    private ?string $targetId = null;

    private function __construct(
        private readonly Endpoint $endpoint,
        private readonly Client $client,
    ) {}

    public static function connect(?string $host = null, ?int $port = null): self
    {
        $endpoint = Endpoint::fromEnv($host, $port);
        $target   = $endpoint->createTarget('about:blank');
        $client   = Client::connect((string) $target['webSocketDebuggerUrl']);

        $browser = new self($endpoint, $client);
        $browser->targetId = isset($target['id']) ? (string) $target['id'] : null;
        $browser->bootstrap();

        return $browser;
    }

    public function navigate(string $url): self
    {
        $this->client->send('Page.navigate', ['url' => $url]);
        $this->waitForLoad();

        return $this;
    }

    public function evaluate(string $expression, bool $awaitPromise = false): mixed
    {
        $result = $this->client->send('Runtime.evaluate', [
            'expression'    => $expression,
            'returnByValue' => true,
            'awaitPromise'  => $awaitPromise,
        ]);

        if (($result['exceptionDetails'] ?? null) !== null) {
            $text = $result['exceptionDetails']['text'] ?? 'JavaScript evaluation failed';
            throw new StealthException((string) $text);
        }

        return $result['result']['value'] ?? null;
    }

    public function content(): string
    {
        $html = $this->evaluate('document.documentElement.outerHTML');

        return is_string($html) ? $html : '';
    }

    public function title(): string
    {
        $title = $this->evaluate('document.title');

        return is_string($title) ? $title : '';
    }

    /**
     * @return array<string, mixed>
     */
    public function command(string $method, array $params = [], int $timeoutSeconds = 30): array
    {
        return $this->client->send($method, $params, $timeoutSeconds);
    }

    public function close(): void
    {
        if ($this->closed) {
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
            $this->targetId = null;
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    private function bootstrap(): void
    {
        foreach (['Page.enable', 'Runtime.enable', 'Network.enable'] as $method) {
            $this->client->send($method);
        }
    }

    private function waitForLoad(int $timeoutSeconds = 30): void
    {
        $this->client->send('Page.getFrameTree');

        $deadline = time() + $timeoutSeconds;
        while (time() <= $deadline) {
            $state = $this->evaluate('document.readyState');
            if ($state === 'complete' || $state === 'interactive') {
                return;
            }
            usleep(100_000);
        }

        throw new StealthException('Timeout waiting for page load.');
    }
}
