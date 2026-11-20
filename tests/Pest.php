<?php

use App\Models\Generation;
use App\Services\Billing\CreditLedger;
use Database\Factories\GenerationFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        config([
            'services.pixelforge.key' => 'pf_test_key',
            'services.pixelforge.webhook_secret' => 'whsec_test_secret',
        ]);

        // No test may reach the real Pixelforge. Its public price list is ambient configuration
        // that most pages read, so it is faked for every feature test.
        Http::preventStrayRequests();
        Http::fake(['pixelforge.promptedit.dev/v1/pricing' => Http::response(pixelforgeFixture('pricing.json'))]);
    })
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * @return array<string, mixed>
 */
function pixelforgeFixture(string $name): array
{
    return json_decode(file_get_contents(__DIR__.'/Fixtures/pixelforge/'.$name), true);
}

/**
 * Hold a generation's quoted maximum from its owner's balance, as CreateGeneration does.
 */
function holdCreditsFor(Generation $generation): Generation
{
    app(CreditLedger::class)->reserve($generation->user, $generation, $generation->estimated_charge_max_nanos);

    return $generation;
}

/**
 * A Pixelforge generation object for a local generation, as the API returns it and webhooks embed it.
 *
 * A succeeded snapshot used 12 text, 1,100 output and 1,500 reasoning tokens: $0.06281 from Pixelforge,
 * so $0.0785125 for the user.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function pixelforgeGeneration(Generation $generation, string $status, array $overrides = []): array
{
    $snapshot = [
        'id' => $generation->pixelforge_id ?? 'gen_01JC2Z8Y5Q2W3E4R5T6Y7U8I9O',
        'object' => 'generation',
        'status' => $status,
        'prompt' => $generation->prompt,
        'size' => $generation->size->value,
        'quality' => $generation->quality->value,
        'reference_images' => $generation->reference_images,
        'metadata' => $generation->request_payload['metadata'] ?? [],
        'image_url' => null,
        'image_url_expires_at' => null,
        'usage' => null,
        'rates' => GenerationFactory::RATES,
        'error' => null,
        'created_at' => '2026-11-19T09:59:58Z',
        'started_at' => null,
        'completed_at' => null,
    ];

    $snapshot = match ($status) {
        'processing' => [...$snapshot, 'started_at' => '2026-11-19T10:00:00Z'],
        'succeeded' => [
            ...$snapshot,
            'started_at' => '2026-11-19T10:00:00Z',
            'completed_at' => '2026-11-19T10:00:16Z',
            'image_url' => 'https://pixelforge.promptedit.dev/images/gen.png?signature=abc',
            'image_url_expires_at' => '2026-11-20T10:00:16Z',
            'usage' => [
                'input_text_tokens' => 12,
                'input_image_tokens' => 0,
                'output_tokens' => 1100,
                'reasoning_tokens' => 1500,
                'total_tokens' => 2612,
            ],
        ],
        'failed' => [
            ...$snapshot,
            'started_at' => '2026-11-19T10:00:00Z',
            'completed_at' => '2026-11-19T10:00:09Z',
            'error' => ['code' => 'generation_failed', 'message' => 'The model could not produce an image for this prompt.'],
        ],
        default => $snapshot,
    };

    return array_replace($snapshot, $overrides);
}

/**
 * Deliver a Pixelforge webhook, signed the way Pixelforge signs it.
 *
 * @param  array<string, mixed>  $generationObject
 */
function deliverPixelforgeWebhook(string $type, array $generationObject, ?string $eventId = null, ?int $timestamp = null, ?string $secret = null): TestResponse
{
    $body = json_encode([
        'id' => $eventId ?? 'evt_'.Str::upper(Str::random(26)),
        'object' => 'event',
        'type' => $type,
        'created_at' => '2026-11-19T10:00:16Z',
        'data' => ['object' => $generationObject],
    ]);

    $timestamp ??= now()->getTimestamp();
    $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret ?? config('services.pixelforge.webhook_secret'));

    return test()->call(
        'POST',
        route('webhooks.pixelforge'),
        server: ['CONTENT_TYPE' => 'application/json', 'HTTP_PIXELFORGE_SIGNATURE' => "t={$timestamp},v1={$signature}"],
        content: $body,
    );
}
