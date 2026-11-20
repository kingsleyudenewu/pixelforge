<?php

namespace App\Models;

use App\Enums\GenerationFailureSource;
use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use Carbon\CarbonImmutable;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property int|null $user_id
 * @property string|null $pixelforge_id
 * @property GenerationStatus $status
 * @property string $prompt
 * @property ImageSize $size
 * @property ImageQuality $quality
 * @property list<string> $reference_images
 * @property array<string, mixed> $request_payload
 * @property int $estimated_charge_min_nanos
 * @property int $estimated_charge_max_nanos
 * @property array<string, string>|null $rates
 * @property int|null $input_text_tokens
 * @property int|null $input_image_tokens
 * @property int|null $output_tokens
 * @property int|null $reasoning_tokens
 * @property int|null $provider_cost_nanos
 * @property int|null $charge_nanos
 * @property string|null $image_url
 * @property CarbonImmutable|null $image_url_expires_at
 * @property string|null $error_code
 * @property string|null $error_message
 * @property GenerationFailureSource|null $failure_source
 * @property bool $possibly_submitted
 * @property int $submit_attempts
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property string|null $billed_on
 * @property CarbonImmutable|null $last_synced_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User|null $user
 */
#[Fillable([
    'user_id', 'pixelforge_id', 'status', 'prompt', 'size', 'quality', 'reference_images', 'request_payload',
    'estimated_charge_min_nanos', 'estimated_charge_max_nanos', 'rates', 'input_text_tokens', 'input_image_tokens',
    'output_tokens', 'reasoning_tokens', 'provider_cost_nanos', 'charge_nanos', 'image_url', 'image_url_expires_at',
    'error_code', 'error_message', 'failure_source', 'possibly_submitted', 'submit_attempts', 'started_at',
    'completed_at', 'billed_on', 'last_synced_at',
])]
class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory, HasUlids;

    /**
     * Mirrors the column defaults for generations that haven't been reloaded since they were created.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'possibly_submitted' => false,
        'submit_attempts' => 0,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => GenerationStatus::class,
            'size' => ImageSize::class,
            'quality' => ImageQuality::class,
            'failure_source' => GenerationFailureSource::class,
            'reference_images' => 'array',
            'request_payload' => 'array',
            'rates' => 'array',
            'estimated_charge_min_nanos' => 'integer',
            'estimated_charge_max_nanos' => 'integer',
            'input_text_tokens' => 'integer',
            'input_image_tokens' => 'integer',
            'output_tokens' => 'integer',
            'reasoning_tokens' => 'integer',
            'provider_cost_nanos' => 'integer',
            'charge_nanos' => 'integer',
            'possibly_submitted' => 'boolean',
            'submit_attempts' => 'integer',
            'image_url_expires_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<CreditTransaction, $this>
     */
    public function creditTransactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class);
    }

    /**
     * Generations that are queued or processing (not yet final).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function inFlight(Builder $query): void
    {
        $query->whereIn('status', GenerationStatus::inFlight());
    }

    /**
     * Why the generation failed, in words a user can act on.
     */
    public function failureMessage(): ?string
    {
        if ($this->status !== GenerationStatus::Failed) {
            return null;
        }

        return match ($this->error_code) {
            'generation_failed' => "Pixelforge couldn't produce an image for this prompt.",
            'internal_error' => 'Pixelforge hit an internal error. Trying again usually works.',
            default => $this->error_message ?? 'This generation failed.',
        };
    }

    public function isImageExpired(): bool
    {
        return $this->image_url_expires_at !== null && $this->image_url_expires_at->isPast();
    }
}
