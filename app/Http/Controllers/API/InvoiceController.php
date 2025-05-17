<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Client;

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
        // التحقق من صحة البيانات المدخلة
        $validator = Validator::make($request->all(), [
            'client_id' => 'required|exists:clients,id',
            'date' => 'required|date',
            'amount' => 'required|numeric|min:0', // يسمح بالأرقام العشرية أو الصحيحة
        ], [
            'client_id.required' => 'حقل معرف العميل مطلوب.',
            'client_id.exists' => 'حقل معرف العميل غير موجود في قاعدة البيانات.',
            'date.required' => 'حقل التاريخ مطلوب.',
            'date.date' => 'حقل التاريخ يجب أن يكون تاريخًا صحيحًا.',
            'amount.required' => 'حقل المبلغ مطلوب.',
            'amount.numeric' => 'حقل المبلغ يجب أن يكون رقمًا.',
            'amount.min' => 'حقل المبلغ يجب ألا يكون سالبًا.',
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

            $maxInvoiceNumber = Invoice::where('user_id', $userId)->max('invoice_number');
            $nextNumber = $maxInvoiceNumber ? (int)substr($maxInvoiceNumber, -5) + 1 : 1;
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
                'amount' => $request->amount, // يُخزن المبلغ كما أُدخل (مثل 35.25)
            ]);

            $client = $invoice->client;
            $amount = $request->amount;

            if ($client->additional_gift >= $amount) {
                $client->additional_gift -= $amount;
                $amount = 0;
            } else {
                $amount -= $client->additional_gift;
                $client->additional_gift = 0;
            }

            $client->renewal_balance -= $amount;
            $client->current_balance += $request->amount;
            $client->save();
            $formattedDate = \Carbon\Carbon::parse($invoice->date)->format('Y-m-d');

            $message = "تم إضافة فاتورة جديدة بنجاح. ";
            $message .= "رقم الفاتورة: {$invoice->invoice_number} ";
            $message .= "التاريخ: {$formattedDate} ";
            $message .= "المبلغ: {$invoice->amount} د.ك ";
            $message .= "شكرًا لك!";

            if ($client->phone) {
                $this->sendWhatsAppNotification($client->phone, $message);
            } else {
                Log::warning("لم يتم إرسال رسالة WhatsApp لأن رقم الهاتف غير متوفر للعميل ID: {$client->id}");
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

    public function getNextInvoiceNumber(Request $request)
    {
        try {
            $userId = auth()->id();
            if (!$userId) {
                return response()->json([
                    'status' => false,
                    'message' => 'يرجى تسجيل الدخول أولاً'
                ], 401);
            }

            $maxInvoiceNumber = Invoice::where('user_id', $userId)->max('invoice_number');
            $nextNumber = $maxInvoiceNumber ? (int)substr($maxInvoiceNumber, -5) + 1 : 1;
            $invoiceNumber = str_pad($nextNumber, 5, '0', STR_PAD_LEFT);

            while (Invoice::where('invoice_number', $invoiceNumber)->exists()) {
                $nextNumber++;
                $invoiceNumber = str_pad($nextNumber, 5, '0', STR_PAD_LEFT);
            }

            return response()->json([
                'status' => true,
                'next_invoice_number' => $invoiceNumber
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'فشل في توليد الرقم: ' . $e->getMessage()
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
            'amount' => 'required|numeric|min:0',
        ], [
            'client_id.required' => 'حقل معرف العميل مطلوب.',
            'client_id.exists' => 'حقل معرف العميل غير موجود في قاعدة البيانات.',
            'date.required' => 'حقل التاريخ مطلوب.',
            'date.date' => 'حقل التاريخ يجب أن يكون تاريخًا صحيحًا.',
            'amount.required' => 'حقل المبلغ مطلوب.',
            'amount.numeric' => 'حقل المبلغ يجب أن يكون رقمًا.',
            'amount.min' => 'حقل المبلغ يجب ألا يكون سالبًا.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $invoice = Invoice::where('user_id', auth()->id())->findOrFail($id);
            $client = $invoice->client;

            // استعادة المبلغ القديم من الرصيد الحالي
            $client->current_balance -= $invoice->amount;

            // إعادة توزيع المبلغ القديم إلى رصيد الهدية ورصيد التجديد
            $oldAmount = $invoice->amount;
            if ($oldAmount > 0) {
                $giftCapacity = $client->additional_gift; // الحد الأقصى الذي يمكن إضافته إلى الهدية
                $restoredToGift = min($oldAmount, $giftCapacity);
                $client->additional_gift += $restoredToGift;
                $oldAmount -= $restoredToGift;

                if ($oldAmount > 0) {
                    $client->renewal_balance += $oldAmount;
                }
            }

            // تحديث الفاتورة بالبيانات الجديدة
            $invoice->update([
                'client_id' => $request->client_id,
                'date' => $request->date,
                'amount' => $request->amount,
            ]);

            // خصم المبلغ الجديد من رصيد الهدية أولاً، ثم من رصيد التجديد
            $newAmount = $request->amount;
            $deductedFromGift = 0;
            $deductedFromRenewal = 0;

            if ($client->additional_gift > 0 && $newAmount > 0) {
                $deductedFromGift = min($client->additional_gift, $newAmount);
                $client->additional_gift -= $deductedFromGift;
                $newAmount -= $deductedFromGift;
            }

            if ($newAmount > 0 && $client->renewal_balance > 0) {
                $deductedFromRenewal = min($client->renewal_balance, $newAmount);
                $client->renewal_balance -= $deductedFromRenewal;
                $newAmount -= $deductedFromRenewal;
            }

            // إضافة المبلغ الجديد إلى الرصيد الحالي
            $client->current_balance += $request->amount;
            $client->save();

            $subscription = $client->subscriptions()->where('status', 'active')->first();
            if ($subscription) {
                $remaining = $client->current_balance;
                $message = "تم تحديث فاتورة رقم {$invoice->invoice_number} للاشتراك رقم {$client->subscription_number} بقيمة {$request->amount} د.ك. المتبقي: {$remaining} د.ك. ينتهي اشتراكك في {$subscription->end_date}";

                $restorationDetails = '';
                if ($restoredToGift > 0) {
                    $restorationDetails .= "تمت استعادة {$restoredToGift} د.ك إلى الهدية";
                }
                if ($oldAmount > 0) {
                    $restorationDetails .= $restoredToGift > 0 ? " و" : "تمت استعادة ";
                    $restorationDetails .= " {$oldAmount} د.ك إلى الرصيد الحالي";
                }
                if ($restorationDetails) {
                    $message .= ". " . $restorationDetails;
                }

                $deductionDetails = '';
                if ($deductedFromGift > 0) {
                    $deductionDetails .= "تم خصم {$deductedFromGift} د.ك من الهدية";
                }
                if ($deductedFromRenewal > 0) {
                    $deductionDetails .= $deductedFromGift > 0 ? " و" : "تم خصم ";
                    $deductionDetails .= " {$deductedFromRenewal} د.ك من الرصيد الحالي";
                }
                if ($deductionDetails) {
                    $message .= ". " . $deductionDetails . ".";
                }

                $this->sendWhatsAppNotification($client->phone, $message);
            }

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

            // استعادة المبلغ من الرصيد الحالي
            $client->current_balance -= $invoice->amount;

            // إعادة توزيع المبلغ إلى رصيد الهدية ورصيد التجديد
            $amount = $invoice->amount;
            $restoredToGift = 0;
            if ($amount > 0) {
                $giftCapacity = $client->additional_gift; // الحد الأقصى الذي يمكن إضافته إلى الهدية
                $restoredToGift = min($amount, $giftCapacity);
                $client->additional_gift += $restoredToGift;
                $amount -= $restoredToGift;

                if ($amount > 0) {
                    $client->renewal_balance += $amount;
                }
            }

            $client->save();

            $subscription = $client->subscriptions()->where('status', 'active')->first();
            if ($subscription) {
                $remaining = $client->current_balance;
                $message = "تم حذف فاتورة رقم {$invoice->invoice_number} للاشتراك رقم {$client->subscription_number}. تمت استعادة {$invoice->amount} د.ك إلى رصيدك. المتبقي: {$remaining} د.ك. ينتهي اشتراكك في {$subscription->end_date}.";

                $restorationDetails = '';
                if ($restoredToGift > 0) {
                    $restorationDetails .= "تمت استعادة {$restoredToGift} د.ك إلى الهدية";
                }
                if ($amount > 0) {
                    $restorationDetails .= $restoredToGift > 0 ? " و" : "تمت استعادة ";
                    $restorationDetails .= " {$amount} د.ك إلى الرصيد الحالي";
                }
                if ($restorationDetails) {
                    $message .= " " . $restorationDetails . ".";
                }

                $this->sendWhatsAppNotification($client->phone, $message);
            }

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
