<?php

declare(strict_types=1);

namespace Beziworld\Fedex\Enums;

/**
 * Normalised package status derived from the FedEx status code (derivedCode, eventType or eventCode).
 */
enum TrackingStatus: string
{
    case LabelCreated = 'label_created';
    case PickedUp = 'picked_up';
    case InTransit = 'in_transit';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';
    case Exception = 'exception';
    case Cancelled = 'cancelled';
    case ReturnedToShipper = 'returned_to_shipper';
    case Unknown = 'unknown';

    public static function fromCode(?string $code): self
    {
        return match (strtoupper((string) $code)) {
            '' => self::Unknown,
            'OC' => self::LabelCreated,
            'PU' => self::PickedUp,
            'OD' => self::OutForDelivery,
            'DL' => self::Delivered,
            'DE', 'SE', 'DD', 'CD' => self::Exception,
            'CA' => self::Cancelled,
            'RS' => self::ReturnedToShipper,
            default => self::InTransit,
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Delivered, self::Cancelled, self::ReturnedToShipper], true);
    }
}
