<?php

namespace App\Http\Resources;

use App\Models\Generation;
use App\Support\Nanodollars;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What a user sees of their generation. Money is sent as exact decimal strings.
 *
 * @mixin Generation
 */
class GenerationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $imageExpired = $this->isImageExpired();

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'prompt' => $this->prompt,
            'size' => $this->size->value,
            'width' => $this->size->width(),
            'height' => $this->size->height(),
            'quality' => $this->quality->value,
            'reference_image_count' => count($this->reference_images),
            'estimated_charge_min' => Nanodollars::toDecimal($this->estimated_charge_min_nanos),
            'estimated_charge_max' => Nanodollars::toDecimal($this->estimated_charge_max_nanos),
            'charge' => $this->charge_nanos === null ? null : Nanodollars::toDecimal($this->charge_nanos),
            'image_url' => $imageExpired ? null : $this->image_url,
            'image_expired' => $imageExpired,
            'error_message' => $this->failureMessage(),
            'created_at' => $this->created_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
