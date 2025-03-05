<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class UserController extends Controller
{
    public function index(Request $request)
    {
        return response()->json([
            'user' => $request->user(),
        ]);
    }

    public function updateAvatar(Request $request)
    {
        Log::info('Request received:', $request->all());
        Log::info('Files in request:', $request->allFiles());

        if (!$request->hasFile('avatar')) {
            Log::error('No file uploaded');
            return response()->json(['message' => 'No file uploaded'], 422);
        }

        $validator = Validator::make($request->all(), [
            'avatar' => 'required|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = Auth::user();

        if ($user->avatar && file_exists(public_path($user->avatar))) {
            unlink(public_path($user->avatar));
        }

        $avatarsPath = public_path('avatars');
        if (!file_exists($avatarsPath)) {
            Log::info('Creating avatars directory at: ' . $avatarsPath);
            mkdir($avatarsPath, 0777, true);
            chmod($avatarsPath, 0777);
        } elseif (!is_writable($avatarsPath)) {
            Log::error('Directory is not writable: ' . $avatarsPath);
            return response()->json(['message' => 'Unable to write in the avatars directory'], 500);
        }

        $image = $request->file('avatar');
        $imageName = time() . '.' . $image->getClientOriginalExtension();

        try {
            $image->move($avatarsPath, $imageName);
            Log::info('Image moved successfully to: ' . $avatarsPath . '/' . $imageName);
        } catch (\Exception $e) {
            Log::error('Failed to move image: ' . $e->getMessage());
            return response()->json(['message' => 'Unable to upload the image'], 500);
        }

        $user->avatar = 'avatars/' . $imageName;
        $user->save();

        $fullUrl = url('avatars/' . $imageName);

        return response()->json([
            'status' => true,
            'avatar_url' => $fullUrl
        ]);
    }

    public function updateProfile(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . auth()->id(),
            'phone' => 'nullable|string|max:20',
            'password' => 'nullable|min:8|confirmed',
            'bio' => 'nullable|string|max:500',
            'facebook' => 'nullable|url|max:255',
            'twitter' => 'nullable|url|max:255',
            'linkedin' => 'nullable|url|max:255',
            'instagram' => 'nullable|url|max:255',
            'github' => 'nullable|url|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'فشل التحقق من البيانات',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = Auth::user();
        $data = $request->only([
            'name',
            'email',
            'phone',
            'bio',
            'facebook',
            'twitter',
            'linkedin',
            'instagram',
            'github'
        ]);

        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }
        $user->update($data);

        return response()->json([
            'status' => true,
            'message' => 'تم تحديث الملف الشخصي بنجاح',
            'user' => $user,
        ]);
    }
}
