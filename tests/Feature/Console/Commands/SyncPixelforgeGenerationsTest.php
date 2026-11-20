<?php

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGenerationToPixelforge;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function quietGeneration(string $pixelforgeId): Generation
{
    $user = User::factory()->withCredits('1.00')->create();

    return holdCreditsFor(Generation::factory()->for($user)->processing()->create([
        'pixelforge_id' => $pixelforgeId,
        'last_synced_at' => now()->subMinute(),
    ]));
}

test('a generation whose webhook never arrived is settled from its Pixelforge status', function () {
    $generation = quietGeneration('gen_quiet');
    Http::fake(['pixelforge.promptedit.dev/v1/generations/gen_quiet' => Http::response(pixelforgeGeneration($generation, 'succeeded'))]);

    $this->artisan('pixelforge:sync')->assertSuccessful();

    expect($generation->refresh())
        ->status->toBe(GenerationStatus::Succeeded)
        ->charge_nanos->toBe(78_512_500);
});

test('a generation updated within the last 30 seconds is not polled', function () {
    $generation = quietGeneration('gen_recent');
    $generation->update(['last_synced_at' => now()->subSeconds(10)]);

    $this->artisan('pixelforge:sync')->assertSuccessful();

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'gen_recent'));
});

test('polling stops for the rest of the run once Pixelforge rate limits us', function () {
    quietGeneration('gen_first')->update(['last_synced_at' => now()->subMinutes(2)]);
    quietGeneration('gen_second');
    Http::fake(['pixelforge.promptedit.dev/v1/generations/gen_first' => Http::response(['error' => ['code' => 'rate_limit_exceeded', 'message' => 'Slow down.']], 429)]);

    $this->artisan('pixelforge:sync')->assertSuccessful();

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'gen_second'));
});

test('a submission with an unknown outcome is replayed after two minutes', function () {
    Queue::fake([SubmitGenerationToPixelforge::class]);
    $generation = Generation::factory()->possiblySubmitted()->create(['updated_at' => now()->subMinutes(3)]);

    $this->artisan('pixelforge:sync')->assertSuccessful();

    Queue::assertPushed(SubmitGenerationToPixelforge::class, fn (SubmitGenerationToPixelforge $job) => $job->generation->is($generation));
});

test('a submission still unresolved after a day is given up on and its hold returned', function () {
    Queue::fake([SubmitGenerationToPixelforge::class]);
    $user = User::factory()->withCredits('1.00')->create();
    $generation = holdCreditsFor(Generation::factory()->for($user)->possiblySubmitted()->create([
        'created_at' => now()->subHours(25),
        'updated_at' => now()->subMinutes(3),
    ]));

    $this->artisan('pixelforge:sync')->assertSuccessful();

    expect($generation->refresh())
        ->status->toBe(GenerationStatus::Failed)
        ->error_code->toBe('submission_unresolved');
    expect($user->refresh()->credit_balance_nanos)->toBe(1_000_000_000);
    Queue::assertNotPushed(SubmitGenerationToPixelforge::class);
});

test('a generation whose submission job was lost is failed without charge', function () {
    $user = User::factory()->withCredits('1.00')->create();
    $generation = holdCreditsFor(Generation::factory()->for($user)->create(['updated_at' => now()->subHour()]));

    $this->artisan('pixelforge:sync')->assertSuccessful();

    expect($generation->refresh())
        ->status->toBe(GenerationStatus::Failed)
        ->error_code->toBe('submission_failed');
    expect($user->refresh()->credit_balance_nanos)->toBe(1_000_000_000);
});

test('a late success for a generation we gave up on is still recorded and charged once', function () {
    Queue::fake([SubmitGenerationToPixelforge::class]);
    $user = User::factory()->withCredits('1.00')->create();
    $generation = holdCreditsFor(Generation::factory()->for($user)->possiblySubmitted()->create([
        'created_at' => now()->subHours(25),
        'updated_at' => now()->subMinutes(3),
    ]));
    $this->artisan('pixelforge:sync');

    deliverPixelforgeWebhook('generation.succeeded', pixelforgeGeneration($generation, 'succeeded'));
    deliverPixelforgeWebhook('generation.succeeded', pixelforgeGeneration($generation, 'succeeded'));

    expect($generation->refresh())
        ->status->toBe(GenerationStatus::Succeeded)
        ->provider_cost_nanos->toBe(62_810_000)
        ->billed_on->toBe('2026-11-19');
    expect($user->refresh()->credit_balance_nanos)->toBe(1_000_000_000 - 78_512_500);
});
