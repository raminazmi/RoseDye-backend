<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\VerificationCode;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class VerificationController extends Controller
{
    public function sendCode(Request $request)
    {
        $request->validate(['phone' => 'required|string']);

        $code = Str::random(4);
        $expiresAt = now()->addMinutes(5);

        VerificationCode::create([
            'phone' => $request->phone,
            'code' => $code,
            'expires_at' => $expiresAt
        ]);

        return response()->json([
            'message' => 'Verification code sent',
            'expires_at' => $expiresAt
        ]);
    }

    public function verifyCode(Request $request)
    {
        $request->validate([
            'phone' => 'required|string',
            'code' => 'required|string'
        ]);

        $verification = VerificationCode::where('phone', $request->phone)
            ->where('code', $request->code)
            ->where('expires_at', '>', now())
            ->first();

        if (!$verification) {
            return response()->json(['message' => 'Invalid code'], 400);
        }

        $verification->delete();

        return response()->json(['message' => 'Phone verified successfully']);
    }
}
