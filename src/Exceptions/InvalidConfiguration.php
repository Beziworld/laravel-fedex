<?php

declare(strict_types=1);

namespace Beziworld\Fedex\Exceptions;

/**
 * Raised when the package configuration or a request built by the application is incomplete.
 */
final class InvalidConfiguration extends FedexException
{
    public static function missing(string $key, string $env): self
    {
        return new self(sprintf('FedEx %s is not configured. Set %s in the environment.', $key, $env));
    }

    public static function unknownConnection(string $name): self
    {
        return new self(sprintf('FedEx connection "%s" is not defined in config/fedex.php.', $name));
    }

    public static function invalid(string $message): self
    {
        return new self($message);
    }
}
