<?php
declare(strict_types=1);

namespace Flames\Stealth\Cdp;

use Flames\Stealth\Exception\StealthException;

/**
 * @internal
 */
final class Client
{
    private int $messageId = 0;

    private function __construct(
        private readonly WebSocket $socket,
    ) {}

    public static function connect(string $webSocketUrl, float $timeout = 30.0): self
    {
        $socket = WebSocket::connect($webSocketUrl, $timeout);

        return new self($socket);
    }

    /**
     * @return array<string, mixed>
     */
    public function send(string $method, array $params = [], int $timeoutSeconds = 30): array
    {
        $id      = ++$this->messageId;
        $payload = json_encode([
            'id'     => $id,
            'method' => $method,
            'params' => (object) $params,
        ], JSON_THROW_ON_ERROR);

        $this->socket->send($payload);

        $deadline = time() + $timeoutSeconds;
        while (time() <= $deadline) {
            $remaining = max(1, $deadline - time());
            $message   = $this->socket->receive($remaining);
            if ($message === null) {
                continue;
            }

            $data = json_decode($message, true);
            if (!is_array($data) || ($data['id'] ?? null) !== $id) {
                continue;
            }

            if (isset($data['error'])) {
                $message = is_array($data['error']) ? ($data['error']['message'] ?? 'CDP command failed') : 'CDP command failed';
                throw new StealthException((string) $message);
            }

            return is_array($data['result'] ?? null) ? $data['result'] : [];
        }

        throw new StealthException("Timeout waiting for CDP method '{$method}'.");
    }

    public function close(): void
    {
        $this->socket->close();
    }
}
