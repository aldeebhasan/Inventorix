<?php

use Aldeebhasan\Inventorix\Support\LockRetry;
use Illuminate\Database\QueryException;

it('LockRetry::run() returns the callback result when no exception is thrown', function () {
    $result = LockRetry::run(fn () => 'expected-value');

    expect($result)->toBe('expected-value');
});

it('LockRetry::run() retries on lock wait timeout QueryException and succeeds', function () {
    config(['inventorix.locking.lock_retry' => 3, 'inventorix.locking.lock_retry_base_ms' => 0]);

    $calls = 0;
    $lockException = new QueryException(
        'mysql',
        'SELECT 1',
        [],
        new PDOException('Lock wait timeout exceeded; try restarting transaction', 1205)
    );

    $result = LockRetry::run(function () use (&$calls, $lockException) {
        $calls++;
        if ($calls === 1) {
            throw $lockException;
        }

        return 'success-after-retry';
    });

    expect($result)->toBe('success-after-retry')
        ->and($calls)->toBe(2);
});

it('LockRetry::run() re-throws after max retries are exhausted', function () {
    config(['inventorix.locking.lock_retry' => 2, 'inventorix.locking.lock_retry_base_ms' => 0]);

    $calls = 0;
    $lockException = new QueryException(
        'mysql',
        'SELECT 1',
        [],
        new PDOException('Lock wait timeout exceeded; try restarting transaction', 1205)
    );

    LockRetry::run(function () use (&$calls, $lockException) {
        $calls++;
        throw $lockException;
    });

    // lock_retry = 2 means 1 initial attempt + 2 retries = 3 total calls
    expect($calls)->toBe(3);
})->throws(QueryException::class);

it('LockRetry::run() does not retry non-lock exceptions', function () {
    config(['inventorix.locking.lock_retry' => 3, 'inventorix.locking.lock_retry_base_ms' => 0]);

    $calls = 0;
    $nonLockException = new QueryException(
        'mysql',
        'SELECT 1',
        [],
        new PDOException('SQLSTATE[23000]: Integrity constraint violation', 23000)
    );

    LockRetry::run(function () use (&$calls, $nonLockException) {
        $calls++;
        throw $nonLockException;
    });

    expect($calls)->toBe(1);
})->throws(QueryException::class);

it('LockRetry::run() respects lock_retry = 0 (no retry)', function () {
    config(['inventorix.locking.lock_retry' => 0, 'inventorix.locking.lock_retry_base_ms' => 0]);

    $calls = 0;
    $lockException = new QueryException(
        'mysql',
        'SELECT 1',
        [],
        new PDOException('Lock wait timeout exceeded; try restarting transaction', 1205)
    );

    LockRetry::run(function () use (&$calls, $lockException) {
        $calls++;
        throw $lockException;
    });

    expect($calls)->toBe(1);
})->throws(QueryException::class);
