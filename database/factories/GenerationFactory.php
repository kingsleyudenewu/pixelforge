<?php

namespace Database\Factories;

use App\Enums\GenerationFailureSource;
use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Generation>
 */
class GenerationFactory extends Factory
{
    /** Pixelforge's documented rates, in dollars per 1M tokens. */
    public const array RATES = [
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ];

    /**
     * Define the model's default state: a medium square generation waiting to be submitted.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status' => GenerationStatus::Queued,
            'prompt' => fake()->sentence(),
            'size' => ImageSize::Square,
            'quality' => ImageQuality::Medium,
            'reference_images' => [],
            'request_payload' => [],
            'estimated_charge_min_nanos' => 58_175_000,
            'estimated_charge_max_nanos' => 86_325_000,
        ];
    }

    /**
     * Give the generation its id up front so the stored Pixelforge payload can reference it,
     * as CreateGeneration does.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Generation $generation): void {
            $generation->id ??= $generation->newUniqueId();

            if ($generation->request_payload === []) {
                $generation->request_payload = [
                    'prompt' => $generation->prompt,
                    'size' => $generation->size->value,
                    'quality' => $generation->quality->value,
                    'metadata' => [
                        'app_generation_id' => $generation->id,
                        'app_user_id' => (string) $generation->user_id,
                        'app_env' => 'testing',
                    ],
                ];
            }
        });
    }

    /**
     * Pixelforge has accepted the generation and it is waiting in their queue.
     */
    public function submitted(): static
    {
        return $this->state(fn (array $attributes) => [
            'pixelforge_id' => 'gen_'.Str::upper(Str::random(26)),
            'possibly_submitted' => true,
            'submit_attempts' => 1,
            'rates' => self::RATES,
        ]);
    }

    /**
     * A submission attempt ended without a clear answer, so Pixelforge may have the generation.
     */
    public function possiblySubmitted(): static
    {
        return $this->state(fn (array $attributes) => [
            'possibly_submitted' => true,
            'submit_attempts' => 1,
        ]);
    }

    public function processing(): static
    {
        return $this->submitted()->state(fn (array $attributes) => [
            'status' => GenerationStatus::Processing,
            'started_at' => now(),
        ]);
    }

    /**
     * Finished: 10 text, 1,100 output and 1,000 reasoning tokens cost $0.05655, so the user pays $0.0706875.
     */
    public function succeeded(): static
    {
        return $this->submitted()->state(fn (array $attributes) => [
            'status' => GenerationStatus::Succeeded,
            'input_text_tokens' => 10,
            'input_image_tokens' => 0,
            'output_tokens' => 1100,
            'reasoning_tokens' => 1000,
            'provider_cost_nanos' => 56_550_000,
            'charge_nanos' => 70_687_500,
            'image_url' => 'https://pixelforge.promptedit.dev/images/'.Str::random(12).'.png',
            'image_url_expires_at' => now()->addDay(),
            'started_at' => now()->subSeconds(20),
            'completed_at' => now(),
            'billed_on' => now()->utc()->toDateString(),
        ]);
    }

    public function failed(): static
    {
        return $this->submitted()->state(fn (array $attributes) => [
            'status' => GenerationStatus::Failed,
            'error_code' => 'generation_failed',
            'error_message' => 'The model could not produce an image for this prompt.',
            'failure_source' => GenerationFailureSource::Provider,
            'provider_cost_nanos' => 0,
            'charge_nanos' => 0,
            'completed_at' => now(),
        ]);
    }
}
