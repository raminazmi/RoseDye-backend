<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckSubscriptionStatus
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user) {
            // Skip the client check for admin users
            if ($user->role === 'admin') {
                return $next($request);
            }

            // Check client relationship only for non-admin users
            if ($user->client()->exists()) {
                $subscription = $user->client->subscriptions()->latest()->first();
                if ($subscription && $subscription->status === 'canceled') {
                    return response()->json(['message' => 'لا يمكن الوصول. اشتراكك موقوف.'], 403);
                }
            } else {
                return response()->json(['message' => 'لا يوجد عميل مرتبط بهذا المستخدم'], 403);
            }
        }

        return $next($request);
    }
}
