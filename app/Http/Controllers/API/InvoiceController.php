<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;
use Carbon\Carbon;

class InvoiceController extends Controller
{
    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 5);
            $page = $request->input('page', 1);

            $invoices = Invoice::with('client')
                ->where('user_id', auth()->id())
                ->paginate($perPage, ['*'], 'page', $page);

            return response()->json([
                'status' => true,
                'data' => $invoices->items(),
                'total' => $invoices->total(),
                'current_page' => $invoices->currentPage(),
                'last_page' => $invoices->lastPage(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'فشل في جلب بيانات الفواتير: ' . $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'client_id' => 'required|exists:clients,id',
            'date' => 'required|date',
            'amount' => 'required|numeric',
        ], [
            'client_id.required' => 'حقل معرف العميل مطلوب.',
            'client_id.exists' => 'حقل معرف العميل غير موجود في قاعدة البيانات.',
            'date.required' => 'حقل التاريخ مطلوب.',
            'date.date' => 'حقل التاريخ يجب أن يكون تاريخًا صحيحًا.',
            'amount.required' => 'حقل المبلغ مطلوب.',
            'amount.numeric' => 'حقل المبلغ يجب أن يكون رقمًا.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $userId = auth()->id();
            if (!$userId) {
                return response()->json([
                    'status' => false,
                    'message' => 'يرجى تسجيل الدخول أولاً'
                ], 401);
            }

            $maxInvoiceNumber = Invoice::where('user_id', $userId)
                ->max('invoice_number');

            if (!$maxInvoiceNumber) {
                $nextNumber = 1;
            } else {
                $numericPart = (int)substr($maxInvoiceNumber, -5);
                $nextNumber = $numericPart + 1;
            }

            $invoiceNumber = str_pad($nextNumber, 5, '0', STR_PAD_LEFT);

            while (Invoice::where('invoice_number', $invoiceNumber)->exists()) {
                $nextNumber++;
                $invoiceNumber = str_pad($nextNumber, 5, '0', STR_PAD_LEFT);
            }

            $invoice = Invoice::create([
                'user_id' => $userId,
                'client_id' => $request->client_id,
                'invoice_number' => $invoiceNumber,
                'date' => $request->date,
                'amount' => $request->amount,
            ]);

            $client = $invoice->client;

            $invoicesCount = $client->invoices()->count();
            $giftAmount = $client->additional_gift ?? 0; // جلب قيمة الهدية من الـ Backend

            if ($invoicesCount == 1 && $giftAmount > 0) {
                // إضافة الهدية إلى الرصيد الحالي إذا كانت الفاتورة الأولى
                $client->current_balance += $giftAmount;
            }

            // خصم مبلغ الفاتورة من الرصيد الحالي
            $client->current_balance -= $request->amount;

            $client->save();

            $subscription = $client->subscriptions()->where('status', 'active')->first();
            if ($subscription) {
                $remaining = $client->current_balance;
                $message = "تم إضافة فاتورة رقم {$invoice->invoice_number} للاشتراك رقم {$client->subscription_number} بقيمة {$request->amount} د.ك. المتبقي: {$remaining} د.ك. ينتهي اشتراكك في {$subscription->end_date}";
                if ($invoicesCount == 1 && $giftAmount > 0) {
                    $message .= " كما تم إضافة مبلغ هدية من الموقع بقيمة {$giftAmount} د.ك.";
                }
                $this->sendWhatsAppNotification($client->phone, $message);
            }

            return response()->json([
                'status' => true,
                'message' => 'تم إنشاء الفاتورة بنجاح',
                'data' => $invoice
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'حدث خطأ أثناء إنشاء الفاتورة: ' . $e->getMessage()
            ], 500);
        }
    }

    private function sendWhatsAppNotification($phone, $message)
    {
        try {
            $client = new \GuzzleHttp\Client();
            $token = env('ULTRAMSG_TOKEN');
            $url = 'https://api.ultramsg.com/instance60138/messages/chat?token=' . urlencode($token);

            $response = $client->post($url, [
                'form_params' => [
                    'to' => $phone,
                    'body' => $message,
                ],
                'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            ]);

            $result = json_decode($response->getBody(), true);
            if (!isset($result['sent']) || $result['sent'] !== 'true') {
                throw new \Exception($result['error'] ?? 'خطأ غير محدد');
            }
        } catch (\Exception $e) {
            \Log::error('فشل إرسال WhatsApp: ' . $e->getMessage());
        }
    }

    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'client_id' => 'required|exists:clients,id',
            'date' => 'required|date',
            'amount' => 'required|numeric',
        ], [
            'client_id.required' => 'حقل معرف العميل مطلوب.',
            'client_id.exists' => 'حقل معرف العميل غير موجود في قاعدة البيانات.',
            'date.required' => 'حقل التاريخ مطلوب.',
            'date.date' => 'حقل التاريخ يجب أن يكون تاريخًا صحيحًا.',
            'amount.required' => 'حقل المبلغ مطلوب.',
            'amount.numeric' => 'حقل المبلغ يجب أن يكون رقمًا.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $invoice = Invoice::where('user_id', auth()->id())->findOrFail($id);

            $oldAmount = $invoice->amount;
            $client = $invoice->client;

            $client->current_balance += $oldAmount;
            $client->current_balance -= $request->amount;

            $invoice->update([
                'client_id' => $request->client_id,
                'date' => $request->date,
                'amount' => $request->amount,
            ]);

            $client->save();

            return response()->json([
                'status' => true,
                'message' => 'تم تحديث الفاتورة بنجاح',
                'data' => $invoice
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'حدث خطأ أثناء تحديث الفاتورة: ' . $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $invoice = Invoice::with('client')
                ->where('user_id', auth()->id())
                ->findOrFail($id);

            return response()->json([
                'status' => true,
                'data' => $invoice
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'حدث خطأ أثناء جلب بيانات الفاتورة: ' . $e->getMessage()
            ], 404);
        }
    }

    public function destroy($id)
    {
        try {
            $invoice = Invoice::where('user_id', auth()->id())->findOrFail($id);
            $client = $invoice->client;

            $invoicesCount = $client->invoices()->count();
            $giftAmount = $client->additional_gift ?? 0;

            if ($invoicesCount == 1 && $giftAmount > 0) {
                $client->current_balance -= $giftAmount;
            }
            $client->current_balance += $invoice->amount;

            $client->save();
            $invoice->delete();

            return response()->json([
                'status' => true,
                'message' => 'تم حذف الفاتورة بنجاح'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'حدث خطأ أثناء الحذف: ' . $e->getMessage()
            ], 500);
        }
    }
}
