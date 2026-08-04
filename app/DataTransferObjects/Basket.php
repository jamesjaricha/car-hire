<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use Carbon\CarbonImmutable;

/**
 * What a guest has chosen, and the price they were promised for it.
 *
 * Spec §1.1: the basket lives in the session for thirty minutes of inactivity,
 * and the quoted price is guaranteed for its whole life. That guarantee is why
 * the quote is stored here rather than recomputed on each page — recomputing
 * would silently honour a rate change mid-checkout, which is precisely the
 * behaviour §1.2 forbids.
 *
 * Everything is flattened to scalars for the session. Storing the objects
 * themselves would work until a deploy changed one of these classes, at which
 * point every live basket would fail to unserialise for a customer standing
 * mid-checkout.
 */
final readonly class Basket
{
    public function __construct(
        public int $vehicleId,
        public int $pickupBranchId,
        public int $dropoffBranchId,
        public DateRange $range,
        public QuoteOptions $options,

        /** Frozen at the moment of adding. Never recomputed. */
        public Quote $quote,

        public CarbonImmutable $createdAt,

        /** Refreshed on activity. The thirty minutes runs from here. */
        public CarbonImmutable $lastActiveAt,
    ) {}

    public static function start(
        int $vehicleId,
        int $pickupBranchId,
        int $dropoffBranchId,
        DateRange $range,
        QuoteOptions $options,
        Quote $quote,
        ?CarbonImmutable $now = null,
    ): self {
        $now ??= CarbonImmutable::now();

        return new self(
            vehicleId: $vehicleId,
            pickupBranchId: $pickupBranchId,
            dropoffBranchId: $dropoffBranchId,
            range: $range,
            options: $options,
            quote: $quote,
            createdAt: $now,
            lastActiveAt: $now,
        );
    }

    public function touchedAt(CarbonImmutable $now): self
    {
        return new self(
            vehicleId: $this->vehicleId,
            pickupBranchId: $this->pickupBranchId,
            dropoffBranchId: $this->dropoffBranchId,
            range: $this->range,
            options: $this->options,
            quote: $this->quote,
            createdAt: $this->createdAt,
            lastActiveAt: $now,
        );
    }

    public function hasExpired(int $ttlMinutes, ?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();

        return $this->lastActiveAt->addMinutes($ttlMinutes)->lessThanOrEqualTo($now);
    }

    public function expiresAt(int $ttlMinutes): CarbonImmutable
    {
        return $this->lastActiveAt->addMinutes($ttlMinutes);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'vehicle_id' => $this->vehicleId,
            'pickup_branch_id' => $this->pickupBranchId,
            'dropoff_branch_id' => $this->dropoffBranchId,
            'start_at' => $this->range->start->toIso8601String(),
            'end_at' => $this->range->end->toIso8601String(),
            'options' => $this->options->toArray(),
            'quote' => $this->quote->toArray(),
            'created_at' => $this->createdAt->toIso8601String(),
            'last_active_at' => $this->lastActiveAt->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        // Checked explicitly rather than left to a type error further down.
        // Session data can be stale from an earlier deploy or tampered with,
        // and "missing key" must be an unambiguous failure the caller can
        // catch — not a warning that yields a basket for vehicle zero.
        foreach ([
            'vehicle_id', 'pickup_branch_id', 'dropoff_branch_id',
            'start_at', 'end_at', 'quote', 'created_at', 'last_active_at',
        ] as $required) {
            if (! array_key_exists($required, $data)) {
                throw new \InvalidArgumentException(
                    "Stored basket is missing [{$required}] and cannot be restored."
                );
            }
        }

        /** @var array<string, mixed> $options */
        $options = $data['options'] ?? [];
        /** @var array<string, mixed> $quote */
        $quote = $data['quote'];

        return new self(
            vehicleId: (int) $data['vehicle_id'],
            pickupBranchId: (int) $data['pickup_branch_id'],
            dropoffBranchId: (int) $data['dropoff_branch_id'],
            range: DateRange::of((string) $data['start_at'], (string) $data['end_at']),
            options: QuoteOptions::fromArray($options),
            quote: Quote::fromArray($quote),
            createdAt: CarbonImmutable::parse((string) $data['created_at'])->utc(),
            lastActiveAt: CarbonImmutable::parse((string) $data['last_active_at'])->utc(),
        );
    }
}
