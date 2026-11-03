<?php

namespace App\Support;

use Closure;

/**
 * A value kept for the rest of the request (and no longer): the same lookup asked for again — by a table built
 * several times over, by every row of a list — is answered from here. Lives in the container, so each request
 * and each test starts with none; once() in a static method does not (it has no object to hang the value on).
 */
final class RequestMemo
{
    /** @var array<string, mixed> */
    private array $values = [];

    public static function remember(string $key, Closure $make): mixed
    {
        $memo = self::instance();

        if (! array_key_exists($key, $memo->values)) {
            $memo->values[$key] = $make();
        }

        return $memo->values[$key];
    }

    /** Everything forgotten — after a write that would make what was remembered stale. */
    public static function flush(): void
    {
        self::instance()->values = [];
    }

    /** The values whose keys start so forgotten — a page's own, before it is drawn again after a change. */
    public static function forget(string $prefix): void
    {
        $memo = self::instance();
        $memo->values = array_filter($memo->values, fn (string $key): bool => ! str_starts_with($key, $prefix), ARRAY_FILTER_USE_KEY);
    }

    private static function instance(): self
    {
        if (! app()->bound(self::class)) {
            app()->instance(self::class, new self);
        }

        return app(self::class);
    }
}
