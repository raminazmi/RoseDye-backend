<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function clientLogin(Request $request)
    {
        $request->validate([
            'phone' => 'required|string|exists:clients,phone',
        ]);

        $client = Client::where('phone', $request->phone)->first();

        if (!$client) {
            return response()->json([
                'message' => 'رقم الهاتف غير مسجل'
            ], 401);
        }

        $user = User::where('email', $client->email)->firstOrFail();
        $tempToken = $user->createToken('temp_auth_token', ['otp-pending'])->plainTextToken;
        $this->sendVerificationCode($client->phone);

        return response()->json([
            'client' => $client,
            'temp_token' => $tempToken,
            'token_type' => 'Bearer',
            'message' => 'تم إرسال رمز التحقق إلى هاتفك'
        ]);
    }

    protected function sendVerificationCode($phone)
    {
        $code = str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT); // رمز من 4 أرقام
        $expiresAt = now()->addMinutes(5);
        Cache::put('otp_' . $phone, $code, $expiresAt);

        $client = new \GuzzleHttp\Client();
        $params = [
            'token' => env('ULTRAMSG_TOKEN'),
            'to' => $phone,
            'body' => "رمز التحقق الخاص بك هو: $code",
        ];

        try {
            $response = $client->post('https://api.ultramsg.com/instance60138/messages/chat', [
                'form_params' => $params,
                'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            ]);

            $result = json_decode($response->getBody(), true);
            if (!isset($result['sent']) || $result['sent'] !== 'true') {
                \Log::error('فشل في إرسال رمز التحقق: ' . ($result['error'] ?? 'خطأ غير محدد'));
                return response()->json(['message' => 'فشل في إرسال الرمز'], 500);
            }
        } catch (\Exception $e) {
            \Log::error('خطأ في إرسال رمز التحقق: ' . $e->getMessage());
            return response()->json(['message' => 'خطأ في الاتصال'], 500);
        }

        return true;
    }

    public function resendVerificationCode(Request $request)
    {
        $request->validate(['phone' => 'required|string']);
        $client = Client::where('phone', $request->phone)->first();

        if (!$client) {
            return response()->json(['message' => 'العميل غير موجود'], 404);
        }

        $this->sendVerificationCode($client->phone);
        return response()->json(['message' => 'تم إعادة إرسال رمز التحقق إلى هاتفك']);
    }

    public function verifyOtp(Request $request)
    {
        $request->validate([
            'phone' => 'required|string',
            'otp' => 'required|string',
            'temp_token' => 'required|string',
        ]);

        $client = Client::where('phone', $request->phone)->first();

        if (!$client) {
            return response()->json(['message' => 'العميل غير موجود'], 404);
        }

        $storedOtp = Cache::get('otp_' . $request->phone);
        if (!$storedOtp || $storedOtp !== $request->otp) {
            return response()->json(['message' => 'رمز التحقق غير صحيح'], 400);
        }

        $user = User::where('email', $client->email)->firstOrFail();
        $fullToken = $user->createToken('auth_token')->plainTextToken;
        Cache::forget('otp_' . $request->phone);

        return response()->json([
            'access_token' => $fullToken,
            'client_id' => $client->id,
            'client' => $client,
            'token_type' => 'Bearer',
            'message' => 'تم تسجيل الدخول بنجاح'
        ]);
    }

    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|unique:users',
            'password' => 'required|string|min:8',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => 'admin'
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'access_token' => $token,
            'token_type' => 'Bearer',
        ]);
    }

    public function login(Request $request)
    {
        \Log::info('Login attempt with email: ' . $request->email);

        if (!Auth::attempt($request->only('email', 'password'))) {
            \Log::warning('Login failed for email: ' . $request->email);
            return response()->json([
                'message' => 'Invalid login details'
            ], 401);
        }

        $user = User::where('email', $request->email)->firstOrFail();
        \Log::info('Login successful for user: ' . $user->email);
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'access_token' => $token,
            'token_type' => 'Bearer',
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out']);
    }
}
