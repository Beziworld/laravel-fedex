<?php

declare(strict_types=1);

namespace Beziworld\Fedex\Exceptions;

use RuntimeException;

/**
 * Base exception for every error raised by the FedEx integration.
 */
class FedexException extends RuntimeException {}
