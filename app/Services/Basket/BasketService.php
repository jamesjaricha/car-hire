<?php

declare(strict_types=1);

namespace App\Services\Basket;

use App\Contracts\BasketServiceContract;
use App\Contracts\SettingsRepositoryContract;
use App\DataTransferObjects\Basket;
use App\DataTransferObjects\SearchCriteria;
use App\Enums\SettingKey;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;

/**
 * Keeps a guest's basket in the session.
 *
 * The session is the right home for this. It requires no account, it survives
 * the sign-in prompt spec §14.1 insists on, and it disappears on its own — no
 * table of abandoned baskets to sweep, and no half-formed bookings appearing in
 * the admin panel.
 *
 * The search criteria are stored under a separate key on purpose. They outlive
 * the basket so that an expiry returns the customer to search with their dates
 * intact, rather than to an empty form.
 */
final class BasketService implements BasketServiceContract
{
    private const BASKET_KEY = 'carhire.basket';

    private const SEARCH_KEY = 'carhire.last_search';

    public function __construct(
        private readonly Session $session,
        private readonly SettingsRepositoryContract $settings,
    ) {}

    public function place(Basket $basket): void
    {
        $this->session->put(self::BASKET_KEY, $basket->toArray());

        // Adding to a basket implies these were the criteria that found it.
        $this->rememberSearch(new SearchCriteria(
            pickupBranchId: $basket->pickupBranchId,
            dropoffBranchId: $basket->dropoffBranchId,
            range: $basket->range,
        ));
    }

    public function current(?CarbonImmutable $now = null): ?Basket
    {
        $basket = $this->stored();

        if (! $basket instanceof Basket) {
            return null;
        }

        if ($basket->hasExpired($this->ttlMinutes(), $now)) {
            // Clear the basket but keep the search criteria, so the customer
            // lands back on search with their dates already filled in.
            $this->session->forget(self::BASKET_KEY);

            return null;
        }

        return $basket;
    }

    public function touch(?CarbonImmutable $now = null): ?Basket
    {
        $now ??= CarbonImmutable::now();

        $basket = $this->current($now);

        if (! $basket instanceof Basket) {
            return null;
        }

        $refreshed = $basket->touchedAt($now);

        $this->session->put(self::BASKET_KEY, $refreshed->toArray());

        return $refreshed;
    }

    public function forget(): void
    {
        $this->session->forget(self::BASKET_KEY);
    }

    public function rememberSearch(SearchCriteria $criteria): void
    {
        $this->session->put(self::SEARCH_KEY, $criteria->toArray());
    }

    public function lastSearch(): ?SearchCriteria
    {
        $data = $this->session->get(self::SEARCH_KEY);

        if (! is_array($data)) {
            return null;
        }

        return SearchCriteria::fromArray($data);
    }

    public function expiresAt(?CarbonImmutable $now = null): ?CarbonImmutable
    {
        return $this->current($now)?->expiresAt($this->ttlMinutes());
    }

    public function ttlMinutes(): int
    {
        return $this->settings->integer(SettingKey::BasketTtlMinutes, 30) ?? 30;
    }

    /**
     * Rehydrate whatever is in the session, tolerating rubbish.
     *
     * A basket written by an older deploy, or a session tampered with, must
     * present as "no basket" rather than throwing a customer into a 500 page
     * with their trip half-booked.
     */
    private function stored(): ?Basket
    {
        $data = $this->session->get(self::BASKET_KEY);

        if (! is_array($data)) {
            return null;
        }

        try {
            return Basket::fromArray($data);
        } catch (\Throwable) {
            $this->session->forget(self::BASKET_KEY);

            return null;
        }
    }
}
