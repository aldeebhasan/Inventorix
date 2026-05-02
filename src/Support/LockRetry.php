<?php

namespace Aldeebhasan\Inventorix\Support;

use Illuminate\Database\QueryException;

class LockRetry
{
    /**
     * Execute $callback with retry on lock wait timeout.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     *
     * @throws QueryException when all retries are exhausted
     */
    public static function run(callable $callback): mixed
    {
        $maxRetries = (int) config('inventorix.locking.lock_retry', 3);
        $baseDelayMs = (int) config('inventorix.locking.lock_retry_base_ms', 100);

        $attempt = 0;

        while (true) {
            try {
                return $callback();
            } catch (QueryException $e) {
                if (! self::isLockTimeout($e) || $attempt >= $maxRetries) {
                    throw $e;
                }

                $delayMs = $baseDelayMs * (2 ** $attempt);
                usleep($delayMs * 1000);
                $attempt++;
            }
        }
    }

    private static function isLockTimeout(QueryException $e): bool
    {
        // MySQL: 1205 = Lock wait timeout exceeded
        // PostgreSQL: 55P03 = lock_not_available
        $code = (string) $e->getCode();
        $message = strtolower($e->getMessage());

        return $code === '1205'
            || str_contains($message, 'lock wait timeout')
            || str_contains($message, 'lock_not_available')
            || str_contains($message, 'deadlock found');
    }
}
