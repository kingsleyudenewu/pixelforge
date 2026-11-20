<?php

use App\Enums\CreditTransactionType;
use App\Enums\GenerationFailureSource;
use App\Enums\GenerationStatus;
use App\Jobs\SubmitGenerationToPixelforge;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const CREATE_URL = 'pixelforge.promptedit.dev/v1/generations';

function queuedGenerationWithHold(string $state = 'queued'): Generation
{
    $user = User::factory()->withCredits('1.00')->create();
    $factory = Generation::factory()->for($user);

    return holdCreditsFor(($state === 'possiblySubmitted' ? $factory->possiblySubmitted() : $factory)->create());
}

function runSubmitJob(Generation $generation): SubmitGenerationToPixelforge
{
    $job = (new SubmitGenerationToPixelforge($generation))->withFakeQueueInteractions();

    app()->call([$job, 'handle']);

    return $job;
}

/**
 * @return array<string, mixed>
 */
function pixelforgeError(string $code, string $message = 'Rejected.'): array
{
    return ['error' => ['type' => 'invalid_request_error', 'code' => $code, 'message' => $message]];
}

test('a successful submission records the Pixelforge id and rates', function () {
    $generation = queuedGenerationWithHold();
    Http::fake([CREATE_URL => Http::response(pixelforgeGeneration($generation, 'queued', ['id' => 'gen_accepted']), 202)]);

    $job = runSubmitJob($generation);

    $job->assertNotReleased();
    expect($generation->refresh())
        ->pixelforge_id->toBe('gen_accepted')
        ->status->toBe(GenerationStatus::Queued)
        ->rates->toBe(['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50']);
});

test('the submission sends the stored payload with our generation id as the idempotency key', function () {
    $generation = queuedGenerationWithHold();
    Http::fake([CREATE_URL => Http::response(pixelforgeGeneration($generation, 'queued'), 202)]);

    runSubmitJob($generation);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://pixelforge.promptedit.dev/v1/generations'
        && $request->hasHeader('Idempotency-Key', $generation->id)
        && $request->hasHeader('Authorization', 'Bearer pf_test_key')
        && $request->data() === $generation->request_payload);
});

test('a prompt rejected by the content policy fails without charging the user', function () {
    $generation = queuedGenerationWithHold();
    Http::fake([CREATE_URL => Http::response(pixelforgeError('content_policy_violation'), 400)]);

    $job = runSubmitJob($generation);

    $job->assertNotReleased();
    expect($generation->refresh())
        ->status->toBe(GenerationStatus::Failed)
        ->failure_source->toBe(GenerationFailureSource::Local)
        ->error_code->toBe('content_policy_violation')
        ->charge_nanos->toBe(0)
        ->possibly_submitted->toBeFalse();
    expect($generation->user->refresh()->credit_balance_nanos)->toBe(1_000_000_000);
});

test('an overloaded response is retried with the hold kept', function () {
    $generation = queuedGenerationWithHold();
    Http::fake([CREATE_URL => Http::response(pixelforgeError('overloaded'), 503, ['Retry-After' => '2'])]);

    $job = runSubmitJob($generation);

    $job->assertReleased();
    expect($generation->refresh())
        ->status->toBe(GenerationStatus::Queued)
        ->possibly_submitted->toBeFalse();
    expect($generation->user->refresh()->credit_balance_nanos)->toBe(1_000_000_000 - $generation->estimated_charge_max_nanos);
});

test('a generation fails without charge after Pixelforge stays overloaded for four attempts', function () {
    $generation = queuedGenerationWithHold();
    Http::fake([CREATE_URL => Http::response(pixelforgeError('overloaded'), 503, ['Retry-After' => '2'])]);

    foreach (range(1, 3) as $attempt) {
        runSubmitJob($generation)->assertReleased();
    }
    $job = runSubmitJob($generation);

    $job->assertNotReleased();
    expect($generation->refresh())
        ->status->toBe(GenerationStatus::Failed)
        ->error_code->toBe('overloaded')
        ->charge_nanos->toBe(0);
    expect($generation->user->refresh()->credit_balance_nanos)->toBe(1_000_000_000);
});

