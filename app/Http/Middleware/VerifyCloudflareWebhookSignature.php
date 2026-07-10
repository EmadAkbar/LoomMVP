<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyCloudflareWebhookSignature
{
    /**
     * Verify Cloudflare Stream webhook signatures using an HMAC SHA-256 digest.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('cloudflare.stream_webhook_secret');
        $rawPayload = $request->getContent();
        $signatureHeader = (string) $request->header('Webhook-Signature', '');

        if ($secret === '') {
            Log::channel('webhooks')->warning('Cloudflare webhook secret is not configured. Skipping signature verification.', [
                'path' => $request->path(),
                'ip' => $request->ip(),
            ]);

            return $next($request);
        }

        $signatureFields = $this->parseSignatureHeader($signatureHeader);
        $timestamp = $signatureFields['time'] ?? null;
        $providedSignature = $signatureFields['sig1'] ?? null;

        if (! is_int($timestamp) || ! is_string($providedSignature) || $providedSignature === '') {
            Log::channel('webhooks')->warning('Cloudflare webhook signature header is missing required fields.', [
                'path' => $request->path(),
                'header' => $signatureHeader,
            ]);

            abort(401, 'Missing webhook signature.');
        }

        $allowedSkew = (int) config('cloudflare.webhook_signature_tolerance_seconds', 300);

        if ($allowedSkew > 0 && abs(now()->timestamp - $timestamp) > $allowedSkew) {
            Log::channel('webhooks')->warning('Cloudflare webhook signature timestamp is outside tolerated skew.', [
                'path' => $request->path(),
                'timestamp' => $timestamp,
                'allowed_skew_seconds' => $allowedSkew,
            ]);

            abort(401, 'Expired webhook signature timestamp.');
        }

        $signatureSource = $timestamp . '.' . $rawPayload;
        $computedSignature = hash_hmac('sha256', $signatureSource, $secret);

        if (! hash_equals($computedSignature, $providedSignature)) {
            Log::channel('webhooks')->warning('Cloudflare webhook signature mismatch.', [
                'path' => $request->path(),
                'timestamp' => $timestamp,
                'provided_signature_prefix' => substr($providedSignature, 0, 12),
                'computed_signature_prefix' => substr($computedSignature, 0, 12),
            ]);

            abort(401, 'Invalid webhook signature.');
        }

        Log::channel('webhooks')->info('Cloudflare webhook signature verified.', [
            'path' => $request->path(),
            'timestamp' => $timestamp,
            'payload_size_bytes' => strlen($rawPayload),
        ]);

        $request->attributes->set('webhook_signature_verified', true);
        $request->attributes->set('webhook_signature_timestamp', $timestamp);

        return $next($request);
    }

    /**
     * Parse signature header in the format: time=123,sig1=abc123...
     *
     * @return array{time?: int, sig1?: string}
     */
    private function parseSignatureHeader(string $signatureHeader): array
    {
        if (trim($signatureHeader) === '') {
            return [];
        }

        $parsed = [];

        foreach (explode(',', $signatureHeader) as $pair) {
            $parts = explode('=', trim($pair), 2);

            if (count($parts) !== 2) {
                continue;
            }

            $key = strtolower(trim($parts[0]));
            $value = trim($parts[1]);

            if ($key === 'time' && ctype_digit($value)) {
                $parsed['time'] = (int) $value;
                continue;
            }

            if ($key === 'sig1' && $value !== '') {
                $parsed['sig1'] = strtolower($value);
            }
        }

        return $parsed;
    }
}
