<?php

namespace App\Services\Pixelforge;

/**
 * The token categories Pixelforge bills, as named in `usage` and `rates`.
 */
enum TokenType: string
{
    case InputText = 'input_text_tokens';
    case InputImage = 'input_image_tokens';
    case Output = 'output_tokens';
    case Reasoning = 'reasoning_tokens';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
