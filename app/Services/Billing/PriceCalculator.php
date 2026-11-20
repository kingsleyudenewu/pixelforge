<?php

namespace App\Services\Billing;

use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Services\Pixelforge\PricingSchedule;
use App\Services\Pixelforge\TokenType;
use App\Support\Nanodollars;
use Illuminate\Container\Attributes\Config;
use InvalidArgumentException;

/**
 * Prices generations in integer nanodollars. Mirrored for display by resources/js/lib/pricing.ts.
 */
final class PriceCalculator
{
    private const int BASIS_POINTS = 10_000;

    public function __construct(
        #[Config('billing.markup_basis_points')]
        private readonly int $markupBasisPoints,
    ) {}

    /**
     * Estimate what a generation will cost, using Pixelforge's published token rules.
     */
    public function estimate(
        PricingSchedule $pricing,
        string $prompt,
        ImageSize $size,
        ImageQuality $quality,
        int $referenceImageCount,
    ): PriceEstimate {
        $expectedTextTokens = (int) ceil(mb_strlen($prompt) / $pricing->charactersPerTextToken);
        $variance = $pricing->textTokenVarianceBasisPoints;

        $minTextTokens = intdiv($expectedTextTokens * (self::BASIS_POINTS - $variance), self::BASIS_POINTS);
        $maxTextTokens = $this->divideRoundingUp($expectedTextTokens * (self::BASIS_POINTS + $variance), self::BASIS_POINTS);
        $imageTokens = $referenceImageCount * $pricing->tokensPerReferenceImage;
        $outputTokens = $pricing->outputTokens[$quality->value][$size->value]
            ?? throw new InvalidArgumentException("No output token price for [{$quality->value}, {$size->value}].");
        $reasoning = $pricing->reasoningTokens[$quality->value]
            ?? throw new InvalidArgumentException("No reasoning token range for [{$quality->value}].");

        $minProviderCost = $this->providerCost([
            TokenType::InputText->value => $minTextTokens,
            TokenType::InputImage->value => $imageTokens,
            TokenType::Output->value => $outputTokens,
            TokenType::Reasoning->value => $reasoning['min'],
        ], $pricing->rates);

        $maxProviderCost = $this->providerCost([
            TokenType::InputText->value => $maxTextTokens,
            TokenType::InputImage->value => $imageTokens,
            TokenType::Output->value => $outputTokens,
            TokenType::Reasoning->value => $reasoning['max'],
        ], $pricing->rates);

        return new PriceEstimate(
            minTextTokens: $minTextTokens,
            maxTextTokens: $maxTextTokens,
            imageTokens: $imageTokens,
            outputTokens: $outputTokens,
            minReasoningTokens: $reasoning['min'],
            maxReasoningTokens: $reasoning['max'],
            minProviderCostNanos: $minProviderCost,
            maxProviderCostNanos: $maxProviderCost,
            minChargeNanos: $this->withMarkup($minProviderCost),
            maxChargeNanos: $this->withMarkup($maxProviderCost),
        );
    }

    /**
     * What Pixelforge charges for the given token counts at the given per-1M-token rates.
     *
     * @param  array<string, int>  $tokens  Token counts keyed by token type.
     * @param  array<string, string>  $rates  Dollars per 1M tokens keyed by token type.
     */
    public function providerCost(array $tokens, array $rates): int
    {
        $total = 0;

        foreach (TokenType::values() as $type) {
            $count = $tokens[$type] ?? 0;

            if ($count === 0) {
                continue;
            }

            $rate = $rates[$type] ?? throw new InvalidArgumentException("No rate for [{$type}].");
            $total += $count * Nanodollars::fromDecimal($rate);
        }

        // Rates are per million tokens. With today's rates this always divides exactly (Pixelforge
        // never rounds); rounding half up only matters if a future rate carries more decimals.
        return intdiv($total + 500_000, 1_000_000);
    }

    /**
     * The user price for a provider cost: cost plus markup, rounded up to the next nanodollar.
     */
    public function withMarkup(int $providerCostNanos): int
    {
        return $this->divideRoundingUp($providerCostNanos * (self::BASIS_POINTS + $this->markupBasisPoints), self::BASIS_POINTS);
    }

    /**
     * What a finished generation costs the user: the marked-up actual cost, never more than the
     * maximum they were quoted (and which we held) when they submitted.
     */
    public function charge(int $providerCostNanos, int $quotedMaximumNanos): int
    {
        return min($this->withMarkup($providerCostNanos), $quotedMaximumNanos);
    }

    private function divideRoundingUp(int $numerator, int $denominator): int
    {
        return intdiv($numerator + $denominator - 1, $denominator);
    }
}
