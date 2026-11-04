<?php

declare(strict_types=1);

namespace Beziworld\Fedex\Enums;

/**
 * Label image types of the FedEx Ship API.
 */
enum LabelFormat: string
{
    case Pdf = 'PDF';
    case Png = 'PNG';
    case Zpl = 'ZPLII';
    case Epl = 'EPL2';

    public function isThermal(): bool
    {
        return $this === self::Zpl || $this === self::Epl;
    }

    /**
     * Default label stock: 4x6 inch paper for images, 4x6 inch thermal stock for printer languages.
     */
    public function defaultStock(): string
    {
        return $this->isThermal() ? 'STOCK_4X6' : 'PAPER_4X6';
    }

    public function extension(): string
    {
        return match ($this) {
            self::Pdf => 'pdf',
            self::Png => 'png',
            self::Zpl => 'zpl',
            self::Epl => 'epl',
        };
    }

    public function mimeType(): string
    {
        return match ($this) {
            self::Pdf => 'application/pdf',
            self::Png => 'image/png',
            default => 'text/plain',
        };
    }
}
