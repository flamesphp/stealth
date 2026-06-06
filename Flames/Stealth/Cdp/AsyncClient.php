<?php
declare(strict_types=1);


namespace Flames\Stealth\Cdp;

use Flames\Stealth\Exception\StealthException;

/**
 * CDP client — synchronous send() with inline WebSocket pump.
 *
 * @internal
 */
final class AsyncClient
{
    private int $messageId = 0;

    private readonly MessageBus $bus;

    /** @var array<int, array{done: bool, result?: array<string, mixed>, error?: string}> */
    private array $pending = [];

    private function __construct(private readonly WebSocket $socket)
    {
        $this->bus = new MessageBus(
            $socket,
            fn(int $id, array $result) => $this->resolve($id, $result),
            fn(int $id, string $error) => $this->reject($id, $error),
        );
    }

    public static function connect(string $webSocketUrl, float $timeout = 1.0): self
    {
        return new self(WebSocket::connect($webSocketUrl, $timeout));
    }

    public function once(string $method, callable $handler): void
    {
        $this->bus->once($method, $handler);
    }

    public function pump(): bool
    {
        return $this->bus->pump();
    }

    /** @param array<string, mixed> $params */
    public function push(string $method, array $params = []): void
    {
        $id = ++$this->messageId;
        $this->socket->send($this->encode($id, $method, $params));
    }

    public function flush(float $maxSeconds = 0.25): void
    {
        $deadline = microtime(true) + $maxSeconds;
        $idleRounds = 0;

        while (microtime(true) < $deadline) {
            if ($this->bus->pump()) {
                $idleRounds = 0;
                continue;
            }

            $idleRounds++;

            if ($idleRounds >= 3) {
                break;
            }

            asleep(0.001);
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function send(string $method, array $params = [], int $timeoutSeconds = 8): array
    {
        $id = ++$this->messageId;
        $this->pending[$id] = ['done' => false];
        $this->socket->send($this->encode($id, $method, $params));

        $deadline = microtime(true) + $timeoutSeconds;

        while ($this->pending[$id]['done'] === false) {
            if (microtime(true) > $deadline) {
                unset($this->pending[$id]);
                throw new StealthException("Timeout waiting for CDP method '{$method}'.");
            }

            $this->bus->pump();

            if ($this->pending[$id]['done'] === false) {
                asleep(0.001);
            }
        }

        $slot = $this->pending[$id];
        unset($this->pending[$id]);

        if (isset($slot['error'])) {
            throw new StealthException($slot['error']);
        }

        return $slot['result'] ?? [];
    }

    public function close(): void
    {
        $this->socket->close();
    }

    /** @param array<string, mixed> $result */
    private function resolve(int $id, array $result): void
    {
        if (!isset($this->pending[$id])) {
            return;
        }

        $this->pending[$id] = ['done' => true, 'result' => $result];
    }

    private function reject(int $id, string $error): void
    {
        if (!isset($this->pending[$id])) {
            return;
        }

        $this->pending[$id] = ['done' => true, 'error' => $error];
    }

    /** @param array<string, mixed> $params */
    private function encode(int $id, string $method, array $params): string
    {
        return json_encode(
            ['id' => $id, 'method' => $method, 'params' => (object) $params],
            JSON_THROW_ON_ERROR,
        );
    }
}
