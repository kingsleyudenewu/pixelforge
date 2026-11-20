<?php

namespace App\Services\Pixelforge\Exceptions;

use Throwable;

/**
 * Pixelforge (or our own request budget) refused the request for now: nothing was created,
 * and the same request may succeed after waiting (rate or concurrency limits, overload).
 */
class PixelforgeTransientException extends PixelforgeException
{
    public function __construct(
        string $message,
        ?string $errorCode = null,
        ?int $status = null,
        public readonly int $retryAfterSeconds = 5,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, $status, $previous);
    }
}
