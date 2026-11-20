<?php

namespace App\Services\Billing;

/**
 * The range a generation can cost before it runs. Text and reasoning tokens are only known
 * after completion, so every figure has a minimum and a maximum. The maximum user charge is
 * the binding quote: it is held from the balance and never exceeded.
 */
final readonly class PriceEstimate
{
    public function __construct(
        public int $minTextTokens,
        public int $maxTextTokens,
        public int $imageTokens,
        public int $outputTokens,
        public int $minReasoningTokens,
        public int $maxReasoningTokens,
        public int $minProviderCostNanos,
        public int $maxProviderCostNanos,
        public int $minChargeNanos,
        public int $maxChargeNanos,
    ) {}
}
