<?php

declare(strict_types=1);

namespace Beziworld\Fedex\Enums;

/**
 * FedEx packaging types.
 */
enum Packaging: string
{
    case YourPackaging = 'YOUR_PACKAGING';
    case Envelope = 'FEDEX_ENVELOPE';
    case Pak = 'FEDEX_PAK';
    case Box = 'FEDEX_BOX';
    case ExtraSmallBox = 'FEDEX_EXTRA_SMALL_BOX';
    case SmallBox = 'FEDEX_SMALL_BOX';
    case MediumBox = 'FEDEX_MEDIUM_BOX';
    case LargeBox = 'FEDEX_LARGE_BOX';
    case ExtraLargeBox = 'FEDEX_EXTRA_LARGE_BOX';
    case Tube = 'FEDEX_TUBE';
    case Box10Kg = 'FEDEX_10KG_BOX';
    case Box25Kg = 'FEDEX_25KG_BOX';
}
