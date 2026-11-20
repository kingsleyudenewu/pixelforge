import { toNanos } from '@/lib/money';
import type { Pricing } from '@/types';

/** All amounts are whole nanodollars; token counts are whole tokens. */
export type PriceEstimate = {
    minTextTokens: number;
    maxTextTokens: number;
    imageTokens: number;
    outputTokens: number;
    minReasoningTokens: number;
    maxReasoningTokens: number;
    minProviderCost: number;
    maxProviderCost: number;
    minCharge: number;
    maxCharge: number;
};

type EstimateInput = {
    prompt: string;
    size: string;
    quality: string;
    referenceImageCount: number;
};

const BASIS_POINTS = 10_000;

/**
 * Characters as Pixelforge and PHP's mb_strlen() count them: Unicode code points, not UTF-16
 * units (String.length) or graphemes. An emoji built from several code points counts as several.
 */
export function characterCount(text: string): number {
    return Array.from(text).length;
}

/**
 * Estimate a generation's price in the browser, so it updates as the user types without a request.
 * Mirrors App\Services\Billing\PriceCalculator, which re-quotes authoritatively on submit.
 */
export function estimateGeneration(
    pricing: Pricing,
    markupBasisPoints: number,
    input: EstimateInput,
): PriceEstimate | null {
    const outputTokens = pricing.output_tokens[input.quality]?.[input.size];
    const reasoning = pricing.reasoning_tokens[input.quality];

    if (outputTokens === undefined || reasoning === undefined) {
        return null;
    }

    const characters = characterCount(input.prompt.trim());
    const expectedTextTokens = Math.ceil(
        characters / pricing.characters_per_text_token,
    );
    const variance = pricing.text_token_variance_basis_points;

    const minTextTokens = Math.floor(
        (expectedTextTokens * (BASIS_POINTS - variance)) / BASIS_POINTS,
    );
    const maxTextTokens = Math.ceil(
        (expectedTextTokens * (BASIS_POINTS + variance)) / BASIS_POINTS,
    );
    const imageTokens =
        input.referenceImageCount * pricing.tokens_per_reference_image;

    const cost = (textTokens: number, reasoningTokens: number) =>
        providerCost(pricing.rates, {
            input_text_tokens: textTokens,
            input_image_tokens: imageTokens,
            output_tokens: outputTokens,
            reasoning_tokens: reasoningTokens,
        });

    const minProviderCost = cost(minTextTokens, reasoning.min);
    const maxProviderCost = cost(maxTextTokens, reasoning.max);

    return {
        minTextTokens,
        maxTextTokens,
        imageTokens,
        outputTokens,
        minReasoningTokens: reasoning.min,
        maxReasoningTokens: reasoning.max,
        minProviderCost,
        maxProviderCost,
        minCharge: withMarkup(minProviderCost, markupBasisPoints),
        maxCharge: withMarkup(maxProviderCost, markupBasisPoints),
    };
}

function providerCost(
    rates: Record<string, string>,
    tokens: Record<string, number>,
): number {
    let total = 0;

    for (const [type, count] of Object.entries(tokens)) {
        if (count > 0) {
            total += count * toNanos(rates[type] ?? '0');
        }
    }

    // Rates are dollars per million tokens.
    return Math.floor((total + 500_000) / 1_000_000);
}

function withMarkup(
    providerCostNanos: number,
    markupBasisPoints: number,
): number {
    return Math.ceil(
        (providerCostNanos * (BASIS_POINTS + markupBasisPoints)) / BASIS_POINTS,
    );
}
