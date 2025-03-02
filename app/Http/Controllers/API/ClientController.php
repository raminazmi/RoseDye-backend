<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class ClientController extends Controller
{
    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 5); // العدد الافتراضي لكل صفحة
            $page = $request->input('page', 1); // الصفحة الافتراضية

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
            'email' => 'required|email|unique:clients',
            'phone' => 'required|string|unique:clients',
            'company_name' => 'nullable|string',
            'address' => 'nullable|string',
            'current_balance' => 'required|numeric',
            'renewal_balance' => 'required|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $maxSubscriptionNumber = Client::max('subscription_number');
            $nextNumber = $maxSubscriptionNumber ? (int)substr($maxSubscriptionNumber, -5) + 1 : 1;
            $subscriptionNumber = str_pad($nextNumber, 5, '0', STR_PAD_LEFT);

            $client = Client::create([
                'name' => $request->name,
                'email' => $request->email,
                'phone' => $request->phone,
                'company_name' => $request->company_name,
                'address' => $request->address,
                'current_balance' => $request->current_balance,
                'renewal_balance' => $request->renewal_balance,
                'subscription_number' => $subscriptionNumber,
            ]);

            Subscription::create([
                'client_id' => $client->id,
                'plan_name' => 'Default Plan',
                'price' => 0,
                'start_date' => now(),
                'end_date' => now()->addYear(),
                'status' => 'active'
            ]);

            User::create([
                'name' => $request->name,
                'email' => $request->email,
                'phone' => $request->phone,
                'password' => Hash::make('123456'), // يبقى في جدول users للتوثيق العام
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
            'email' => 'sometimes|email|unique:clients,email,' . $client->id,
            'phone' => 'sometimes|string|unique:clients,phone,' . $client->id,
            'company_name' => 'nullable|string',
            'address' => 'nullable|string',
            'current_balance' => 'sometimes|numeric',
            'renewal_balance' => 'sometimes|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $client->update($request->all());

            return response()->json([
                'status' => true,
                'data' => $client,
                'message' => 'تم تحديث العميل بنجاح'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'حدث خطأ أثناء التحديث'
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $client = Client::findOrFail($id);
            $client->subscriptions()->delete();
            $client->delete();

            return response()->json([
                'status' => true,
                'message' => 'تم حذف العميل وجميع الاشتراكات المرتبطة به بنجاح'
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

    public function getClientByEmail(Request $request)
    {
        $request->validate(['email' => 'required|email|exists:clients,email']);

        $client = Client::where('email', $request->email)->firstOrFail();

        return response()->json([
            'status' => true,
            'data' => $client,
            'message' => 'تم جلب بيانات العميل بنجاح'
        ]);
    }
}
