<?php

namespace App\Actions\Generations;

use App\Enums\GenerationFailureSource;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\Billing\CreditLedger;
use App\Services\Billing\PriceCalculator;
use App\Services\Pixelforge\PixelforgeGeneration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use UnexpectedValueException;

/**
 * Bring a local generation up to date with a Pixelforge snapshot, from a webhook, the create
 * response or a status poll. These can arrive twice, late or out of order, so this is the only
 * code path that changes a generation's status, and it:
 *
 *  - locks the generation row, so concurrent deliveries are applied one at a time;
 *  - only ever moves the status forward (queued → processing → succeeded/failed);
 *  - settles credits exactly once, on the transition into a final status.
 */
class ApplyPixelforgeSnapshot
{
    public function __construct(
        private readonly PriceCalculator $calculator,
        private readonly CreditLedger $ledger,
    ) {}

    /**
     * Returns the updated generation, or null if the snapshot isn't for one of ours.
     */
    public function handle(PixelforgeGeneration $snapshot): ?Generation
    {
        return DB::transaction(function () use ($snapshot): ?Generation {
            $generation = $this->findAndLock($snapshot);

            if ($generation === null) {
                Log::info('Ignoring a Pixelforge snapshot for an unknown generation.', ['pixelforge_id' => $snapshot->id]);

                return null;
            }

            if ($generation->pixelforge_id !== null && $generation->pixelforge_id !== $snapshot->id) {
                Log::warning('Ignoring a Pixelforge snapshot whose id conflicts with the local generation.', [
                    'generation_id' => $generation->id,
                    'expected_pixelforge_id' => $generation->pixelforge_id,
                    'pixelforge_id' => $snapshot->id,
                ]);

                return $generation;
            }

            $generation->pixelforge_id ??= $snapshot->id;
            $generation->rates ??= $snapshot->rates;
            $generation->last_synced_at = now();

            if ($this->isLateSuccessForAbandonedSubmission($generation, $snapshot)) {
                $this->recoverAbandonedSubmission($generation, $snapshot);
            } elseif ($snapshot->status->rank() > $generation->status->rank()) {
                $this->advance($generation, $snapshot);
            }

            $generation->save();

            return $generation;
        });
    }

    private function findAndLock(PixelforgeGeneration $snapshot): ?Generation
    {
        $environment = $snapshot->metadata['app_env'] ?? null;

        if ($environment !== null && $environment !== app()->environment()) {
            return null;
        }

        // Look up by our own id first: a webhook can arrive before the create request has returned,
        // when we don't know Pixelforge's id yet.
        $appGenerationId = $snapshot->appGenerationId();

        $generation = $appGenerationId !== null
            ? Generation::query()->whereKey($appGenerationId)->lockForUpdate()->first()
            : null;

        return $generation ?? Generation::query()->where('pixelforge_id', $snapshot->id)->lockForUpdate()->first();
    }

    private function advance(Generation $generation, PixelforgeGeneration $snapshot): void
    {
        $generation->status = $snapshot->status;
        $generation->started_at ??= $snapshot->startedAt;

        if ($snapshot->status === GenerationStatus::Succeeded) {
            $this->recordSuccess($generation, $snapshot);
            $this->ledger->settle($generation, (int) $generation->charge_nanos);
        }

        if ($snapshot->status === GenerationStatus::Failed) {
            $generation->fill([
                'error_code' => $snapshot->errorCode ?? 'generation_failed',
                'error_message' => $snapshot->errorMessage,
                'failure_source' => GenerationFailureSource::Provider,
                'completed_at' => $snapshot->completedAt ?? now(),
                'provider_cost_nanos' => 0,
                'charge_nanos' => 0,
            ]);

            $this->ledger->release($generation);
        }
    }

    private function recordSuccess(Generation $generation, PixelforgeGeneration $snapshot): void
    {
        $rates = $snapshot->rates ?? $generation->rates;

        if ($snapshot->usage === null || $rates === null) {
            throw new UnexpectedValueException("Succeeded Pixelforge generation [{$snapshot->id}] has no usage or rates.");
        }

        $providerCost = $this->calculator->providerCost($snapshot->usage, $rates);
        $completedAt = $snapshot->completedAt ?? now();

        $generation->fill([
            'status' => GenerationStatus::Succeeded,
            'rates' => $rates,
            'input_text_tokens' => $snapshot->usage['input_text_tokens'],
            'input_image_tokens' => $snapshot->usage['input_image_tokens'],
            'output_tokens' => $snapshot->usage['output_tokens'],
            'reasoning_tokens' => $snapshot->usage['reasoning_tokens'],
            'provider_cost_nanos' => $providerCost,
            'charge_nanos' => $this->calculator->charge($providerCost, $generation->estimated_charge_max_nanos),
            'image_url' => $snapshot->imageUrl,
            'image_url_expires_at' => $snapshot->imageUrlExpiresAt,
            'completed_at' => $completedAt,
            // Pixelforge bills on the UTC day the generation completed; /v1/usage groups by it.
            'billed_on' => $completedAt->utc()->toDateString(),
        ]);
    }

    /**
     * We only fail a generation locally when Pixelforge can't have it, or after giving up on an
     * unresolved submission for a day. If Pixelforge reports success anyway, it has billed us,
     * so record the cost rather than leave a gap in the books.
     */
    private function isLateSuccessForAbandonedSubmission(Generation $generation, PixelforgeGeneration $snapshot): bool
    {
        return $generation->status === GenerationStatus::Failed
            && $generation->failure_source === GenerationFailureSource::Local
            && $snapshot->status === GenerationStatus::Succeeded;
    }

    private function recoverAbandonedSubmission(Generation $generation, PixelforgeGeneration $snapshot): void
    {
        Log::warning('Pixelforge reported success for a generation we had failed locally; charging it now.', [
            'generation_id' => $generation->id,
            'pixelforge_id' => $snapshot->id,
        ]);

        $generation->fill(['error_code' => null, 'error_message' => null, 'failure_source' => null]);
        $generation->started_at ??= $snapshot->startedAt;

        $this->recordSuccess($generation, $snapshot);
        $this->ledger->chargeWithoutHold($generation, (int) $generation->charge_nanos);
    }
}
