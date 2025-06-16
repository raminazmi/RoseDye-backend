<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use App\Models\Client;
use Illuminate\Support\Facades\DB;

class SubscriptionNumberController extends Controller
{
    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 10);
            $page = $request->input('page', 1);

            $subscriptionNumbers = SubscriptionNumber::with('client')
                ->paginate($perPage, ['*'], 'page', $page);

            return response()->json([
                'status' => true,
                'data' => $subscriptionNumbers->items(),
                'total' => $subscriptionNumbers->total(),
                'current_page' => $subscriptionNumbers->currentPage(),
                'last_page' => $subscriptionNumbers->lastPage(),
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching subscription numbers: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'فشل في جلب أرقام الاشتراكات',
            ], 500);
        }
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'start_number' => 'required|integer|min:1',
            'end_number' => 'required|integer|min:1|gte:start_number',
        ], [
            'start_number.required' => 'الرقم الأول مطلوب.',
            'start_number.integer' => 'الرقم الأول يجب أن يكون عددًا صحيحًا.',
            'start_number.min' => 'الرقم الأول يجب أن يكون على الأقل 1.',
            'end_number.required' => 'الرقم الأخير مطلوب.',
            'end_number.integer' => 'الرقم الأخير يجب أن يكون عددًا صحيحًا.',
            'end_number.min' => 'الرقم الأخير يجب أن يكون على الأقل 1.',
            'end_number.gte' => 'الرقم الأخير يجب أن يكون أكبر من أو يساوي الرقم الأول.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'فشل التحقق من الصحة',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            $start = (int)$request->start_number;
            $end = (int)$request->end_number;
            $newNumbers = [];

            for ($number = $start; $number <= $end; $number++) {
                // تجنب الأرقام المكررة
                if (!SubscriptionNumber::where('number', $number)->exists()) {
                    $newNumbers[] = [
                        'number' => $number,
                        'is_available' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }

            // إضافة الأرقام الجديدة بكمية كبيرة
            SubscriptionNumber::insert($newNumbers);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'تمت إضافة أرقام الاشتراكات بنجاح!',
                'data' => [
                    'added_count' => count($newNumbers),
                    'total_added' => count($newNumbers),
                ],
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error adding subscription numbers: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'حدث خطأ أثناء إضافة أرقام الاشتراكات: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'number' => 'required|string|unique:subscription_numbers,number,' . $id,
            'is_available' => 'required|boolean',
        ], [
            'number.required' => 'رقم الاشتراك مطلوب.',
            'number.string' => 'رقم الاشتراك يجب أن يكون نصًا.',
            'number.unique' => 'رقم الاشتراك موجود بالفعل.',
            'is_available.required' => 'حالة التوفر مطلوبة.',
            'is_available.boolean' => 'حالة التوفر يجب أن تكون صحيحة أو خاطئة.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'فشل التحقق من الصحة',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $subscriptionNumber = SubscriptionNumber::findOrFail($id);

            // إذا تم تعيين is_available إلى false ورقم الاشتراك مرتبط بعميل، إلغاء ربط العميل
            if ($request->is_available === false && $subscriptionNumber->client()->exists()) {
                $client = $subscriptionNumber->client;
                $client->subscription_number_id = null;
                $client->subscription_number = null;
                $client->save();
            }

            // إذا تم تعيين is_available إلى true، إلغاء ربط العميل
            if ($request->is_available === true && $subscriptionNumber->client()->exists()) {
                $client = $subscriptionNumber->client;
                $client->subscription_number_id = null;
                $client->subscription_number = null;
                $client->save();
            }

            // تحديث رقم الاشتراك
            $subscriptionNumber->update([
                'number' => $request->number,
                'is_available' => $request->is_available,
            ]);

            return response()->json([
                'status' => true,
                'message' => 'تم تحديث رقم الاشتراك بنجاح',
                'data' => $subscriptionNumber,
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating subscription number: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'حدث خطأ أثناء التحديث: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $subscriptionNumber = SubscriptionNumber::findOrFail($id);

            if ($subscriptionNumber->client()->exists()) {
                return response()->json([
                    'status' => false,
                    'message' => 'لا يمكن حذف رقم الاشتراك هذا لأنه مرتبط بعميل، يجب فك ربط العميل برقم الاشتراك وتغيير حالته الى متاح.',
                ], 400);
            }

            $subscriptionNumber->delete();

            return response()->json([
                'status' => true,
                'message' => 'تم حذف رقم الاشتراك بنجاح',
            ]);
        } catch (\Exception $e) {
            Log::error('Error deleting subscription number: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'حدث خطأ أثناء الحذف: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function assignClient(Request $request, $id)
    {
        Log::info('Starting assignClient function', [
            'subscription_number_id' => $id,
            'client_id' => $request->input('client_id'),
            'request_data' => $request->all()
        ]);

        // التحقق من صحة المدخلات
        $validator = Validator::make($request->all(), [
            'client_id' => 'nullable|exists:clients,id',
        ]);

        if ($validator->fails()) {
            Log::error('Validation failed for assignClient', [
                'subscription_number_id' => $id,
                'errors' => $validator->errors()->toArray()
            ]);
            return response()->json([
                'message' => 'فشل التحقق من الصحة',
                'errors' => $validator->errors()
            ], 422);
        }

        Log::info('Input validation passed', [
            'subscription_number_id' => $id,
            'client_id' => $request->input('client_id')
        ]);

        try {
            // البحث عن رقم الاشتراك
            $subscriptionNumber = SubscriptionNumber::findOrFail($id);
            Log::info('Subscription number found', [
                'subscription_number_id' => $id,
                'number' => $subscriptionNumber->number,
                'is_available' => $subscriptionNumber->is_available
            ]);

            $clientId = $request->input('client_id');

            if ($clientId) {
                // البحث عن العميل الجديد
                $newClient = Client::findOrFail($clientId);
                Log::info('New client found', [
                    'client_id' => $clientId,
                    'phone' => $newClient->phone
                ]);

                Log::info('No active subscriptions found for new client', [
                    'client_id' => $clientId
                ]);
                if ($newClient->subscription_number_id) {
                    $oldSubscriptionNumber = SubscriptionNumber::find($newClient->subscription_number_id);
                    if ($oldSubscriptionNumber) {
                        $oldSubscriptionNumber->is_available = true;
                        $oldSubscriptionNumber->save();
                    }
                }

                // التحقق من العميل الحالي المرتبط برقم الاشتراك
                if ($subscriptionNumber->client) {
                    // إلغاء ربط العميل القديم
                    $oldClient = $subscriptionNumber->client;
                    $oldClient->subscription_number_id = null;
                    $oldClient->subscription_number = null; // تحديث حقل subscription_number
                    $oldClient->is_available = true;
                    if ($oldClient->save()) {
                        Log::info('Old client unlinked successfully', [
                            'old_client_id' => $oldClient->id,
                            'phone' => $oldClient->phone,
                            'subscription_number_id' => $id
                        ]);
                    } else {
                        Log::error('Failed to unlink old client', [
                            'old_client_id' => $oldClient->id,
                            'phone' => $oldClient->phone,
                            'subscription_number_id' => $id
                        ]);
                        return response()->json([
                            'status' => false,
                            'message' => 'فشل في إلغاء ربط العميل القديم'
                        ], 500);
                    }
                } else {
                    Log::info('No existing client linked to subscription number', [
                        'subscription_number_id' => $id
                    ]);
                }

                // ربط العميل الجديد
                $newClient->subscription_number_id = $subscriptionNumber->id;
                $newClient->subscription_number = $subscriptionNumber->number; // تحديث حقل subscription_number
                if ($newClient->save()) {
                    Log::info('New client linked successfully', [
                        'client_id' => $newClient->id,
                        'phone' => $newClient->phone,
                        'subscription_number_id' => $id,
                        'subscription_number' => $subscriptionNumber->number
                    ]);
                } else {
                    Log::error('Failed to save new client with subscription_number_id', [
                        'client_id' => $newClient->id,
                        'phone' => $newClient->phone,
                        'subscription_number_id' => $id
                    ]);
                    return response()->json([
                        'status' => false,
                        'message' => 'فشل في حفظ العميل الجديد'
                    ], 500);
                }

                // تحديث حالة التوفر
                $subscriptionNumber->is_available = false;
            } else {
                // إلغاء ربط أي عميل حالي إذا لم يتم توفير client_id
                if ($subscriptionNumber->client) {
                    $oldClient = $subscriptionNumber->client;
                    $oldClient->subscription_number_id = null;
                    $oldClient->subscription_number = null; // تحديث حقل subscription_number
                    if ($oldClient->save()) {
                        Log::info('Old client unlinked successfully (no new client provided)', [
                            'old_client_id' => $oldClient->id,
                            'phone' => $oldClient->phone,
                            'subscription_number_id' => $id
                        ]);
                    } else {
                        Log::error('Failed to unlink old client (no new client provided)', [
                            'old_client_id' => $oldClient->id,
                            'phone' => $oldClient->phone,
                            'subscription_number_id' => $id
                        ]);
                        return response()->json([
                            'status' => false,
                            'message' => 'فشل في إلغاء ربط العميل القديم'
                        ], 500);
                    }
                } else {
                    Log::info('No client to unlink (no new client provided)', [
                        'subscription_number_id' => $id
                    ]);
                }

                // تحديث حالة التوفر
                $subscriptionNumber->is_available = true;
            }

            // حفظ رقم الاشتراك
            if ($subscriptionNumber->save()) {
                Log::info('Subscription number saved successfully', [
                    'subscription_number_id' => $id,
                    'is_available' => $subscriptionNumber->is_available
                ]);
            } else {
                Log::error('Failed to save subscription number', [
                    'subscription_number_id' => $id,
                    'is_available' => $subscriptionNumber->is_available
                ]);
                return response()->json([
                    'status' => false,
                    'message' => 'فشل في حفظ رقم الاشتراك'
                ], 500);
            }

            Log::info('assignClient completed successfully', [
                'subscription_number_id' => $id,
                'client_id' => $clientId,
                'is_available' => $subscriptionNumber->is_available
            ]);

            return response()->json([
                'status' => true,
                'message' => 'تم تحديث العميل المرتبط برقم الاشتراك بنجاح',
                'data' => $subscriptionNumber->load('client'),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            Log::error('Model not found in assignClient', [
                'subscription_number_id' => $id,
                'client_id' => $clientId,
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'status' => false,
                'message' => 'رقم الاشتراك أو العميل غير موجود.'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Unexpected error in assignClient', [
                'subscription_number_id' => $id,
                'client_id' => $clientId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'status' => false,
                'message' => 'حدث خطأ أثناء تحديث العميل لرقم الاشتراك: ' . $e->getMessage()
            ], 500);
        }
    }
}
