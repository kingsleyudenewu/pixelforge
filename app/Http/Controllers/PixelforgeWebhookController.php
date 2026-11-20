<?php

namespace App\Http\Controllers;

use App\Actions\Generations\ApplyPixelforgeSnapshot;
use App\Models\PixelforgeWebhookEvent;
use App\Services\Pixelforge\PixelforgeGeneration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PixelforgeWebhookController extends Controller
{
    /**
     * Record a Pixelforge event and apply it. Pixelforge delivers at least once and in no particular
     * order, so the event id is deduplicated and the generation snapshot (not the event type) decides
     * the new state. If anything fails, everything rolls back and the 5xx makes Pixelforge retry.
     */
    public function __invoke(Request $request, ApplyPixelforgeSnapshot $applySnapshot): Response|JsonResponse
    {
        Log::info('Pixelforge webhook', ['request' => $request->all()]);
        $eventId = $request->input('id');
        $type = $request->input('type');

        if (! is_string($eventId) || ! is_string($type)) {
            return response()->json(['message' => 'Malformed Pixelforge event.'], Response::HTTP_BAD_REQUEST);
        }

        $generation = $request->input('data.object');

        DB::transaction(function () use ($request, $eventId, $type, $generation, $applySnapshot): void {
            $isNewEvent = PixelforgeWebhookEvent::query()->insertOrIgnore([
                'event_id' => $eventId,
                'type' => $type,
                'pixelforge_generation_id' => is_array($generation) ? ($generation['id'] ?? null) : null,
                'payload' => $request->getContent(),
                'created_at' => now(),
                'updated_at' => now(),
            ]) === 1;

            if ($isNewEvent && str_starts_with($type, 'generation.') && is_array($generation)) {
                $applySnapshot->handle(PixelforgeGeneration::fromArray($generation));
            }
        });

        return response()->noContent();
    }
}
