<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Omaralalwi\Gpdf\Gpdf;
use Illuminate\Support\Facades\Validator;
use Omaralalwi\Gpdf\GpdfConfig;
use Carbon\Carbon;

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
                // التعديل هنا: استخدام renewal_balance بدلاً من current_balance
                $totalDue = $client && $client->renewal_balance < 0 ? abs($client->renewal_balance) : 0;
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

    public function renew(Request $request, $id)
    {
        try {
            $subscription = Subscription::findOrFail($id);
            $client = $subscription->client;

            $renewalCost = $request->input('renewal_cost');
            $gift = $request->input('gift', 0); // استقبال قيمة الهدية، افتراضيًا 0 إذا لم تُدخل

            // التحقق من صحة المدخلات
            if (!is_numeric($renewalCost) || $renewalCost <= 0) {
                throw new \Exception('قيمة التجديد غير صالحة');
            }
            if (!is_numeric($gift) || $gift < 0) {
                throw new \Exception('قيمة الهدية غير صالحة');
            }

            // 1. حساب المبلغ المستحق من الرصيد السابق
            $previousBalance = $client->renewal_balance;
            $totalDue = $previousBalance < 0 ? abs($previousBalance) : 0;

            // 2. خصم من الهدية أولاً
            $deductedFromGift = min($totalDue, $client->additional_gift);
            $client->additional_gift -= $deductedFromGift;
            $totalDue -= $deductedFromGift;

            // 3. حساب المبلغ الفعلي المضاف بعد الخصم
            $netRenewal = $renewalCost - $totalDue;

            // 4. تحديث رصيد التجديد
            $client->renewal_balance = $netRenewal;

            // 5. إضافة قيمة الهدية إلى additional_gift
            $client->additional_gift += $gift;

            // 6. تجديد فترة الاشتراك
            $durationInDays = $subscription->duration_in_days;

            if (!is_numeric($durationInDays) || $durationInDays <= 0) {
                throw new \Exception('مدة الاشتراك غير صالحة');
            }
            $newEndDate = Carbon::now()->addDays($durationInDays);

            $subscription->update([
                'start_date' => Carbon::now(),
                'end_date' => $newEndDate,
                'status' => 'active'
            ]);

            $client->save();

            // إنشاء الرسالة مع تفاصيل الإضافة والخصم والهدية
            $message = "تم تجديد الاشتراك رقم {$client->subscription_number} بنجاح. ";
            $message .= "تم إضافة {$renewalCost} د.ك إلى رصيد التجديد. ";
            if ($totalDue > 0) {
                $message .= "تم خصم مبلغ {$totalDue} د.ك مستحق. ";
            }
            if ($deductedFromGift > 0) {
                $message .= "تم خصم {$deductedFromGift} د.ك من رصيد الهدية. ";
            }
            if ($gift > 0) {
                $message .= "تم إضافة {$gift} د.ك كهدية. ";
            }
            $message .= "رصيد التجديد الجديد: {$client->renewal_balance} د.ك. ";
            $message .= "رصيد الهدية الجديد: {$client->additional_gift} د.ك. ";
            $message .= "تاريخ الانتهاء الجديد: {$newEndDate->format('d-m-Y')}.";

            // إرسال الإشعارات
            $notificationRequest = new Request(['message' => $message]);
            $this->sendNotification($notificationRequest, $subscription);

            return response()->json([
                'status' => true,
                'message' => 'تم التجديد بنجاح',
                'data' => [
                    'renewal_balance' => $client->renewal_balance,
                    'additional_gift' => $client->additional_gift,
                    'end_date' => $subscription->end_date
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'حدث خطأ: ' . $e->getMessage()
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
                // التعديل هنا: استخدام renewal_balance بدلاً من current_balance
                $totalDue = $client && $client->renewal_balance < 0 ? abs($client->renewal_balance) : 0;
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
            'status' => 'required|in:active,canceled',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $subscription = Subscription::findOrFail($id);

            if ($request->status === 'active') {
                $today = Carbon::now();
                if ($today->greaterThan($subscription->end_date)) {
                    return response()->json([
                        'status' => false,
                        'message' => 'لا تستطيع تحديث الحالة إلى "نشط" لأن تاريخ انتهاء الاشتراك قد انتهى. يجب أن يجدد أولاً.'
                    ], 403);
                }
            }

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
