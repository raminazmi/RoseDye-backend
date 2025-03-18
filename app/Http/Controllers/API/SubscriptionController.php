<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Omaralalwi\Gpdf\Gpdf;
use Illuminate\Support\Facades\Validator;
use Omaralalwi\Gpdf\GpdfConfig;

class SubscriptionController extends Controller
{
    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 5);
            $page = $request->input('page', 1);

            $subscriptions = Subscription::with(['client' => function ($query) {
                $query->withCount('invoices');
            }])->paginate($perPage, ['*'], 'page', $page);

            $subscriptions->getCollection()->transform(function ($sub) {
                $client = $sub->client;
                $totalDue = $client && $client->current_balance <= 0 ? abs($client->current_balance) : 0;
                $totalINV = $client ? $client->invoices()->sum('amount') : 0;

                return [
                    'id' => $sub->id,
                    'subscription_number' => $client->subscription_number ?? 'N/A',
                    'invoices_count' => $client->invoices_count ?? 0,
                    'end_date' => $sub->end_date,
                    'total_due' => $totalDue,
                    'total_inv' => $totalINV,
                    'client_phone' => $client->phone ?? 'N/A',
                    'status' => $sub->status,
                ];
            });

            return response()->json([
                'status' => true,
                'data' => $subscriptions->items(),
                'total' => $subscriptions->total(),
                'current_page' => $subscriptions->currentPage(),
                'last_page' => $subscriptions->lastPage(),
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching subscriptions: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'فشل في جلب بيانات الاشتراكات'
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $subscription = Subscription::with(['client.invoices'])->findOrFail($id);

            if (!$subscription->client) {
                throw new \Exception('العميل غير موجود');
            }

            $perPage = request()->input('per_page', 5);
            $page = request()->input('page', 1);
            $invoices = $subscription->client->invoices()->paginate($perPage, ['*'], 'page', $page);

            $formattedData = [
                'subscription' => [
                    'id' => $subscription->id,
                    'plan_name' => $subscription->plan_name,
                    'start_date' => $subscription->start_date,
                    'end_date' => $subscription->end_date,
                    'status' => $subscription->status,
                    'client' => [
                        'id' => $subscription->client->id,
                        'name' => $subscription->client->name,
                        'subscription_number' => $subscription->client->subscription_number,
                        'phone' => $subscription->client->phone,
                        'current_balance' => $subscription->client->current_balance,
                    ],
                    'invoices' => $invoices->items(),
                ],
                'pagination' => [
                    'total' => $invoices->total(),
                    'current_page' => $invoices->currentPage(),
                    'last_page' => $invoices->lastPage(),
                ],
            ];

            return response()->json([
                'status' => true,
                'data' => $formattedData,
            ]);
        } catch (\Exception $e) {
            Log::error('Subscription Error: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'الاشتراك غير موجود: ' . $e->getMessage()
            ], 404);
        }
    }

    // إضافة دالة لتجديد الاشتراك
    public function renew(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric', // المبلغ المدفوع للتجديد
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $subscription = Subscription::findOrFail($id);
            $client = $subscription->client;
            $currentBalance = $client->current_balance;
            $renewalAmount = $request->amount; // المبلغ المدفوع (مثل 20 دينار)

            // منطق التجديد
            $baseLimit = 35; // الحد الأساسي
            if ($currentBalance > $baseLimit) {
                $excess = $currentBalance - $baseLimit;
                $client->renewal_balance = $baseLimit - $excess; // خصم الفائض
            } elseif ($currentBalance < $baseLimit) {
                $remaining = $baseLimit - $currentBalance;
                $client->renewal_balance = $baseLimit + $remaining; // إضافة المتبقي
            } else {
                $client->renewal_balance = $baseLimit;
            }

            $client->current_balance = 0; // إعادة تعيين الرصيد الحالي
            $client->save();

            $subscription->update([
                'start_date' => now(),
                'end_date' => now()->addMonths(2), // مدة الاشتراك 60 يوم
                'status' => 'active',
            ]);

            return response()->json([
                'status' => true,
                'message' => 'تم تجديد الاشتراك بنجاح',
                'data' => [
                    'subscription' => $subscription,
                    'renewal_balance' => $client->renewal_balance,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'حدث خطأ أثناء التجديد: ' . $e->getMessage()
            ], 500);
        }
    }

    public function sendNotification(Request $request, Subscription $subscription)
    {
        if (!$subscription->client->phone) {
            return response()->json(['status' => false, 'message' => 'لا يوجد رقم هاتف مسجل للعميل']);
        }

        $validator = Validator::make($request->all(), [
            'message' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $client = new \GuzzleHttp\Client();
            $token = env('ULTRAMSG_TOKEN');
            $url = 'https://api.ultramsg.com/instance60138/messages/chat?token=' . urlencode($token);

            $response = $client->post($url, [
                'form_params' => [
                    'to' => $subscription->client->phone,
                    'body' => $request->message,
                ],
                'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            ]);

            $result = json_decode($response->getBody(), true);
            if (!isset($result['sent']) || $result['sent'] !== 'true') {
                throw new \Exception($result['error'] ?? 'خطأ غير محدد');
            }

            return response()->json([
                'status' => true,
                'message' => 'تم إرسال التنبيه بنجاح'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'حدث خطأ أثناء إرسال التنبيه: ' . $e->getMessage()
            ], 500);
        }
    }

    public function exportPdf(Request $request)
    {
        try {
            $subscriptions = Subscription::with(['client' => function ($query) {
                $query->withCount('invoices');
            }])->get();

            $data = $subscriptions->map(function ($sub) {
                $client = $sub->client;
                $totalDue = $client && $client->current_balance <= 0 ? abs($client->current_balance) : 0;
                $totalINV = $client ? $client->invoices()->sum('amount') : 0;

                return [
                    'subscription_number' => $client->subscription_number ?? '-',
                    'invoices_count' => $client->invoices_count ?? 0,
                    'end_date' => $sub->end_date,
                    'total_due' => $totalDue,
                    'total_inv' => $totalINV,
                    'client_phone' => $client->phone ?? '-',
                    'status' => $sub->status,
                ];
            });

            $defaultConfig = config('gpdf');
            $config = new GpdfConfig($defaultConfig);
            $gpdf = new Gpdf($config);
            $html = view('exports.subscriptions', [
                'subscriptions' => $data,
                'title' => 'تقارير الاشتراكات'
            ])->render();
            $pdfContent = $gpdf->generate($html);

            return response($pdfContent, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="subscriptions-report.pdf"',
            ]);
        } catch (\Exception $e) {
            Log::error('PDF Export Error: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'فشل في تصدير الملف: ' . $e->getMessage()
            ], 500);
        }
    }

    public function updateStatus(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:active,expired,canceled',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $subscription = Subscription::findOrFail($id);
            $subscription->update([
                'status' => $request->status,
            ]);

            return response()->json([
                'status' => true,
                'data' => $subscription,
                'message' => 'تم تحديث حالة الاشتراك بنجاح'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'حدث خطأ أثناء تحديث الحالة: ' . $e->getMessage()
            ], 500);
        }
    }
}
