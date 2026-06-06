<?php
declare(strict_types=1);


namespace Flames\Stealth\Cdp;

use Flames\Autoload\C;

/**
 * Bridges PHP-pure and C-extension Promise implementations.
 *
 * @internal
 */
final class Promises
{
    public static function create(): object
    {
        if (C::validAsync()) {
            return new \Flames\Async\Async\Promise();
        }

        return new \Flames\Async\Promise();
    }

    public static function isPending(object $promise): bool
    {
        return $promise->isPending();
    }

    public static function isRejected(object $promise): bool
    {
        return self::state($promise) === self::rejectedConstant($promise);
    }

    public static function getResult(object $promise): mixed
    {
        return $promise->getResult();
    }

    public static function getError(object $promise): ?string
    {
        return $promise->getError();
    }

    public static function resolve(object $promise, mixed $value): void
    {
        $promise->resolve($value);
    }

    public static function reject(object $promise, string $message): void
    {
        $promise->reject($message);
    }

    private static function state(object $promise): int
    {
        return $promise->getState();
    }

    private static function rejectedConstant(object $promise): int
    {
        return $promise instanceof \Flames\Async\Async\Promise
            ? \Flames\Async\Async\Promise::REJECTED
            : \Flames\Async\Promise::REJECTED;
    }
}
