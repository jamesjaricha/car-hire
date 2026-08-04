<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

/**
 * What a customer was looking for.
 *
 * Kept in the session separately from the basket, and deliberately outliving it.
 * Spec §1.1: when a basket expires the customer is returned to search "with
 * their dates pre-filled" — which is impossible if the dates only existed inside
 * the thing that just expired. Making someone re-enter their trip because a
 * timer ran out is how a booking becomes an abandoned session.
 */
final readonly class SearchCriteria
{
    public function __construct(
        public int $pickupBranchId,
        public int $dropoffBranchId,
        public DateRange $range,
        public ?int $vehicleClassId = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'pickup_branch_id' => $this->pickupBranchId,
            'dropoff_branch_id' => $this->dropoffBranchId,
            'start_at' => $this->range->start->toIso8601String(),
            'end_at' => $this->range->end->toIso8601String(),
            'vehicle_class_id' => $this->vehicleClassId,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            pickupBranchId: (int) $data['pickup_branch_id'],
            dropoffBranchId: (int) $data['dropoff_branch_id'],
            range: DateRange::of((string) $data['start_at'], (string) $data['end_at']),
            vehicleClassId: isset($data['vehicle_class_id'])
                ? (int) $data['vehicle_class_id']
                : null,
        );
    }
}
