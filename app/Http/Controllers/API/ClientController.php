<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Subscription;
use App\Models\SubscriptionNumber;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class ClientController extends Controller
{
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|unique:clients,phone',
            'current_balance' => 'required|numeric',
            'renewal_balance' => 'required|numeric',
            'original_gift' => 'nullable|numeric',
            'additional_gift' => 'nullable|numeric',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'subscription_number' => 'required|string',
        ], [
            'phone.required' => 'حقل رقم الهاتف مطلوب.',
            'phone.unique' => 'رقم الهاتف موجود مسبقًا.',
            'current_balance.required' => 'حقل الرصيد الحالي مطلوب.',
            'current_balance.numeric' => 'حقل الرصيد الحالي يجب أن يكون رقمًا.',
            'renewal_balance.required' => 'حقل رصيد التجديد مطلوب.',
            'renewal_balance.numeric' => 'حقل رصيد التجديد يجب أن يكون رقمًا.',
            'original_gift.numeric' => 'حقل الهدية الأساسية يجب أن يكون رقمًا.',
            'additional_gift.numeric' => 'حقل الهدية الإضافية يجب أن يكون رقمًا.',
            'start_date.required' => 'حقل تاريخ البداية مطلوب.',
            'start_date.date' => 'حقل تاريخ البداية يجب أن يكون تاريخًا صحيحًا.',
            'end_date.required' => 'حقل تاريخ النهاية مطلوب.',
            'end_date.date' => 'حقل تاريخ النهاية يجب أن يكون تاريخًا صحيحًا.',
            'end_date.after' => 'حقل تاريخ النهاية يجب أن يكون بعد تاريخ البداية.',
            'subscription_number.required' => 'حقل رقم الاشتراك مطلوب.',
            'subscription_number.string' => 'حقل رقم الاشتراك يجب أن يكون نصًا.',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            Log::info('Attempting to create client', ['request_data' => $request->all()]);

            $subscriptionNumber = SubscriptionNumber::where('number', $request->subscription_number)->first();

            if ($subscriptionNumber) {
                if ($subscriptionNumber->is_available) {
                    // Number is available, mark it as unavailable
                    $subscriptionNumber->is_available = false;
                    $subscriptionNumber->save();
                } else {
                    // Number is taken, check the associated client's subscription status
                    $previousClient = Client::where('subscription_number_id', $subscriptionNumber->id)->first();
                    if ($previousClient) {
                        $previousSubscription = $previousClient->subscriptions()->first();
                        if ($previousSubscription && $previousSubscription->status === 'canceled') {
                            // Clear the subscription number from the previous client
                            $previousClient->subscription_number = null;
                            $previousClient->subscription_number_id = null;
                            $previousClient->save();
                            // Mark the subscription number as unavailable for the new client
                            $subscriptionNumber->is_available = false;
                            $subscriptionNumber->save();
                        } else {
                            // Number is taken by an active client
                            return response()->json([
                                'status' => false,
                                'message' => 'رقم الاشتراك غير متاح، يوجد عميل نشط له نفس رقم الاشتراك'
                            ], 400);
                        }
                    } else {
                        // No client associated, but number is marked unavailable (edge case)
                        return response()->json([
                            'status' => false,
                            'message' => 'رقم الاشتراك غير متاح'
                        ], 400);
                    }
                }
            } else {
                // Create a new subscription number
                $subscriptionNumber = SubscriptionNumber::create([
                    'number' => $request->subscription_number,
                    'is_available' => false,
                ]);
            }

            // Create the new client
            $client = Client::create([
                'phone' => $request->phone,
                'current_balance' => $request->current_balance,
                'renewal_balance' => $request->renewal_balance,
                'subscription_number' => $request->subscription_number,
                'subscription_number_id' => $subscriptionNumber->id,
                'original_gift' => $request->original_gift ?? 0,
                'additional_gift' => $request->additional_gift ?? 0,
            ]);

            // Create the subscription
            $startDate = Carbon::parse($request->start_date);
            $endDate = Carbon::parse($request->end_date);
            $durationInDays = $startDate->diffInDays($endDate);
            $currentDate = Carbon::today();
            $status = $endDate->lessThan($currentDate) ? 'expired' : 'active';

            Subscription::create([
                'client_id' => $client->id,
                'plan_name' => 'Default Plan',
                'price' => 0,
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
                'duration_in_days' => $durationInDays + 1,
                'status' => $status,
            ]);

            // Create the associated user
            User::create([
                'name' => 'user' . $request->subscription_number,
                'phone' => $request->phone,
                'email' => $request->phone . '@example.com',
                'password' => Hash::make('123456'),
                'role' => 'user'
            ]);

            Log::info('Client created successfully', ['client_id' => $client->id]);

            return response()->json([
                'status' => true,
                'message' => 'تم إنشاء العميل بنجاح',
                'data' => $client
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error creating client', [
                'message' => $e->getMessage(),
                'request_data' => $request->all()
            ]);
            return response()->json([
                'status' => false,
                'message' => 'حدث خطأ أثناء إنشاء العميل: ' . $e->getMessage()
            ], 500);
        }
    }

    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 5);
            $page = $request->input('page', 1);

            $clients = Client::with(['subscriptions' => function ($query) {
                $query->latest();
            }])->paginate($perPage, ['*'], 'page', $page);

            return response()->json([
                'status' => true,
                'data' => $clients->items(),
                'total' => $clients->total(),
                'current_page' => $clients->currentPage(),
                'last_page' => $clients->lastPage(),
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching clients', ['message' => $e->getMessage()]);
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
            'data' => $client->load('subscriptionNumber')
        ]);
    }

    public function update(Request $request, Client $client)
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string|unique:clients,phone,' . $client->id,
            'current_balance' => 'required|numeric',
            'renewal_balance' => 'required|numeric',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'additional_gift' => 'nullable|numeric',
            'subscription_number' => 'required|string',
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
            'additional_gift.numeric' => 'حقل الهدية الإضافية يجب أن يكون رقمًا.',
            'subscription_number.required' => 'حقل رقم الاشتراك مطلوب.',
            'subscription_number.string' => 'حقل رقم الاشتراك يجب أن يكون نصًا.',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            Log::info('Attempting to update client', ['client_id' => $client->id, 'request_data' => $request->all()]);

            $oldPhone = $client->phone;
            $oldSubscriptionNumberId = $client->subscription_number_id;

            if ($client->subscription_number !== $request->subscription_number) {
                if ($oldSubscriptionNumberId) {
                    // Free the old subscription number
                    $oldSubscriptionNumber = SubscriptionNumber::find($oldSubscriptionNumberId);
                    if ($oldSubscriptionNumber) {
                        $oldSubscriptionNumber->is_available = true;
                        $oldSubscriptionNumber->save();
                    }
                }

                // Check the new subscription number
                $newSubscriptionNumber = SubscriptionNumber::where('number', $request->subscription_number)->first();
                if ($newSubscriptionNumber) {
                    if ($newSubscriptionNumber->is_available) {
                        $newSubscriptionNumber->is_available = false;
                        $newSubscriptionNumber->save();
                    } else {
                        $previousClient = Client::where('subscription_number_id', $newSubscriptionNumber->id)->first();
                        if ($previousClient) {
                            $previousSubscription = $previousClient->subscriptions()->first();
                            if ($previousSubscription && $previousSubscription->status === 'canceled') {
                                // Clear the subscription number from the previous client
                                $previousClient->subscription_number = null;
                                $previousClient->subscription_number_id = null;
                                $previousClient->save();
                                $newSubscriptionNumber->is_available = false;
                                $newSubscriptionNumber->save();
                            } else {
                                // Number is taken by an active client
                                return response()->json([
                                    'status' => false,
                                    'message' => 'رقم الاشتراك غير متاح، يوجد عميل نشط له نفس رقم الاشتراك'
                                ], 400);
                            }
                        } else {
                            // No client associated, but number is marked unavailable
                            return response()->json([
                                'status' => false,
                                'message' => 'رقم الاشتراك غير متاح'
                            ], 400);
                        }
                    }
                } else {
                    // Create a new subscription number
                    $newSubscriptionNumber = SubscriptionNumber::create([
                        'number' => $request->subscription_number,
                        'is_available' => false,
                    ]);
                }

                $client->subscription_number = $request->subscription_number;
                $client->subscription_number_id = $newSubscriptionNumber->id;
            }

            // Update client details
            $client->update([
                'phone' => $request->input('phone', $client->phone),
                'current_balance' => $request->input('current_balance', $client->current_balance),
                'renewal_balance' => $request->input('renewal_balance', $client->renewal_balance),
                'additional_gift' => $request->input('additional_gift', $client->additional_gift),
            ]);

            // Update associated user if phone changed
            if ($oldPhone !== $client->phone) {
                $user = User::where('phone', $oldPhone)->first();
                if ($user) {
                    $user->update([
                        'phone' => $client->phone,
                        'email' => $client->phone . '@example.com'
                    ]);
                }
            }

            // Update subscription dates if provided
            if ($request->has('start_date') || $request->has('end_date')) {
                $startDate = $request->has('start_date') ? Carbon::parse($request->start_date) : $client->subscriptions()->first()->start_date;
                $endDate = $request->has('end_date') ? Carbon::parse($request->end_date) : $client->subscriptions()->first()->end_date;

                $durationInDays = $startDate->diffInDays($endDate);
                $currentDate = Carbon::today();
                $status = $endDate->lessThan($currentDate) ? 'expired' : 'active';

                $subscription = $client->subscriptions()->first();
                if ($subscription) {
                    $subscription->update([
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                        'duration_in_days' => $durationInDays + 1,
                        'status' => $status,
                    ]);
                }
            }

            Log::info('Client updated successfully', ['client_id' => $client->id]);

            return response()->json([
                'status' => true,
                'data' => $client,
                'message' => 'تم تحديث العميل بنجاح'
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating client', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all()
            ]);
            return response()->json([
                'status' => false,
                'message' => 'حدث خطأ أثناء التحديث: ' . $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            Log::info('Attempting to delete client', ['client_id' => $id]);

            $client = Client::findOrFail($id);
            if ($client->phone) {
                $user = User::where('phone', $client->phone)->first();
                if ($user) {
                    $user->delete();
                }
            }

            if ($client->subscription_number_id) {
                $subscriptionNumber = SubscriptionNumber::find($client->subscription_number_id);
                if ($subscriptionNumber) {
                    $subscriptionNumber->is_available = true;
                    $subscriptionNumber->save();
                }
            }

            $client->subscriptions()->delete();
            $client->delete();

            Log::info('Client deleted successfully', ['client_id' => $id]);

            return response()->json([
                'status' => true,
                'message' => 'تم حذف العميل والاشتراكات والمستخدم المرتبط به بنجاح'
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            Log::error('Client not found for deletion', ['client_id' => $id]);
            return response()->json([
                'status' => false,
                'message' => 'العميل غير موجود'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error deleting client', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'client_id' => $id
            ]);
            return response()->json([
                'status' => false,
                'message' => 'حدث خطأ أثناء الحذف: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getSubscriptionNumbers(Request $request)
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
            Log::error('Error fetching subscription numbers', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'status' => false,
                'message' => 'فشل في جلب أرقام الاشتراك: ' . $e->getMessage()
            ], 500);
        }
    }

    public function toggleSubscriptionNumberAvailability($id)
    {
        try {
            $subscriptionNumber = SubscriptionNumber::findOrFail($id);
            $client = $subscriptionNumber->client;

            if ($client) {
                $subscription = $client->subscriptions()->first();

                if ($subscription) {
                    if ($subscriptionNumber->is_available) {
                        // إذا كان الرقم متاحاً (يعني العميل موقوف)
                        // لا يمكن جعله غير متاح إلا من خلال إنشاء/تحديث عميل
                        return response()->json([
                            'status' => false,
                            'message' => 'لا يمكن جعل الرقم غير متاح إلا من خلال إنشاء/تحديث عميل'
                        ], 400);
                    } else {
                        // جعل الرقم متاحاً وتعليق العميل
                        $subscription->update(['status' => 'canceled']);
                        $client->update([
                            'subscription_number_id' => null,
                            'subscription_number' => null
                        ]);
                        $subscriptionNumber->is_available = true;
                        $subscriptionNumber->save();
                    }
                }
            } else {
                // إذا لم يكن الرقم مرتبطاً بأي عميل
                $subscriptionNumber->is_available = !$subscriptionNumber->is_available;
                $subscriptionNumber->save();
            }

            Log::info('Subscription number availability toggled', [
                'subscription_number_id' => $id,
                'is_available' => $subscriptionNumber->is_available,
            ]);

            return response()->json([
                'status' => true,
                'message' => 'تم تغيير حالة توفر رقم الاشتراك بنجاح',
                'data' => [
                    'id' => $subscriptionNumber->id,
                    'number' => $subscriptionNumber->number,
                    'is_available' => $subscriptionNumber->is_available,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error toggling subscription number availability', [
                'subscription_number_id' => $id,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'status' => false,
                'message' => 'حدث خطأ أثناء تغيير حالة التوفر: ' . $e->getMessage(),
            ], 500);
        }
    }
}
