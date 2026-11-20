<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A received Pixelforge webhook delivery, kept for auditing and as the first deduplication layer.
 *
 * @property int $id
 * @property string $event_id
 * @property string $type
 * @property string|null $pixelforge_generation_id
 * @property array<string, mixed> $payload
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['event_id', 'type', 'pixelforge_generation_id', 'payload'])]
class PixelforgeWebhookEvent extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }
}
