<?php

use App\Enums\CreditTransactionType;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;

function submittedGenerationWithHold(): Generation
{
    $user = User::factory()->withCredits('1.00')->create();

    return holdCreditsFor(Generation::factory()->for($user)->submitted()->create());
}

test('a succeeded event records the exact provider cost and charges the user cost plus 25%', function () {
    $generation = submittedGenerationWithHold();

    deliverPixelforgeWebhook('generation.succeeded', pixelforgeGeneration($generation, 'succeeded'))->assertNoContent();

    expect($generation->refresh())
        ->status->toBe(GenerationStatus::Succeeded)
        ->provider_cost_nanos->toBe(62_810_000)
        ->charge_nanos->toBe(78_512_500)
        ->reasoning_tokens->toBe(1500)
        ->billed_on->toBe('2026-11-19')
        ->image_url->toBe('https://pixelforge.promptedit.dev/images/gen.png?signature=abc');
    expect($generation->user->refresh()->credit_balance_nanos)->toBe(1_000_000_000 - 78_512_500);
});

test('the same event delivered twice is recorded and charged once', function () {
    $generation = submittedGenerationWithHold();
    $snapshot = pixelforgeGeneration($generation, 'succeeded');

    deliverPixelforgeWebhook('generation.succeeded', $snapshot, eventId: 'evt_duplicate')->assertNoContent();
    deliverPixelforgeWebhook('generation.succeeded', $snapshot, eventId: 'evt_duplicate')->assertNoContent();

    $this->assertDatabaseCount('pixelforge_webhook_events', 1);
    expect($generation->creditTransactions()->where('type', CreditTransactionType::Charge)->count())->toBe(1);
    expect($generation->user->refresh()->credit_balance_nanos)->toBe(1_000_000_000 - 78_512_500);
});

test('a second event with a different id for the same outcome does not charge again', function () {
    $generation = submittedGenerationWithHold();
    $snapshot = pixelforgeGeneration($generation, 'succeeded');

    deliverPixelforgeWebhook('generation.succeeded', $snapshot, eventId: 'evt_first');
    deliverPixelforgeWebhook('generation.succeeded', $snapshot, eventId: 'evt_second');

    expect($generation->creditTransactions()->where('type', CreditTransactionType::Charge)->count())->toBe(1);
    expect($generation->user->refresh()->credit_balance_nanos)->toBe(1_000_000_000 - 78_512_500);
});

test('a processing event arriving after the succeeded event does not move the generation back', function () {
    $generation = submittedGenerationWithHold();

    deliverPixelforgeWebhook('generation.succeeded', pixelforgeGeneration($generation, 'succeeded'));
    deliverPixelforgeWebhook('generation.processing', pixelforgeGeneration($generation, 'processing'))->assertNoContent();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Succeeded);
});

test('a succeeded event arriving before the processing event still completes the generation', function () {
    $generation = submittedGenerationWithHold();

    deliverPixelforgeWebhook('generation.succeeded', pixelforgeGeneration($generation, 'succeeded'));

    expect($generation->refresh())
        ->status->toBe(GenerationStatus::Succeeded)
        ->started_at->toIso8601ZuluString()->toBe('2026-11-19T10:00:00Z');
});

test('a failed event costs the user nothing and returns their hold', function () {
    $generation = submittedGenerationWithHold();

    deliverPixelforgeWebhook('generation.failed', pixelforgeGeneration($generation, 'failed'))->assertNoContent();

    expect($generation->refresh())
        ->status->toBe(GenerationStatus::Failed)
        ->charge_nanos->toBe(0)
        ->provider_cost_nanos->toBe(0)
        ->error_code->toBe('generation_failed')
        ->billed_on->toBeNull();
    expect($generation->user->refresh()->credit_balance_nanos)->toBe(1_000_000_000);
});

test('an event that arrives before the create request returns is matched through our metadata', function () {
    $user = User::factory()->withCredits('1.00')->create();
    $generation = holdCreditsFor(Generation::factory()->for($user)->possiblySubmitted()->create());

    deliverPixelforgeWebhook('generation.processing', pixelforgeGeneration($generation, 'processing', ['id' => 'gen_early']));

    expect($generation->refresh())
        ->pixelforge_id->toBe('gen_early')
        ->status->toBe(GenerationStatus::Processing);
});

test('the charge is capped at the quoted maximum when usage exceeds the estimate', function () {
    $user = User::factory()->withCredits('1.00')->create();
    $generation = holdCreditsFor(Generation::factory()->for($user)->submitted()->create(['estimated_charge_max_nanos' => 50_000_000]));

    deliverPixelforgeWebhook('generation.succeeded', pixelforgeGeneration($generation, 'succeeded'));

    expect($generation->refresh())
        ->provider_cost_nanos->toBe(62_810_000)
        ->charge_nanos->toBe(50_000_000);
});

test('a ping event is acknowledged without changing any generation', function () {
    $generation = submittedGenerationWithHold();

    deliverPixelforgeWebhook('ping', [])->assertNoContent();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Queued);
});

test('an event for a generation we do not know is acknowledged and ignored', function () {
    $stranger = pixelforgeGeneration(Generation::factory()->make(['request_payload' => ['metadata' => []]]), 'succeeded', ['id' => 'gen_unknown']);

    deliverPixelforgeWebhook('generation.succeeded', $stranger)->assertNoContent();

    $this->assertDatabaseCount('generations', 0);
});

test('an event from another environment is ignored', function () {
    $generation = submittedGenerationWithHold();
    $snapshot = pixelforgeGeneration($generation, 'succeeded', ['metadata' => [...$generation->request_payload['metadata'], 'app_env' => 'production']]);

    deliverPixelforgeWebhook('generation.succeeded', $snapshot)->assertNoContent();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Queued);
});

test('rejects a webhook with an invalid signature with 400', function () {
    $generation = submittedGenerationWithHold();

    deliverPixelforgeWebhook('generation.succeeded', pixelforgeGeneration($generation, 'succeeded'), secret: 'whsec_wrong')->assertBadRequest();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Queued);
    $this->assertDatabaseCount('pixelforge_webhook_events', 0);
});

test('rejects a webhook signed more than five minutes ago with 400', function () {
    $generation = submittedGenerationWithHold();

    deliverPixelforgeWebhook('generation.succeeded', pixelforgeGeneration($generation, 'succeeded'), timestamp: now()->subSeconds(301)->getTimestamp())
        ->assertBadRequest();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Queued);
});

test('rejects a webhook without a signature header with 400', function () {
    $this->postJson(route('webhooks.pixelforge'), ['id' => 'evt_1', 'type' => 'ping'])->assertBadRequest();

    $this->assertDatabaseCount('pixelforge_webhook_events', 0);
});
