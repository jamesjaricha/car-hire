<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DataTransferObjects\Basket;
use App\DataTransferObjects\SearchCriteria;
use Carbon\CarbonImmutable;

/**
 * The guest's basket, held in the session. Spec §1.1.
 *
 * Two rules the whole thing exists to keep:
 *
 *  1. **The price is frozen.** Whatever was quoted when the vehicle was added is
 *     what the customer pays, for the life of the basket. Nothing re-prices it.
 *  2. **Thirty minutes of inactivity ends it** — inactivity, not age. A customer
 *     still working through checkout must not have the basket pulled out from
 *     under them at the thirty-minute mark.
 *
 * No account is required, and none is created. A basket is anonymous until
 * checkout asks for contact details, which is the whole point of §1.3.
 */
interface BasketServiceContract
{
    public function place(Basket $basket): void;

    /**
     * The live basket, or null if there is none or it has expired.
     *
     * Reading an expired basket also clears it, while keeping the search
     * criteria so the customer can be returned to search with their dates
     * still filled in.
     */
    public function current(?CarbonImmutable $now = null): ?Basket;

    /**
     * Mark activity, restarting the inactivity window.
     *
     * Returns the refreshed basket, or null if there was nothing live to touch.
     */
    public function touch(?CarbonImmutable $now = null): ?Basket;

    public function forget(): void;

    /**
     * Remember what the customer was searching for, independently of the
     * basket, so it survives the basket expiring.
     */
    public function rememberSearch(SearchCriteria $criteria): void;

    public function lastSearch(): ?SearchCriteria;

    /**
     * When the current basket lapses, or null if there is no live basket.
     */
    public function expiresAt(?CarbonImmutable $now = null): ?CarbonImmutable;

    public function ttlMinutes(): int;
}
