<?php

use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Services\Billing\PriceCalculator;
use App\Services\Pixelforge\PricingSchedule;

function documentedPricing(): PricingSchedule
{
    return PricingSchedule::fromApi(json_decode(file_get_contents(__DIR__.'/../../../Fixtures/pixelforge/pricing.json'), true));
}

test('the maximum estimate matches the worst case worked example in the Pixelforge docs', function () {
    $calculator = new PriceCalculator(markupBasisPoints: 2500);

    $estimate = $calculator->estimate(documentedPricing(), str_repeat('a', 400), ImageSize::Landscape, ImageQuality::High, 2);

    expect($estimate)
        ->maxTextTokens->toBe(115)
        ->imageTokens->toBe(1530)
        ->outputTokens->toBe(6450)
        ->maxReasoningTokens->toBe(8000)
        ->maxProviderCostNanos->toBe(373_875_000)
        ->maxChargeNanos->toBe(467_343_750);
});

test('the minimum estimate uses the low end of text variance and reasoning tokens', function () {
    $calculator = new PriceCalculator(markupBasisPoints: 2500);

    $estimate = $calculator->estimate(documentedPricing(), str_repeat('a', 400), ImageSize::Landscape, ImageQuality::High, 2);

    // 85 text × $5 + 1530 image × $10 + 6450 output × $40 + 1000 reasoning × $12.50, per 1M tokens.
    expect($estimate)
        ->minTextTokens->toBe(85)
        ->minReasoningTokens->toBe(1000)
        ->minProviderCostNanos->toBe(286_225_000)
        ->minChargeNanos->toBe(357_781_250);
});

test('text tokens are counted per character rather than per byte', function () {
    $calculator = new PriceCalculator(markupBasisPoints: 2500);

    $estimate = $calculator->estimate(documentedPricing(), str_repeat('é', 8), ImageSize::Square, ImageQuality::Low, 0);

    expect($estimate->maxTextTokens)->toBe(3);
});

test('provider cost matches the daily total in the Pixelforge usage example', function () {
    $calculator = new PriceCalculator(markupBasisPoints: 2500);

    $cost = $calculator->providerCost(
        ['input_text_tokens' => 41, 'input_image_tokens' => 0, 'output_tokens' => 3300, 'reasoning_tokens' => 2950],
        ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
    );

    expect($cost)->toBe(169_080_000);
});

test('the markup rounds a fractional nanodollar up', function () {
    $calculator = new PriceCalculator(markupBasisPoints: 2500);

    expect($calculator->withMarkup(3))->toBe(4);
});

test('the charge never exceeds the quoted maximum', function (int $providerCost, int $quotedMaximum, int $charge) {
    $calculator = new PriceCalculator(markupBasisPoints: 2500);

    expect($calculator->charge($providerCost, $quotedMaximum))->toBe($charge);
})->with([
    'actual cost below the quote' => [200_000_000, 467_343_750, 250_000_000],
    'actual cost above the quote' => [400_000_000, 467_343_750, 467_343_750],
]);
