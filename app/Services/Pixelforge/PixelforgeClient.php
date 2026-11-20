<?php

namespace App\Services\Pixelforge;

use App\Services\Pixelforge\Exceptions\PixelforgeAmbiguousException;
use App\Services\Pixelforge\Exceptions\PixelforgeException;
use App\Services\Pixelforge\Exceptions\PixelforgeRejectedException;
use App\Services\Pixelforge\Exceptions\PixelforgeTransientException;
use App\Support\Nanodollars;
use Carbon\CarbonInterface;
use Illuminate\Container\Attributes\Config;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;

/**
 * The only place the app talks to Pixelforge. Every failure is classified so callers can decide
 * whether a generation might exist (and be billed) on Pixelforge's side.
 */
class PixelforgeClient
{
    private const string PRICING_CACHE_KEY = 'pixelforge:pricing';

    private const string PRICING_FALLBACK_CACHE_KEY = 'pixelforge:pricing:last-known-good';

    private const string REQUEST_BUDGET_KEY = 'pixelforge:requests';

    public function __construct(
        #[Config('services.pixelforge.url')]
        private readonly string $baseUrl,
        #[Config('services.pixelforge.key')]
        private readonly ?string $apiKey,
        #[Config('services.pixelforge.requests_per_minute')]
        private readonly int $requestsPerMinute,
    ) {}

    /**
     * Submit a generation. Replaying the same payload with the same idempotency key returns the
     * existing generation instead of creating (and billing) another one.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws PixelforgeException
     */
    public function createGeneration(array $payload, string $idempotencyKey): PixelforgeGeneration
    {
        $this->consumeRequestBudget();

        try {
            $response = $this->authenticated()
                ->withHeader('Idempotency-Key', $idempotencyKey)
                ->post('generations', $payload);
        } catch (ConnectionException $exception) {
            throw new PixelforgeAmbiguousException('No response from Pixelforge: '.$exception->getMessage(), 'connection_failed', previous: $exception);
        }

        if (! $response->successful()) {
            throw $this->creationFailure($response);
        }

        try {
            return PixelforgeGeneration::fromArray((array) $response->json());
        } catch (InvalidArgumentException $exception) {
            throw new PixelforgeAmbiguousException('Pixelforge accepted the generation but returned an unreadable body.', 'invalid_response', $response->status(), $exception);
        }
    }

    /**
     * @throws PixelforgeException
     */
    public function getGeneration(string $pixelforgeId): PixelforgeGeneration
    {
        $this->consumeRequestBudget();

        return PixelforgeGeneration::fromArray(
            $this->read(fn (PendingRequest $request): Response => $request->get('generations/'.rawurlencode($pixelforgeId))),
        );
    }

    /**
     * The current price list. Cached briefly; if Pixelforge is unreachable we fall back to the
     * last list we saw so the generate page keeps working.
     *
     * @throws PixelforgeException when no price list has ever been fetched.
     */
    public function pricing(): PricingSchedule
    {
        try {
            $payload = Cache::remember(self::PRICING_CACHE_KEY, now()->addMinutes(5), function (): array {
                $payload = $this->read(fn (PendingRequest $request): Response => $request->get('pricing'));
                Cache::forever(self::PRICING_FALLBACK_CACHE_KEY, $payload);

                return $payload;
            });
        } catch (PixelforgeException $exception) {
            $payload = Cache::get(self::PRICING_FALLBACK_CACHE_KEY) ?? throw $exception;
        }

        return PricingSchedule::fromApi((array) $payload);
    }

    /**
     * Drop the cached price list, e.g. when a generation comes back with rates we didn't expect.
     */
    public function forgetPricing(): void
    {
        Cache::forget(self::PRICING_CACHE_KEY);
    }

