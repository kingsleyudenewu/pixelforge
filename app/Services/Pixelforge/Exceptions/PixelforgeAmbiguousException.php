<?php

namespace App\Services\Pixelforge\Exceptions;

/**
 * We can't tell whether Pixelforge acted on the request (timeouts, connection drops, unexpected
 * server errors, or a reused idempotency key). For a create, the generation may exist and be billed,
 * so it must be resolved by replaying with the same Idempotency-Key — never assumed to have failed.
 */
class PixelforgeAmbiguousException extends PixelforgeException {}
