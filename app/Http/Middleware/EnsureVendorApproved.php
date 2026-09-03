<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates vendor actions (store/product creation, WhatsApp setup, etc.) behind
 * vendor.status === approved. Does NOT block the vendor dashboard itself —
 * a pending/rejected/suspended vendor must still be able to see their status.
 */
class EnsureVendorApproved
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $vendor = $request->user()?->vendor;

        if (! $vendor || ! $vendor->isApproved()) {
            abort(403, 'Your vendor account must be approved before you can do this.');
        }

        return $next($request);
    }
}