test('the account concurrency limit is retried after the advertised delay', function () {
    $generation = queuedGenerationWithHold();
    Http::fake([CREATE_URL => Http::response(pixelforgeError('concurrency_limit_exceeded'), 429, ['Retry-After' => '5'])]);

    $job = runSubmitJob($generation);

    $job->assertReleased();
    expect($generation->refresh()->status)->toBe(GenerationStatus::Queued);
});

test('a submission waits without calling Pixelforge while three generations are already running there', function () {
    Generation::factory()->count(3)->processing()->create();
    $generation = queuedGenerationWithHold();

    $job = runSubmitJob($generation);

    $job->assertReleased();
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/v1/generations'));
});

test('a timeout keeps the generation queued with its hold, because Pixelforge may have created it', function () {
    $generation = queuedGenerationWithHold();
    Http::fake([CREATE_URL => Http::failedConnection()]);

    $job = runSubmitJob($generation);

    $job->assertReleased();
    expect($generation->refresh())
        ->status->toBe(GenerationStatus::Queued)
        ->possibly_submitted->toBeTrue();
    expect($generation->user->refresh()->credit_balance_nanos)->toBe(1_000_000_000 - $generation->estimated_charge_max_nanos);
});

test('a reused idempotency key keeps the generation queued instead of failing it', function () {
    $generation = queuedGenerationWithHold();
    Http::fake([CREATE_URL => Http::response(pixelforgeError('idempotency_key_reused'), 409)]);

    $job = runSubmitJob($generation);

    $job->assertReleased();
    expect($generation->refresh())
        ->status->toBe(GenerationStatus::Queued)
        ->possibly_submitted->toBeTrue();
});

test('a rejection after an unclear attempt is retried rather than failed for free', function (int $status, string $code) {
    $generation = queuedGenerationWithHold('possiblySubmitted');
    Http::fake([CREATE_URL => Http::response(pixelforgeError($code), $status)]);

    $job = runSubmitJob($generation);

    $job->assertReleased();
    expect($generation->refresh())
        ->status->toBe(GenerationStatus::Queued)
        ->possibly_submitted->toBeTrue();
})->with([
    'overloaded' => [503, 'overloaded'],
    'content policy' => [400, 'content_policy_violation'],
]);

test('a replayed submission resolves an unclear attempt', function () {
    $generation = queuedGenerationWithHold('possiblySubmitted');
    Http::fake([CREATE_URL => Http::response(pixelforgeGeneration($generation, 'processing', ['id' => 'gen_replayed']), 202, ['Idempotent-Replayed' => 'true'])]);

    runSubmitJob($generation);

    expect($generation->refresh())
        ->pixelforge_id->toBe('gen_replayed')
        ->status->toBe(GenerationStatus::Processing);
});

test('a create response arriving after the succeeded webhook does not move the generation back', function () {
    $generation = queuedGenerationWithHold();
    Http::fake([CREATE_URL => function () use ($generation) {
        deliverPixelforgeWebhook('generation.succeeded', pixelforgeGeneration($generation, 'succeeded'));

        return Http::response(pixelforgeGeneration($generation, 'queued'), 202);
    }]);

    runSubmitJob($generation);

    expect($generation->refresh()->status)->toBe(GenerationStatus::Succeeded);
    expect($generation->creditTransactions()->where('type', CreditTransactionType::Charge)->count())->toBe(1);
});

test('exhausted retries fail a generation Pixelforge never received, without charge', function () {
    $generation = queuedGenerationWithHold();

    (new SubmitGenerationToPixelforge($generation))->failed(new RuntimeException('Gave up.'));

    expect($generation->refresh())
        ->status->toBe(GenerationStatus::Failed)
        ->error_code->toBe('submission_failed');
    expect($generation->user->refresh()->credit_balance_nanos)->toBe(1_000_000_000);
});

test('exhausted retries leave a possibly submitted generation queued for the sync to resolve', function () {
    $generation = queuedGenerationWithHold('possiblySubmitted');

    (new SubmitGenerationToPixelforge($generation))->failed(new RuntimeException('Gave up.'));

    expect($generation->refresh()->status)->toBe(GenerationStatus::Queued);
    expect($generation->user->refresh()->credit_balance_nanos)->toBe(1_000_000_000 - $generation->estimated_charge_max_nanos);
});
