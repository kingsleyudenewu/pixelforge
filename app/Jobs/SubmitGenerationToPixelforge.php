<?php

namespace App\Jobs;

use App\Actions\Generations\ApplyPixelforgeSnapshot;
use App\Actions\Generations\FailGenerationLocally;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\Pixelforge\Exceptions\PixelforgeAmbiguousException;
use App\Services\Pixelforge\Exceptions\PixelforgeRejectedException;
use App\Services\Pixelforge\Exceptions\PixelforgeTransientException;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\MaxExceptions;
use Illuminate\Queue\Attributes\RetryFor;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Submit a generation to Pixelforge and classify the outcome.
 *
 * Before each attempt we record that the generation may now exist on Pixelforge. It is only failed
 * (for free) when every attempt got an answer that Pixelforge documents as "nothing was created".
 * After any unclear outcome it stays queued with its credits held, and is retried with the same
 * payload and Idempotency-Key until Pixelforge confirms it (or a webhook does).
 */
#[RetryFor(minutes: 30)]
#[MaxExceptions(5)]
#[Timeout(30)]
#[UniqueFor(35 * 60)]
#[DeleteWhenMissingModels]
class SubmitGenerationToPixelforge implements ShouldBeUnique, ShouldQueueAfterCommit
{
    use Queueable;

    /** Serialises submissions so the account-wide concurrency check is accurate. */
    private const string SUBMISSION_LOCK = 'pixelforge:submit';

    /** How long to back off after each consecutive "overloaded" response before giving up. */
    private const array OVERLOADED_BACKOFF_SECONDS = [5, 15, 45];

    public function __construct(public Generation $generation) {}

    public function uniqueId(): string
    {
        return $this->generation->id;
    }

    public function handle(PixelforgeClient $pixelforge, ApplyPixelforgeSnapshot $applySnapshot, FailGenerationLocally $failLocally): void
    {
        $generation = $this->generation->refresh();

        if ($generation->status->isFinal() || $generation->pixelforge_id !== null) {
            return;
        }

        $lock = Cache::lock(self::SUBMISSION_LOCK, 30);

        if (! $lock->get()) {
            $this->release($this->withJitter(2));

            return;
        }

        try {
            // Pixelforge only runs a few generations per account at once. Waiting here instead of
            // collecting 429s keeps the shared per-minute request budget for useful calls.
            if ($this->providerSlotsInUse($generation) >= (int) config('services.pixelforge.max_concurrent_generations')) {
                $this->release($this->withJitter(5));

                return;
            }

            $this->submit($generation, $pixelforge, $applySnapshot, $failLocally);
        } finally {
            $lock->release();
        }
    }

    /**
     * Called once retries are exhausted. Only fails the generation if Pixelforge can't have it;
     * otherwise pixelforge:sync keeps replaying the submission.
     */
    public function failed(?Throwable $exception): void
    {
        app(FailGenerationLocally::class)->handle(
            $this->generation,
            'submission_failed',
            "We couldn't submit this generation to Pixelforge.",
        );
    }

    private function submit(
        Generation $generation,
        PixelforgeClient $pixelforge,
        ApplyPixelforgeSnapshot $applySnapshot,
        FailGenerationLocally $failLocally,
    ): void {
        $wasPossiblySubmitted = $generation->possibly_submitted;

        // Commit this before sending, so a crash mid-request can never lead to a free failure.
        $generation->forceFill([
            'possibly_submitted' => true,
            'submit_attempts' => $generation->submit_attempts + 1,
        ])->save();

        try {
            $snapshot = $pixelforge->createGeneration($generation->request_payload, $generation->id);
        } catch (PixelforgeRejectedException $exception) {
            $this->handleRejection($generation, $exception, $wasPossiblySubmitted, $failLocally);

            return;
        } catch (PixelforgeTransientException $exception) {
            $this->handleTransientFailure($generation, $exception, $wasPossiblySubmitted, $failLocally);

            return;
        } catch (PixelforgeAmbiguousException $exception) {
            Log::warning('Unclear Pixelforge submission outcome; replaying with the same idempotency key.', [
                'generation_id' => $generation->id,
                'status' => $exception->status,
                'code' => $exception->errorCode,
                'message' => $exception->getMessage(),
            ]);

            $this->release($this->withJitter(min(60, 5 * 2 ** min($this->attempts(), 4))));

            return;
        }

        $applySnapshot->handle($snapshot);
    }

    private function handleRejection(
        Generation $generation,
        PixelforgeRejectedException $exception,
        bool $wasPossiblySubmitted,
        FailGenerationLocally $failLocally,
    ): void {
        if ($wasPossiblySubmitted) {
            // An earlier, unclear attempt may have created it. Only a replay can tell us.
            $this->release($this->withJitter(30));

            return;
        }

        if ($exception->errorCode === 'invalid_api_key') {
            Log::critical('Pixelforge rejected our API key.', ['message' => $exception->getMessage()]);
        }

        $generation->forceFill(['possibly_submitted' => false])->save();

        $failLocally->handle($generation, $exception->errorCode ?? 'rejected', $this->describeRejection($exception));
    }

    private function handleTransientFailure(
        Generation $generation,
        PixelforgeTransientException $exception,
        bool $wasPossiblySubmitted,
        FailGenerationLocally $failLocally,
    ): void {
        if (! $wasPossiblySubmitted) {
            $generation->forceFill(['possibly_submitted' => false])->save();
        }

        if ($exception->errorCode !== 'overloaded') {
            $this->release($this->withJitter($exception->retryAfterSeconds));

            return;
        }

        $overloadedResponses = $this->countOverloadedResponse($generation);

        if ($overloadedResponses > count(self::OVERLOADED_BACKOFF_SECONDS) && ! $wasPossiblySubmitted) {
            $failLocally->handle($generation, 'overloaded', 'Pixelforge is overloaded right now. Please try again in a few minutes.');

            return;
        }

        $backoff = self::OVERLOADED_BACKOFF_SECONDS[min($overloadedResponses, count(self::OVERLOADED_BACKOFF_SECONDS)) - 1];

        $this->release($this->withJitter(max($backoff, $exception->retryAfterSeconds)));
    }

    /**
     * Generations Pixelforge may currently be running for this account, other than this one.
     */
    private function providerSlotsInUse(Generation $generation): int
    {
        return Generation::query()
            ->whereKeyNot($generation->id)
            ->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
            ->where(fn ($query) => $query->whereNotNull('pixelforge_id')->orWhere('possibly_submitted', true))
            ->count();
    }

    private function countOverloadedResponse(Generation $generation): int
    {
        $key = "pixelforge:overloaded:{$generation->id}";

        Cache::add($key, 0, now()->addHour());

        return (int) Cache::increment($key);
    }

    private function describeRejection(PixelforgeRejectedException $exception): string
    {
        return match ($exception->errorCode) {
            'content_policy_violation' => "Pixelforge's content policy rejected this prompt.",
            'daily_limit_exceeded' => "We've reached today's Pixelforge generation limit. Please try again tomorrow.",
            'invalid_api_key' => 'Image generation is temporarily unavailable.',
            default => 'Pixelforge rejected this generation: '.$exception->getMessage(),
        };
    }

    private function withJitter(int $seconds): int
    {
        return $seconds + random_int(0, max(1, intdiv($seconds, 4)));
    }
}
