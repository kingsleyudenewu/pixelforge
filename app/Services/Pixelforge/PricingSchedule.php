<?php

namespace App\Services\Pixelforge;

use Illuminate\Support\Arr;
use InvalidArgumentException;

/**
 * Pixelforge's public price list (GET /v1/pricing): per-1M-token rates plus the token tables
 * needed to estimate a generation's cost before it runs.
 */
final readonly class PricingSchedule
{
    /**
     * @param  array<string, string>  $rates  Dollars per 1M tokens keyed by token type.
     * @param  array<string, array<string, int>>  $outputTokens  Fixed output tokens keyed by quality, then size.
     * @param  array<string, array{min: int, max: int}>  $reasoningTokens  Reasoning token range keyed by quality.
     */
    public function __construct(
        public array $rates,
        public int $charactersPerTextToken,
        public int $textTokenVarianceBasisPoints,
        public int $tokensPerReferenceImage,
        public array $outputTokens,
        public array $reasoningTokens,
    ) {}

    /**
     * @param  array<mixed>  $payload  The GET /v1/pricing response body.
     */
    public static function fromApi(array $payload): self
    {
        $rates = Arr::get($payload, 'rates');
        $output = Arr::get($payload, 'tokens.output');
        $reasoning = Arr::get($payload, 'tokens.reasoning');

        if (! is_array($rates) || ! is_array($output) || ! is_array($reasoning)) {
            throw new InvalidArgumentException('Pixelforge pricing payload is incomplete.');
        }

        return new self(
            rates: array_map(strval(...), Arr::only($rates, TokenType::values())),
            charactersPerTextToken: (int) Arr::get($payload, 'tokens.characters_per_text_token', 4),
            // The variance is a ratio (0.15); hold it as whole basis points so estimates stay in integer maths.
            textTokenVarianceBasisPoints: (int) round((float) Arr::get($payload, 'tokens.text_token_variance', 0.15) * 10_000),
            tokensPerReferenceImage: (int) Arr::get($payload, 'tokens.per_reference_image', 765),
            outputTokens: array_map(
                fn (mixed $sizes): array => array_map(intval(...), (array) $sizes),
                $output,
            ),
            reasoningTokens: array_map(
                fn (mixed $range): array => ['min' => (int) Arr::get((array) $range, 'min', 0), 'max' => (int) Arr::get((array) $range, 'max', 0)],
                $reasoning,
            ),
        );
    }

    /**
     * Shape shared with the frontend estimator (resources/js/lib/pricing.ts).
     *
     * @return array{rates: array<string, string>, characters_per_text_token: int, text_token_variance_basis_points: int, tokens_per_reference_image: int, output_tokens: array<string, array<string, int>>, reasoning_tokens: array<string, array{min: int, max: int}>}
     */
    public function toArray(): array
    {
        return [
            'rates' => $this->rates,
            'characters_per_text_token' => $this->charactersPerTextToken,
            'text_token_variance_basis_points' => $this->textTokenVarianceBasisPoints,
            'tokens_per_reference_image' => $this->tokensPerReferenceImage,
            'output_tokens' => $this->outputTokens,
            'reasoning_tokens' => $this->reasoningTokens,
        ];
    }
}
