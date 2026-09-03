<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies Meta's X-Hub-Signature-256 header against the raw request body
 * using the App Secret (PLAN.md §5 Webhook Handling / §15 Security).
 * Non-negotiable — rejects with 403 on any mismatch or missing header.
 */
class VerifyWhatsAppSignature
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $appSecret = (string) config('whatsapp.app_secret');

        if ($appSecret === '') {
            abort(500, 'WhatsApp app secret is not configured.');
        }

        $signatureHeader = $request->header('X-Hub-Signature-256');

        if (! $signatureHeader || ! str_starts_with($signatureHeader, 'sha256=')) {
            abort(403, 'Missing or malformed signature.');
        }

        $expectedSignature = hash_hmac('sha256', $request->getContent(), $appSecret);

        $providedSignature = substr($signatureHeader, strlen('sha256='));

        if (! hash_equals($expectedSignature, $providedSignature)) {
            abort(403, 'Invalid signature.');
        }

        return $next($request);
    }
}
