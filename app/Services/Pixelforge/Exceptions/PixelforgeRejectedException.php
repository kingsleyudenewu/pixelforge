<?php

namespace App\Services\Pixelforge\Exceptions;

/**
 * Pixelforge definitively refused the request: nothing was created and nothing will be billed.
 * Retrying the same request will not help (e.g. content policy, validation, daily limit).
 */
class PixelforgeRejectedException extends PixelforgeException {}
