<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;

class ClientController extends Controller
{
    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 5);
            $page = $request->input('page', 1);

            $clients = Client::paginate($perPage, ['*'], 'page', $page);

            return response()->json([
                'status' => true,
                'data' => $clients->items(),
                'total' => $clients->total(),
                'current_page' => $clients->currentPage(),
                'last_page' => $clients->lastPage(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'فشل في جلب بيانات العملاء: ' . $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'phone' => 'required|string|unique:clients',
            'current_balance' => 'required|numeric',
            'renewal_balance' => 'required|numeric',
            'start_date' => 'required|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $lastClient = Client::orderBy('subscription_number', 'desc')->first();
            $nextNumber = $lastClient ? ((int)$lastClient->subscription_number + 1) : 1;
            $subscriptionNumber = (string)$nextNumber;

            $client = Client::create([
                'name' => $request->name,
                'phone' => $request->phone,
                'current_balance' => $request->current_balance,
                'renewal_balance' => $request->renewal_balance,
                'subscription_number' => $subscriptionNumber,
            ]);

            $startDate = Carbon::parse($request->start_date);
            $endDate = $startDate->copy()->addMonths(2);

            Subscription::create([
                'client_id' => $client->id,
                'plan_name' => 'Default Plan',
                'price' => 0,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'status' => 'active'
            ]);

            User::create([
                'name' => $request->name,
                'phone' => $request->phone,
                'email' => $request->phone . '@example.com',
                'password' => Hash::make('123456'),
                'role' => 'user'
            ]);

            return response()->json([
                'status' => true,
                'data' => $client,
                'message' => 'تم إضافة العميل والاشتراك بنجاح'
            ], 201);
        } catch (\Illuminate\Database\QueryException $e) {
            return response()->json([
                'status' => false,
                'message' => 'خطأ في قاعدة البيانات: ' . $e->getMessage()
            ], 500);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'حدث خطأ أثناء الحفظ: ' . $e->getMessage()
            ], 500);
        }
    }

    public function show(Client $client)
    {
        return response()->json([
            'status' => true,
            'data' => $client
        ]);
    }

    public function update(Request $request, Client $client)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'phone' => 'sometimes|string|unique:clients,phone,' . $client->id,
            'current_balance' => 'sometimes|numeric',
            'renewal_balance' => 'sometimes|numeric',
            'start_date' => 'sometimes|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $client->update([
                'name' => $request->input('name', $client->name),
                'phone' => $request->input('phone', $client->phone),
                'current_balance' => $request->input('current_balance', $client->current_balance),
                'renewal_balance' => $request->input('renewal_balance', $client->renewal_balance),
            ]);

            if ($request->has('start_date')) {
                $startDate = Carbon::parse($request->start_date);
                $endDate = $startDate->copy()->addMonths(2);

                $subscription = $client->subscriptions()->where('status', 'active')->first();
                if ($subscription) {
                    $subscription->update([
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                    ]);
                }
            }

            return response()->json([
                'status' => true,
                'data' => $client,
                'message' => 'تم تحديث العميل بنجاح'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'حدث خطأ أثناء التحديث: ' . $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $client = Client::findOrFail($id);
            $client->subscriptions()->delete();
            $user = $client->user;
            if ($user) {
                $user->delete();
            }
            $client->delete();

            return response()->json([
                'status' => true,
                'message' => 'تم حذف العميل والاشتراكات والمستخدم المرتبط به بنجاح'
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'العميل غير موجود أو لا ينتمي إليك'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'حدث خطأ أثناء الحذف: ' . $e->getMessage()
            ], 500);
        }
    }
}
