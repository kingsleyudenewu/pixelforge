<?php

namespace App\Console\Commands;

use App\Actions\Generations\ApplyPixelforgeSnapshot;
use App\Actions\Generations\FailGenerationLocally;
use App\Jobs\SubmitGenerationToPixelforge;
use App\Models\Generation;
use App\Services\Pixelforge\Exceptions\PixelforgeTransientException;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Webhooks are not guaranteed to arrive, so this sweep pulls the state of generations that have
 * gone quiet and re-drives submissions whose outcome is still unknown. It also keeps local
 * development working without a public webhook URL.
 */
#[Signature('pixelforge:sync {--limit=5 : Maximum Pixelforge status requests per run}')]
#[Description('Reconcile in-flight generations with Pixelforge when webhooks are late or missing')]
class SyncPixelforgeGenerations extends Command
{
    /** How long an in-flight generation may go without news before we ask Pixelforge directly. */
    private const int QUIET_SECONDS = 30;

    /** How long a submission with an unknown outcome waits before it is re-driven. */
    private const int UNRESOLVED_SUBMISSION_MINUTES = 2;

    /** How long we keep trying to resolve a submission before giving up on it. */
    private const int ABANDON_SUBMISSION_HOURS = 24;

    /** Submissions never sent this long after their job's retry window are considered lost. */
    private const int LOST_SUBMISSION_MINUTES = 40;

    public function handle(PixelforgeClient $pixelforge, ApplyPixelforgeSnapshot $applySnapshot, FailGenerationLocally $failLocally): int
    {
        $this->pollQuietGenerations($pixelforge, $applySnapshot);
        $this->redriveUnresolvedSubmissions($failLocally);
        $this->failLostSubmissions($failLocally);

        return self::SUCCESS;
    }

    private function pollQuietGenerations(PixelforgeClient $pixelforge, ApplyPixelforgeSnapshot $applySnapshot): void
    {
        $quietGenerations = Generation::query()
            ->inFlight()
            ->whereNotNull('pixelforge_id')
            ->where(fn ($query) => $query
                ->whereNull('last_synced_at')
                ->orWhere('last_synced_at', '<=', now()->subSeconds(self::QUIET_SECONDS)))
            ->orderBy('last_synced_at')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        foreach ($quietGenerations as $generation) {
            try {
                $applySnapshot->handle($pixelforge->getGeneration((string) $generation->pixelforge_id));
            } catch (PixelforgeTransientException $exception) {
                // Rate limited or unreachable: leave the rest for the next run.
                $this->components->warn("Stopped polling: {$exception->getMessage()}");

                return;
            } catch (Throwable $exception) {
                report($exception);

                // Don't let one broken generation block the head of the queue.
                $generation->forceFill(['last_synced_at' => now()])->save();
            }
        }
    }

    private function redriveUnresolvedSubmissions(FailGenerationLocally $failLocally): void
    {
        $unresolved = Generation::query()
            ->inFlight()
            ->whereNull('pixelforge_id')
            ->where('possibly_submitted', true)
            ->where('updated_at', '<=', now()->subMinutes(self::UNRESOLVED_SUBMISSION_MINUTES))
            ->get();

        foreach ($unresolved as $generation) {
            if ($generation->created_at?->lte(now()->subHours(self::ABANDON_SUBMISSION_HOURS))) {
                $failLocally->abandon($generation, 'submission_unresolved', 'Pixelforge never confirmed this generation.');

                continue;
            }

            // The job is unique per generation, so this is a no-op while a retry is still queued.
            SubmitGenerationToPixelforge::dispatch($generation);
            $generation->touch();
        }
    }

    private function failLostSubmissions(FailGenerationLocally $failLocally): void
    {
        Generation::query()
            ->inFlight()
            ->whereNull('pixelforge_id')
            ->where('possibly_submitted', false)
            ->where('updated_at', '<=', now()->subMinutes(self::LOST_SUBMISSION_MINUTES))
            ->each(fn (Generation $generation) => $failLocally->handle(
                $generation,
                'submission_failed',
                "We couldn't submit this generation to Pixelforge.",
            ));
    }
}
