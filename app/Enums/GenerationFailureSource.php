<?php

namespace App\Enums;

enum GenerationFailureSource: string
{
    /** Pixelforge accepted the generation and reported that it failed. */
    case Provider = 'provider';

    /** We stopped before Pixelforge accepted the generation (rejected or abandoned submission). */
    case Local = 'local';
}
