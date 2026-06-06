<?php
declare(strict_types=1);


namespace Flames\Stealth;

/**
 * Chromium user profile persisted across container restarts.
 *
 * The profile lives in a Docker named volume mounted at {@see CONTAINER_PATH}.
 * Cookies, cache, history and local storage survive `docker compose restart`.
 */
final class ProfileStorage
{
    public const CONTAINER_PATH = '/var/lib/flames-chromium';

    public const VOLUME_NAME = 'stealth-chromium-profile';
}
