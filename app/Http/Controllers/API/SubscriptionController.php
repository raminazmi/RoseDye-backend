<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Mpdf\Mpdf;

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
                $totalDue = $client ? $client->invoices()->where('status', 'unpaid')->sum('amount') : 0;

                return [
                    'id' => $sub->id,
                    'subscription_number' => $client->subscription_number ?? 'N/A',
                    'invoices_count' => $client->invoices_count ?? 0,
                    'end_date' => $sub->end_date,
                    'total_due' => $totalDue,
                    'client_phone' => $client->phone ?? 'N/A'
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
            Log::error('Subscription Error: ' . $e->getMessage() . ' | Stack: ' . $e->getTraceAsString());
            return response()->json([
                'status' => false,
                'message' => 'الاشتراك غير موجود: ' . $e->getMessage()
            ], 404);
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
            $params = [
                'token' => env('ULTRAMSG_TOKEN'),
                'to' => $subscription->client->phone,
                'body' => $request->message,
            ];

            $response = $client->post('https://api.ultramsg.com/instance60138/messages/chat', [
                'form_params' => $params,
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
            ]);

            $result = json_decode($response->getBody(), true);

            if (isset($result['sent']) && $result['sent'] === 'true') {
                return response()->json([
                    'status' => true,
                    'message' => 'تم إرسال التنبيه بنجاح'
                ]);
            } else {
                return response()->json([
                    'status' => false,
                    'message' => 'فشل في إرسال التنبيه: ' . ($result['error'] ?? 'خطأ غير محدد')
                ], 500);
            }
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
                $totalDue = $client ? $client->invoices()->where('status', 'unpaid')->sum('amount') : 0;

                return [
                    'subscription_number' => $client->subscription_number ?? '-',
                    'invoices_count' => $client->invoices_count ?? 0,
                    'end_date' => $sub->end_date,
                    'total_due' => $totalDue,
                    'client_phone' => $client->phone ?? '-'
                ];
            });

            if ($data->isEmpty()) {
                return response()->json([
                    'status' => false,
                    'message' => 'لا توجد بيانات لتصديرها'
                ], 404);
            }

            $mpdf = new Mpdf([
                'mode' => 'utf-8',
                'format' => 'A4',
                'margin_top' => 20,
                'margin_bottom' => 20,
                'margin_left' => 15,
                'margin_right' => 15,
                'default_font' => 'almarai',
            ]);

            $html = view('exports.subscriptions', [
                'subscriptions' => $data,
                'title' => 'تقارير الاشتراكات'
            ])->render();

            $mpdf->WriteHTML($html);

            return $mpdf->Output('subscriptions-report.pdf', 'D');
        } catch (\Exception $e) {
            Log::error('PDF Export Error: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'فشل في تصدير الملف: ' . $e->getMessage()
            ], 500);
        }
    }
}
