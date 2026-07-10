<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyCloudflareWebhookSignature
{
    /**
     * Verify Cloudflare Stream webhook signatures using an HMAC SHA-256 digest.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('cloudflare.stream_webhook_secret');

        if ($secret === '') {
            return $next($request);
        }

        $providedSignature = $this->extractSignature($request);

        if ($providedSignature === null) {
            abort(401, 'Missing webhook signature.');
        }

        $computedSignature = hash_hmac('sha256', $request->getContent(), $secret);

        if (! hash_equals($computedSignature, $providedSignature)) {
            abort(401, 'Invalid webhook signature.');
        }

        return $next($request);
    }

    private function extractSignature(Request $request): ?string
    {
        $headerValues = [
            $request->header('Webhook-Signature'),
            $request->header('X-Webhook-Signature'),
            $request->header('CF-Webhook-Signature'),
            $request->header('Cf-Webhook-Signature'),
        ];

        foreach ($headerValues as $value) {
            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $signature = trim($value);

            if (str_starts_with($signature, 'sha256=')) {
                $signature = substr($signature, 7);
            }

            return strtolower($signature);
        }

        return null;
    }
}
