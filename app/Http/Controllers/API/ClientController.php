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

            $clients = Client::with(['subscriptions' => function ($query) {
                $query->where('status', 'active')->latest();
            }])->paginate($perPage, ['*'], 'page', $page);

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

    public function show(Client $client)
    {
        return response()->json([
            'status' => true,
            'data' => $client
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string|unique:clients',
            'current_balance' => 'required|numeric',
            'renewal_balance' => 'required|numeric',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'subscription_number' => 'required|string|unique:clients',
            'additional_gift' => 'nullable|numeric', // إضافة التحقق من حقل الهدية
        ], [
            'phone.required' => 'حقل رقم الهاتف مطلوب.',
            'phone.string' => 'حقل رقم الهاتف يجب أن يكون نصًا.',
            'phone.unique' => 'حقل رقم الهاتف مُستخدم مسبقًا.',
            'current_balance.required' => 'حقل الرصيد الحالي مطلوب.',
            'current_balance.numeric' => 'حقل الرصيد الحالي يجب أن يكون رقمًا.',
            'renewal_balance.required' => 'حقل رصيد التجديد مطلوب.',
            'renewal_balance.numeric' => 'حقل رصيد التجديد يجب أن يكون رقمًا.',
            'start_date.required' => 'حقل تاريخ البداية مطلوب.',
            'start_date.date' => 'حقل تاريخ البداية يجب أن يكون تاريخًا صحيحًا.',
            'end_date.required' => 'حقل تاريخ النهاية مطلوب.',
            'end_date.date' => 'حقل تاريخ النهاية يجب أن يكون تاريخًا صحيحًا.',
            'end_date.after' => 'حقل تاريخ النهاية يجب أن يكون تاريخًا بعد تاريخ البداية.',
            'subscription_number.required' => 'حقل رقم الاشتراك مطلوب.',
            'subscription_number.string' => 'حقل رقم الاشتراك يجب أن يكون نصًا.',
            'subscription_number.unique' => 'حقل رقم الاشتراك مُستخدم مسبقًا.',
            'additional_gift.numeric' => 'حقل الهدية الإضافية يجب أن يكون رقمًا.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $client = Client::create([
                'phone' => $request->phone,
                'current_balance' => $request->current_balance,
                'renewal_balance' => $request->renewal_balance,
                'subscription_number' => $request->subscription_number,
                'additional_gift' => $request->additional_gift ?? 0, // حفظ الهدية (0 إذا لم يتم إدخال قيمة)
            ]);

            Subscription::create([
                'client_id' => $client->id,
                'plan_name' => 'Default Plan',
                'price' => 0,
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
                'status' => 'active'
            ]);

            User::create([
                'name' => 'user' . $request->subscription_number,
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
                'message' => 'خطأ: رقم الاشتراك مكرر أو مشكلة في قاعدة البيانات'
            ], 500);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'حدث خطأ أثناء الحفظ'
            ], 500);
        }
    }

    public function update(Request $request, Client $client)
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string|unique:clients,phone,' . $client->id,
            'current_balance' => 'required|numeric',
            'renewal_balance' => 'required|numeric',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'subscription_number' => 'required|string|unique:clients,subscription_number,' . $client->id,
            'additional_gift' => 'nullable|numeric',
        ], [
            'phone.required' => 'حقل رقم الهاتف مطلوب.',
            'phone.string' => 'حقل رقم الهاتف يجب أن يكون نصًا.',
            'phone.unique' => 'حقل رقم الهاتف مُستخدم مسبقًا.',
            'current_balance.required' => 'حقل الرصيد الحالي مطلوب.',
            'current_balance.numeric' => 'حقل الرصيد الحالي يجب أن يكون رقمًا.',
            'renewal_balance.required' => 'حقل رصيد التجديد مطلوب.',
            'renewal_balance.numeric' => 'حقل رصيد التجديد يجب أن يكون رقمًا.',
            'start_date.required' => 'حقل تاريخ البداية مطلوب.',
            'start_date.date' => 'حقل تاريخ البداية يجب أن يكون تاريخًا صحيحًا.',
            'end_date.required' => 'حقل تاريخ النهاية مطلوب.',
            'end_date.date' => 'حقل تاريخ النهاية يجب أن يكون تاريخًا صحيحًا.',
            'end_date.after' => 'حقل تاريخ النهاية يجب أن يكون تاريخًا بعد تاريخ البداية.',
            'subscription_number.required' => 'حقل رقم الاشتراك مطلوب.',
            'subscription_number.string' => 'حقل رقم الاشتراك يجب أن يكون نصًا.',
            'subscription_number.unique' => 'حقل رقم الاشتراك مُستخدم مسبقًا.',
            'additional_gift.numeric' => 'حقل الهدية الإضافية يجب أن يكون رقمًا.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $client->update([
                'phone' => $request->input('phone', $client->phone),
                'current_balance' => $request->input('current_balance', $client->current_balance),
                'renewal_balance' => $request->input('renewal_balance', $client->renewal_balance),
                'subscription_number' => $request->input('subscription_number', $client->subscription_number),
                'additional_gift' => $request->input('additional_gift', $client->additional_gift), // تحديث الهدية
            ]);

            if ($request->has('start_date') || $request->has('end_date')) {
                $startDate = $request->has('start_date') ? Carbon::parse($request->start_date) : $client->subscriptions()->where('status', 'active')->first()->start_date;
                $endDate = $request->has('end_date') ? Carbon::parse($request->end_date) : $client->subscriptions()->where('status', 'active')->first()->end_date;

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
