<?php

namespace App\Actions\Generations;

use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Exceptions\InsufficientCreditsException;
use App\Exceptions\TooManyGenerationsInFlightException;
use App\Jobs\SubmitGenerationToPixelforge;
use App\Models\Generation;
use App\Models\User;
use App\Services\Billing\CreditLedger;
use App\Services\Billing\PriceCalculator;
use App\Services\Pixelforge\Exceptions\PixelforgeException;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Container\Attributes\Config;
use Illuminate\Support\Facades\DB;

/**
 * Quote a generation, hold the quoted maximum from the user's credits, record it locally and
 * queue its submission to Pixelforge.
 */
class CreateGeneration
{
    public function __construct(
        private readonly PixelforgeClient $pixelforge,
        private readonly PriceCalculator $calculator,
        private readonly CreditLedger $ledger,
        #[Config('billing.max_in_flight_per_user')]
        private readonly int $maxInFlightPerUser,
    ) {}

    /**
     * @param  list<string>  $referenceImages
     *
     * @throws PixelforgeException when no price list is available.
     * @throws InsufficientCreditsException
     * @throws TooManyGenerationsInFlightException
     */
    public function handle(User $user, string $prompt, ImageSize $size, ImageQuality $quality, array $referenceImages): Generation
    {
        $estimate = $this->calculator->estimate($this->pixelforge->pricing(), $prompt, $size, $quality, count($referenceImages));

        $generation = DB::transaction(function () use ($user, $prompt, $size, $quality, $referenceImages, $estimate): Generation {
            // Serialise this user's submissions so the in-flight cap and the hold can't be raced.
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            if ($user->generations()->inFlight()->count() >= $this->maxInFlightPerUser) {
                throw new TooManyGenerationsInFlightException('The user has too many generations in progress.');
            }

            $generation = new Generation([
                'user_id' => $user->id,
                'status' => GenerationStatus::Queued,
                'prompt' => $prompt,
                'size' => $size,
                'quality' => $quality,
                'reference_images' => $referenceImages,
                'estimated_charge_min_nanos' => $estimate->minChargeNanos,
                'estimated_charge_max_nanos' => $estimate->maxChargeNanos,
            ]);
            $generation->id = $generation->newUniqueId();
            $generation->request_payload = $this->requestPayload($generation, $user);
            $generation->save();

            $this->ledger->reserve($user, $generation, $estimate->maxChargeNanos);

            return $generation;
        });

        SubmitGenerationToPixelforge::dispatch($generation);

        return $generation;
    }

    /**
     * The exact body sent to Pixelforge. It is stored and replayed unchanged on every retry so the
     * Idempotency-Key always matches the same parameters. Pixelforge rejects unknown fields.
     *
     * @return array<string, mixed>
     */
    private function requestPayload(Generation $generation, User $user): array
    {
        $payload = [
            'prompt' => $generation->prompt,
            'size' => $generation->size->value,
            'quality' => $generation->quality->value,
        ];

        if ($generation->reference_images !== []) {
            $payload['reference_images'] = $generation->reference_images;
        }

        $payload['metadata'] = [
            'app_generation_id' => $generation->id,
            'app_user_id' => (string) $user->id,
            'app_env' => (string) app()->environment(),
        ];

        return $payload;
    }
}
