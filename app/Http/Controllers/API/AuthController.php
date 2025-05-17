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
        try {
            $validatedData = $request->validate([
                'phone' => 'required|string|exists:clients,phone',
                'remember_me' => 'sometimes|boolean'
            ], [
                'phone.required' => 'رقم الهاتف مطلوب',
                'phone.string' => 'يجب أن يكون رقم الهاتف نصًا',
                'phone.exists' => 'رقم الهاتف غير مسجل',
            ]);

            $client = Client::where('phone', $request->phone)->firstOrFail();
            $user = User::where('phone', $client->phone)->firstOrFail();

            $tokenExpiration = $request->remember_me ? now()->addDays(30) : null;
            $tempToken = $user->createToken(
                'temp_auth_token',
                ['otp-pending'],
                $tokenExpiration
            )->plainTextToken;

            $this->sendVerificationCode($client->phone);

            return response()->json([
                'client' => $client,
                'temp_token' => $tempToken,
                'token_type' => 'Bearer',
                'expires_at' => $tokenExpiration,
                'message' => 'تم إرسال رمز التحقق إلى هاتفك'
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'فشل التحقق من الصحة',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'message' => 'رقم الهاتف غير مسجل'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'حدث خطأ: ' . $e->getMessage()
            ], 500);
        }
    }

    protected function sendVerificationCode($phone)
    {
        $code = str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);
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
        try {
            $validatedData = $request->validate([
                'phone' => 'required|string',
                'otp' => 'required|string|digits:4',
                'temp_token' => 'required|string',
                'remember_me' => 'sometimes|boolean'
            ], [
                'phone.required' => 'رقم الهاتف مطلوب',
                'phone.string' => 'يجب أن يكون رقم الهاتف نصًا',
                'otp.required' => 'رمز التحقق مطلوب',
                'otp.string' => 'يجب أن يكون رمز التحقق نصًا',
                'otp.digits' => 'يجب أن يتكون رمز التحقق من 4 أرقام',
                'temp_token.required' => 'الرمز المؤقت مطلوب',
                'temp_token.string' => 'يجب أن يكون الرمز المؤقت نصًا',
            ]);

            $client = Client::where('phone', $request->phone)->first();

            if (!$client) {
                return response()->json(['message' => 'العميل غير موجود'], 404);
            }

            $storedOtp = Cache::get('otp_' . $request->phone);
            if (!$storedOtp || $storedOtp !== $request->otp) {
                return response()->json(['message' => 'رمز التحقق غير صحيح'], 400);
            }

            $user = User::where('phone', $client->phone)->firstOrFail();

            $tokenExpiration = $request->remember_me ? now()->addDays(30) : null;
            $fullToken = $user->createToken(
                'auth_token',
                ['*'],
                $tokenExpiration
            )->plainTextToken;

            Cache::forget('otp_' . $request->phone);

            return response()->json([
                'access_token' => $fullToken,
                'client_id' => $client->id,
                'client' => $client,
                'token_type' => 'Bearer',
                'expires_at' => $tokenExpiration,
                'message' => 'تم تسجيل الدخول بنجاح'
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'فشل التحقق من الصحة',
                'errors' => $e->errors(),
            ], 422);
        }
    }

    public function verifyTempToken(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'temp_token' => 'required|string',
            ]);
            $token = \Laravel\Sanctum\PersonalAccessToken::findToken($request->temp_token);

            if (!$token || $token->name !== 'temp_auth_token' || !$token->tokenable) {
                return response()->json(['message' => 'الرمز المؤقت غير صحيح أو منتهي الصلاحية'], 401);
            }
            $user = $token->tokenable;
            if (!$user || !$user->exists) {
                return response()->json(['message' => 'المستخدم غير موجود'], 404);
            }

            return response()->json(['message' => 'الرمز المؤقت صحيح', 'user' => $user], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'فشل التحقق من الصحة',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'حدث خطأ: ' . $e->getMessage()
            ], 500);
        }
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
        try {
            $validatedData = $request->validate([
                'email' => 'required|string|email',
                'password' => 'required|string|min:8',
                'remember_me' => 'sometimes|boolean'
            ]);

            $user = User::where('email', $request->email)->firstOrFail();

            if (!Auth::attempt($request->only('email', 'password'))) {
                return response()->json(['message' => 'كلمة المرور غير صحيحة'], 401);
            }

            $tokenExpiration = $request->remember_me ? now()->addDays(30) : null;
            $token = $user->createToken(
                'auth_token',
                ['*'],
                $tokenExpiration
            )->plainTextToken;

            return response()->json([
                'user' => $user,
                'access_token' => $token,
                'token_type' => 'Bearer',
                'expires_at' => $tokenExpiration
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'فشل التحقق من الصحة',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'حدث خطأ: ' . $e->getMessage()
            ], 500);
        }
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out']);
    }
}