    /**
     * What Pixelforge billed this API key per UTC day (GET /v1/usage), inclusive of both dates.
     *
     * @return array<string, array{generations: int, cost_nanos: int}> keyed by Y-m-d
     *
     * @throws PixelforgeException
     */
    public function dailyUsage(CarbonInterface $start, CarbonInterface $end): array
    {
        $query = ['start_date' => $start->toDateString(), 'end_date' => $end->toDateString()];

        return Cache::remember('pixelforge:usage:'.implode(':', $query), now()->addMinute(), function () use ($query): array {
            $this->consumeRequestBudget();

            $payload = $this->read(fn (PendingRequest $request): Response => $request->get('usage', $query));
            $days = [];

            foreach ((array) ($payload['data'] ?? []) as $day) {
                $day = (array) $day;
                $days[(string) $day['date']] = [
                    'generations' => (int) $day['generations'],
                    'cost_nanos' => Nanodollars::fromDecimal((string) $day['cost_usd']),
                ];
            }

            return $days;
        });
    }

    /**
     * Perform a read-only request. Reads are always safe to retry, so failures are transient
     * unless Pixelforge says the request itself is wrong.
     *
     * @param  callable(PendingRequest): Response  $send
     * @return array<mixed>
     *
     * @throws PixelforgeException
     */
    private function read(callable $send): array
    {
        try {
            $response = $send($this->authenticated());
        } catch (ConnectionException $exception) {
            throw new PixelforgeTransientException('No response from Pixelforge: '.$exception->getMessage(), 'connection_failed', retryAfterSeconds: 10, previous: $exception);
        }

        if ($response->successful()) {
            return (array) $response->json();
        }

        [$code, $message] = $this->errorDetails($response);

        if ($response->status() === 429 || $response->serverError()) {
            throw new PixelforgeTransientException($message, $code, $response->status(), $this->retryAfter($response));
        }

        throw new PixelforgeRejectedException($message, $code, $response->status());
    }

    /**
     * Classify a failed create. Only responses Pixelforge documents as "nothing was created"
     * are treated as definitive; anything else might have created a billable generation.
     */
    private function creationFailure(Response $response): PixelforgeException
    {
        [$code, $message] = $this->errorDetails($response);
        $status = $response->status();

        return match (true) {
            $status === 429 && $code === 'daily_limit_exceeded' => new PixelforgeRejectedException($message, $code, $status),
            $status === 429, $status === 503 && $code === 'overloaded' => new PixelforgeTransientException($message, $code, $status, $this->retryAfter($response)),
            $status === 409, $status >= 500 => new PixelforgeAmbiguousException($message, $code, $status),
            $status >= 400 => new PixelforgeRejectedException($message, $code, $status),
            default => new PixelforgeAmbiguousException($message, $code, $status),
        };
    }

    /**
     * @return array{0: string|null, 1: string}
     */
    private function errorDetails(Response $response): array
    {
        $code = $response->json('error.code');
        $message = $response->json('error.message');

        return [
            is_string($code) ? $code : null,
            is_string($message) ? $message : "Pixelforge responded with HTTP {$response->status()}.",
        ];
    }

    private function retryAfter(Response $response): int
    {
        return max(1, (int) ($response->header('Retry-After') ?: 5));
    }

    /**
     * Keep every outbound call (submissions, status checks, usage) inside one shared per-minute budget,
     * so background polling can never starve submissions of Pixelforge's 60 requests per minute.
     *
     * @throws PixelforgeTransientException
     */
    private function consumeRequestBudget(): void
    {
        if (RateLimiter::tooManyAttempts(self::REQUEST_BUDGET_KEY, $this->requestsPerMinute)) {
            throw new PixelforgeTransientException(
                'Pixelforge request budget for this minute is used up.',
                'local_rate_limit',
                retryAfterSeconds: max(1, RateLimiter::availableIn(self::REQUEST_BUDGET_KEY)),
            );
        }

        RateLimiter::hit(self::REQUEST_BUDGET_KEY);
    }

    private function authenticated(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken((string) $this->apiKey)
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(15);
    }
}
