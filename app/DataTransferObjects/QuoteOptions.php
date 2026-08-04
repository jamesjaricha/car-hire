<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Support\Money;

/**
 * The optional extras a customer has chosen, priced.
 *
 * Extras and cross-border are passed in as already-totalled amounts rather than
 * calculated here, because neither has a table yet: the extras catalogue
 * arrives with the customer-facing work, and cross-border pricing is a spec §15
 * open item awaiting the list of supported countries.
 *
 * Both therefore default to zero, which keeps the quote arithmetic complete and
 * correct today and means nothing has to be restructured when the real data
 * lands — only this object gets populated differently.
 */
final readonly class QuoteOptions
{
    public function __construct(
        public string $extrasTotal = Money::ZERO,
        public string $crossBorderTotal = Money::ZERO,
        public ?string $crossBorderCountry = null,
    ) {}

    public static function none(): self
    {
        return new self;
    }

    public function includesCrossBorder(): bool
    {
        return $this->crossBorderCountry !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'extras_total' => $this->extrasTotal,
            'cross_border_total' => $this->crossBorderTotal,
            'cross_border_country' => $this->crossBorderCountry,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            extrasTotal: (string) ($data['extras_total'] ?? Money::ZERO),
            crossBorderTotal: (string) ($data['cross_border_total'] ?? Money::ZERO),
            crossBorderCountry: isset($data['cross_border_country'])
                ? (string) $data['cross_border_country']
                : null,
        );
    }
}
