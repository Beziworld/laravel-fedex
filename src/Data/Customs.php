<?php

declare(strict_types=1);

namespace Beziworld\Fedex\Data;

/**
 * Customs clearance detail of an international shipment, with a FedEx-generated commercial invoice.
 */
final readonly class Customs
{
    /**
     * @param list<CustomsItem> $items
     * @param string $dutiesPaidBy SENDER, RECIPIENT or THIRD_PARTY.
     * @param string $purpose SOLD, GIFT, NOT_SOLD, PERSONAL_EFFECTS, REPAIR_AND_RETURN or SAMPLE.
     * @param string|null $termsOfSale Incoterm, e.g. DAP or DDP.
     * @param string|null $dutiesAccountNumber Account paying duties when they are billed to a third party or the recipient.
     */
    public function __construct(
        public array $items,
        public string $currency,
        public string $dutiesPaidBy = 'RECIPIENT',
        public string $purpose = 'SOLD',
        public ?string $termsOfSale = null,
        public ?string $invoiceNumber = null,
        public bool $documentsOnly = false,
        public bool $generateInvoice = true,
        public ?string $dutiesAccountNumber = null,
        public ?string $comments = null,
    ) {}

    public function total(): Money
    {
        $total = 0.0;

        foreach ($this->items as $item) {
            $total += $item->customsValue();
        }

        return Money::of($total, $this->currency);
    }
}
