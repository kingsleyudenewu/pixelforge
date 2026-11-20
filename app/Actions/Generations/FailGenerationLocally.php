<?php

namespace App\Actions\Generations;

use App\Enums\GenerationFailureSource;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\Billing\CreditLedger;
use Illuminate\Support\Facades\DB;

/**
 * Fail a generation that Pixelforge never accepted, and give the user their hold back.
 */
class FailGenerationLocally
{
    public function __construct(private readonly CreditLedger $ledger) {}

    /**
     * Fail a generation that Pixelforge definitely doesn't have. Does nothing (and returns false)
     * if Pixelforge accepted it, if it is already final, or if an earlier submission attempt
     * ended without a clear answer, since then it might exist and be billed.
     */
    public function handle(Generation $generation, string $errorCode, string $errorMessage): bool
    {
        return $this->fail($generation, $errorCode, $errorMessage, allowPossiblySubmitted: false);
    }

    /**
     * Give up on a submission that never got a clear answer. Only for use after a long timeout;
     * if Pixelforge turns out to have the generation, its later snapshot will still be recorded.
     */
    public function abandon(Generation $generation, string $errorCode, string $errorMessage): bool
    {
        return $this->fail($generation, $errorCode, $errorMessage, allowPossiblySubmitted: true);
    }

    private function fail(Generation $generation, string $errorCode, string $errorMessage, bool $allowPossiblySubmitted): bool
    {
        return DB::transaction(function () use ($generation, $errorCode, $errorMessage, $allowPossiblySubmitted): bool {
            $locked = Generation::query()->whereKey($generation->getKey())->lockForUpdate()->first();

            if ($locked === null
                || $locked->status->isFinal()
                || $locked->pixelforge_id !== null
                || ($locked->possibly_submitted && ! $allowPossiblySubmitted)) {
                return false;
            }

            $locked->fill([
                'status' => GenerationStatus::Failed,
                'failure_source' => GenerationFailureSource::Local,
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
                'provider_cost_nanos' => 0,
                'charge_nanos' => 0,
                'completed_at' => now(),
                'last_synced_at' => now(),
            ])->save();

            $this->ledger->release($locked);

            $generation->setRawAttributes($locked->getAttributes(), sync: true);

            return true;
        });
    }
}
