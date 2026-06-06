<?php
declare(strict_types=1);


namespace Flames\Stealth\Cdp;

/**
 * @internal
 */
final class MessageBus
{
    /** @var array<string, list<array{once: bool, handler: callable}>> */
    private array $eventListeners = [];

    /** @param \Closure(int, array<string, mixed>): void $onResult */
    /** @param \Closure(int, string): void $onError */
    public function __construct(
        private readonly WebSocket $socket,
        private readonly \Closure $onResult,
        private readonly \Closure $onError,
    ) {}

    public function once(string $method, callable $handler): void
    {
        $this->eventListeners[$method][] = ['once' => true, 'handler' => $handler];
    }

    public function pump(): bool
    {
        $processed = false;

        while (($frame = $this->socket->readFrameNonBlocking()) !== null) {
            $this->dispatch($frame);
            $processed = true;
        }

        return $processed;
    }

    private function dispatch(string $json): void
    {
        $data = json_decode($json, true);

        if (!is_array($data)) {
            return;
        }

        if (isset($data['method'])) {
            $this->dispatchEvent(
                (string) $data['method'],
                is_array($data['params'] ?? null) ? $data['params'] : [],
            );
        }

        if (!isset($data['id'])) {
            return;
        }

        $id = (int) $data['id'];

        if (isset($data['error'])) {
            $message = is_array($data['error'])
                ? ($data['error']['message'] ?? 'CDP command failed')
                : 'CDP command failed';

            ($this->onError)($id, (string) $message);
            return;
        }

        $result = is_array($data['result'] ?? null) ? $data['result'] : [];
        ($this->onResult)($id, $result);
    }

    /** @param array<string, mixed> $params */
    private function dispatchEvent(string $method, array $params): void
    {
        if (!isset($this->eventListeners[$method])) {
            return;
        }

        foreach ($this->eventListeners[$method] as $i => $entry) {
            ($entry['handler'])($params);

            if ($entry['once']) {
                unset($this->eventListeners[$method][$i]);
            }
        }

        if (($this->eventListeners[$method] ?? []) === []) {
            unset($this->eventListeners[$method]);
        }
    }
}
