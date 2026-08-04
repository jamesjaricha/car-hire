<?php

declare(strict_types=1);

namespace App\Console\Concerns;

use Carbon\CarbonImmutable;

/**
 * Lets several independently-launched processes act at the same instant.
 *
 * Concurrency tests spawn processes in a loop, but each spends a few hundred
 * milliseconds booting Laravel before it reaches the code under test. Without a
 * shared rendezvous the first can finish before the last has even connected, and
 * the test passes having demonstrated nothing at all.
 *
 * Each process therefore boots, then sleeps here until a timestamp they were all
 * given. Whatever contention the code has is then real.
 */
trait WaitsForBarrier
{
    private function waitForBarrier(?string $notBefore): void
    {
        if (! is_string($notBefore) || $notBefore === '') {
            return;
        }

        $targetMicros = (float) CarbonImmutable::parse($notBefore)->format('U.u');
        $delayMicros = (int) (($targetMicros - microtime(true)) * 1_000_000);

        if ($delayMicros > 0) {
            usleep($delayMicros);
        }
    }
}
