<?php

namespace App\Services\Pixelforge\Exceptions;

use RuntimeException;
use Throwable;

abstract class PixelforgeException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $errorCode = null,
        public readonly ?int $status = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
