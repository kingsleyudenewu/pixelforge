<?php

namespace App\Http\Controllers;

use App\Actions\Generations\CreateGeneration;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Exceptions\InsufficientCreditsException;
use App\Exceptions\TooManyGenerationsInFlightException;
use App\Http\Requests\StoreGenerationRequest;
use App\Http\Resources\GenerationResource;
use App\Services\Pixelforge\Exceptions\PixelforgeException;
use App\Services\Pixelforge\PixelforgeClient;
use App\Support\Nanodollars;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    /**
     * The generate studio: the prompt form, a live cost estimate and the latest generations.
     */
    public function create(Request $request, PixelforgeClient $pixelforge): Response
    {
        return Inertia::render('generations/Create', [
            'pricing' => function () use ($pixelforge): ?array {
                try {
                    return $pixelforge->pricing()->toArray();
                } catch (PixelforgeException $exception) {
                    report($exception);

                    return null;
                }
            },
            'markupBasisPoints' => (int) config('billing.markup_basis_points'),
            'sizes' => array_map(fn (ImageSize $size): array => [
                'value' => $size->value,
                'label' => $size->label(),
                'width' => $size->width(),
                'height' => $size->height(),
            ], ImageSize::cases()),
            'qualities' => array_map(fn (ImageQuality $quality): array => [
                'value' => $quality->value,
                'label' => $quality->label(),
            ], ImageQuality::cases()),
            'generations' => fn (): array => GenerationResource::collection(
                $request->user()->generations()->latest()->orderByDesc('id')->limit(6)->get(),
            )->resolve(),
        ]);
    }

    /**
     * The user's generation history.
     */
    public function index(Request $request): Response
    {
        return Inertia::render('generations/Index', [
            'generations' => fn () => GenerationResource::collection(
                $request->user()->generations()->latest()->orderByDesc('id')->paginate(24)->withQueryString(),
            ),
        ]);
    }

    public function store(StoreGenerationRequest $request, CreateGeneration $createGeneration): RedirectResponse
    {
        try {
            $generation = $createGeneration->handle(
                $request->user(),
                $request->string('prompt')->toString(),
                $request->enum('size', ImageSize::class) ?? ImageSize::Square,
                $request->enum('quality', ImageQuality::class) ?? ImageQuality::Medium,
                $request->referenceImages(),
            );
        } catch (InsufficientCreditsException) {
            throw ValidationException::withMessages([
                'credits' => "You don't have enough credits for this generation. Buy credits to continue.",
            ]);
        } catch (TooManyGenerationsInFlightException) {
            throw ValidationException::withMessages([
                'generation' => 'You have several generations in progress. Wait for one to finish, then try again.',
            ]);
        } catch (PixelforgeException) {
            throw ValidationException::withMessages([
                'generation' => 'Pricing is temporarily unavailable, so we can’t quote this generation. Please try again shortly.',
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Generation queued. Up to '.Nanodollars::formatUsd($generation->estimated_charge_max_nanos).' is reserved until it finishes.',
        ]);

        return to_route('dashboard');
    }
}
