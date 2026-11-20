<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Container\Attributes\Config;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accept only webhooks signed by Pixelforge: `Pixelforge-Signature: t=<unix>,v1=<hex>`, where v1
 * is the HMAC-SHA256 of "<t>.<raw body>" keyed with our signing secret. Stale timestamps are
 * rejected so a captured delivery can't be replayed later.
 */
class VerifyPixelforgeSignature
{
    public function __construct(
        #[Config('services.pixelforge.webhook_secret')]
        private readonly ?string $secret,
        #[Config('services.pixelforge.webhook_tolerance')]
        private readonly int $toleranceSeconds,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->hasValidSignature($request)) {
            return response()->json(['message' => 'Invalid Pixelforge signature.'], Response::HTTP_BAD_REQUEST);
        }

        return $next($request);
    }

    private function hasValidSignature(Request $request): bool
    {
        if (blank($this->secret)) {
            return false;
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', (string) $request->header('Pixelforge-Signature')) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || ! ctype_digit($timestamp) || $signatures === []) {
            return false;
        }

        if (abs(now()->getTimestamp() - (int) $timestamp) > $this->toleranceSeconds) {
            return false;
        }

        // Sign the raw body exactly as received: re-encoding decoded JSON would change the bytes.
        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), (string) $this->secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }
}
