<?php
declare(strict_types=1);


namespace Flames\Stealth;

use Flames\Docker\Docker;
use Flames\Env\Env;

/**
 * Syncs the X11 cursor (visible in VNC) per browser tab.
 *
 * @internal
 */
final class CursorSync
{
    private readonly string $baseUrl;

    public function __construct(?string $host = null, ?int $port = null)
    {
        if (Docker::isDocker()) {
            $host ??= (string) (Env::get('STEALTH_CURSOR_HOST') ?? Env::get('STEALTH_HOST') ?? 'stealth');
        } else {
            $host ??= '127.0.0.1';
        }

        $port ??= (int) (Env::get('STEALTH_CURSOR_PORT') ?? 9230);
        $this->baseUrl = "http://{$host}:{$port}";
    }

    public static function enabled(): bool
    {
        if (!Docker::isDocker()) {
            return false;
        }

        $flag = Env::get('STEALTH_CURSOR_SYNC');

        if ($flag === null) {
            return true;
        }

        return filter_var($flag, FILTER_VALIDATE_BOOLEAN);
    }

    public function register(string $targetId, float $x = 960.0, float $y = 540.0): void
    {
        $this->post('/register', [
            'targetId' => $targetId,
            'x'        => $x,
            'y'        => $y,
        ]);
    }

    public function unregister(string $targetId): void
    {
        $this->post('/unregister', ['targetId' => $targetId]);
    }

    public function move(string $targetId, float $x, float $y): void
    {
        $this->post('/move', [
            'targetId' => $targetId,
            'x'        => $x,
            'y'        => $y,
        ]);
    }

    public function click(string $targetId, float $x, float $y): void
    {
        $this->post('/click', [
            'targetId' => $targetId,
            'x'        => $x,
            'y'        => $y,
        ]);
    }

    public function focus(string $targetId): void
    {
        $this->post('/focus', ['targetId' => $targetId]);
    }

    /** @param array<string, mixed> $payload */
    private function post(string $path, array $payload): void
    {
        $url = $this->baseUrl . $path;
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/json\r\nConnection: close\r\n",
                'content'       => $body,
                'timeout'       => 2,
                'ignore_errors' => true,
            ],
        ]);

        @file_get_contents($url, false, $context);
    }
}
