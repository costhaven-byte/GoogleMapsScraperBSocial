<?php

namespace App\Http\Middleware;

use App\Models\Worker;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a local worker by its bearer token (stored as SHA-256 only).
 */
class AuthenticateWorker
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->bearerToken();
        $worker = $token !== '' ? Worker::findByToken($token) : null;
        if (! $worker) {
            return response()->json(['error' => 'Invalid or revoked worker token.'], 401);
        }

        $worker->forceFill(['last_seen_at' => now(), 'last_ip' => $request->ip()])->save();
        $request->attributes->set('worker', $worker);

        return $next($request);
    }
}
