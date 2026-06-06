<?php
declare(strict_types=1);


namespace Flames\Stealth\Cdp;

/**
 * @internal
 */
final class MessageBus
{
    /** @param \Closure(int, array<string, mixed>): void $onResult */
    /** @param \Closure(int, string): void $onError */
    public function __construct(
        private readonly WebSocket $socket,
        private readonly \Closure $onResult,
        private readonly \Closure $onError,
    ) {}

    public function pump(): void
    {
        while (($frame = $this->socket->readFrameNonBlocking()) !== null) {
            $this->dispatch($frame);
        }
    }

    private function dispatch(string $json): void
    {
        $data = json_decode($json, true);

        if (!is_array($data) || !isset($data['id'])) {
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
}
