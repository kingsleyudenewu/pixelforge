<?php

namespace App\Services\Pixelforge;

use App\Enums\GenerationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use InvalidArgumentException;

/**
 * A point-in-time snapshot of a Pixelforge generation, as returned by POST/GET /v1/generations
 * or embedded in a webhook's data.object.
 */
final readonly class PixelforgeGeneration
{
    /**
     * @param  array<string, string>  $metadata
     * @param  array<string, int>|null  $usage  Token counts keyed by token type, once succeeded.
     * @param  array<string, string>|null  $rates  Dollars per 1M tokens keyed by token type.
     */
    public function __construct(
        public string $id,
        public GenerationStatus $status,
        public array $metadata,
        public ?array $usage,
        public ?array $rates,
        public ?string $imageUrl,
        public ?CarbonImmutable $imageUrlExpiresAt,
        public ?string $errorCode,
        public ?string $errorMessage,
        public ?CarbonImmutable $startedAt,
        public ?CarbonImmutable $completedAt,
    ) {}

    /**
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $id = Arr::get($data, 'id');
        $status = GenerationStatus::tryFrom((string) Arr::get($data, 'status'));

        if (! is_string($id) || $id === '' || $status === null) {
            throw new InvalidArgumentException('Pixelforge generation payload is missing a valid id or status.');
        }

        $usage = Arr::get($data, 'usage');
        $rates = Arr::get($data, 'rates');
        $metadata = Arr::get($data, 'metadata');

        return new self(
            id: $id,
            status: $status,
            metadata: is_array($metadata) ? array_map(strval(...), array_filter($metadata, is_scalar(...))) : [],
            usage: is_array($usage) ? self::tokenCounts($usage) : null,
            rates: is_array($rates) ? array_map(strval(...), array_intersect_key($rates, array_flip(TokenType::values()))) : null,
            imageUrl: self::stringOrNull(Arr::get($data, 'image_url')),
            imageUrlExpiresAt: self::dateOrNull(Arr::get($data, 'image_url_expires_at')),
            errorCode: self::stringOrNull(Arr::get($data, 'error.code')),
            errorMessage: self::stringOrNull(Arr::get($data, 'error.message')),
            startedAt: self::dateOrNull(Arr::get($data, 'started_at')),
            completedAt: self::dateOrNull(Arr::get($data, 'completed_at')),
        );
    }

    /**
     * The local generation id we attached when submitting, if any.
     */
    public function appGenerationId(): ?string
    {
        return $this->metadata['app_generation_id'] ?? null;
    }

    /**
     * @param  array<mixed>  $usage
     * @return array<string, int>
     */
    private static function tokenCounts(array $usage): array
    {
        $counts = [];

        foreach (TokenType::values() as $type) {
            $counts[$type] = (int) ($usage[$type] ?? 0);
        }

        return $counts;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function dateOrNull(mixed $value): ?CarbonImmutable
    {
        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value)->utc() : null;
    }
}
