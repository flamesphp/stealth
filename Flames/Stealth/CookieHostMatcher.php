<?php
declare(strict_types=1);


namespace Flames\Stealth;

/**
 * @internal
 */
final class CookieHostMatcher
{
    public static function matches(string $cookieDomain, string $pattern): bool
    {
        $cookieDomain = strtolower(ltrim($cookieDomain, '.'));
        $pattern = strtolower($pattern);

        if (str_starts_with($pattern, '*.')) {
            $base = substr($pattern, 2);

            return $cookieDomain === $base || str_ends_with($cookieDomain, '.' . $base);
        }

        return $cookieDomain === ltrim($pattern, '.');
    }
}
