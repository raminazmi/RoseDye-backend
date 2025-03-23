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
                'amount' => $request->amount,
            ]);

            $client = $invoice->client;
            $invoicesCount = $client->invoices()->count();

            if ($invoicesCount == 1 && !is_null($client->original_gift) && $client->original_gift > 0) {
                $client->additional_gift = $client->original_gift;
            }

            $giftAmount = $client->additional_gift ?? 0;

            $deductedFromGift = 0;
            $deductedFromBalance = 0;

            $remainingAmount = $request->amount;

            if ($giftAmount > 0) {
                if ($giftAmount >= $remainingAmount) {
                    $deductedFromGift = $remainingAmount;
                    $client->additional_gift -= $remainingAmount;
                    $remainingAmount = 0;
                } else {
                    $deductedFromGift = $giftAmount;
                    $remainingAmount -= $giftAmount;
                    $client->additional_gift = 0;
                }
            }

            if ($remainingAmount > 0) {
                $deductedFromBalance = $remainingAmount;
                $client->current_balance -= $remainingAmount;
            }

            $client->save();

            // $subscription = $client->subscriptions()->where('status', 'active')->first();
            // if ($subscription) {
            //     $remaining = $client->current_balance;
            //     $message = "تم إضافة فاتورة رقم {$invoice->invoice_number} للاشتراك رقم {$client->subscription_number} بقيمة {$request->amount} د.ك. المتبقي: {$remaining} د.ك. ينتهي اشتراكك في {$subscription->end_date}";

            //     if ($invoicesCount == 1 && !is_null($client->original_gift) && $client->original_gift > 0) {
            //         $message .= " كما تم إضافة مبلغ هدية من الموقع بقيمة {$client->original_gift} د.ك.";
            //     }

            //     $deductionDetails = '';
            //     if ($deductedFromGift > 0) {
            //         $deductionDetails .= "تم خصم {$deductedFromGift} د.ك من الهدية";
            //     }
            //     if ($deductedFromBalance > 0) {
            //         $deductionDetails .= $deductedFromGift > 0 ? " و" : "تم خصم ";
            //         $deductionDetails .= " {$deductedFromBalance} د.ك من الرصيد الحالي";
            //     }
            //     if ($deductionDetails) {
            //         $message .= ". " . $deductionDetails . ".";
            //     }

            //     $this->sendWhatsAppNotification($client->phone, $message);
            // }

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
            $client = $invoice->client;

            $restoredToGift = 0;
            $restoredToBalance = 0;
            $deductedFromGift = 0;
            $deductedFromBalance = 0;

            $oldAmount = $invoice->amount;
            $originalGift = $client->original_gift ?? 0;
            $currentGift = $client->additional_gift ?? 0;

            if ($originalGift > 0) {
                $availableGiftSpace = $originalGift - $currentGift;
                if ($availableGiftSpace > 0) {
                    if ($oldAmount <= $availableGiftSpace) {
                        $restoredToGift = $oldAmount;
                        $client->additional_gift += $restoredToGift;
                        $oldAmount = 0;
                    } else {
                        $restoredToGift = $availableGiftSpace;
                        $client->additional_gift += $restoredToGift;
                        $oldAmount -= $restoredToGift;
                        $restoredToBalance = $oldAmount;
                        $client->current_balance += $restoredToBalance;
                    }
                } else {
                    $restoredToBalance = $oldAmount;
                    $client->current_balance += $restoredToBalance;
                }
            } else {
                $restoredToBalance = $oldAmount;
                $client->current_balance += $restoredToBalance;
            }

            $remainingAmount = $request->amount;
            $giftAmount = $client->additional_gift ?? 0;

            if ($giftAmount > 0) {
                if ($giftAmount >= $remainingAmount) {
                    $deductedFromGift = $remainingAmount;
                    $client->additional_gift -= $remainingAmount;
                    $remainingAmount = 0;
                } else {
                    $deductedFromGift = $giftAmount;
                    $remainingAmount -= $giftAmount;
                    $client->additional_gift = 0;
                }
            }

            if ($remainingAmount > 0) {
                $deductedFromBalance = $remainingAmount;
                $client->current_balance -= $remainingAmount;
            }

            $invoice->update([
                'client_id' => $request->client_id,
                'date' => $request->date,
                'amount' => $request->amount,
            ]);

            $client->save();

            // $subscription = $client->subscriptions()->where('status', 'active')->first();
            // if ($subscription) {
            //     $remaining = $client->current_balance;
            //     $message = "تم تحديث فاتورة رقم {$invoice->invoice_number} للاشتراك رقم {$client->subscription_number} بقيمة {$request->amount} د.ك. المتبقي: {$remaining} د.ك. ينتهي اشتراكك في {$subscription->end_date}";

            //     $restorationDetails = '';
            //     if ($restoredToGift > 0) {
            //         $restorationDetails .= "تمت استعادة {$restoredToGift} د.ك إلى الهدية";
            //     }
            //     if ($restoredToBalance > 0) {
            //         $restorationDetails .= $restoredToGift > 0 ? " و" : "تمت استعادة ";
            //         $restorationDetails .= " {$restoredToBalance} د.ك إلى الرصيد الحالي";
            //     }
            //     if ($restorationDetails) {
            //         $message .= ". " . $restorationDetails;
            //     }

            //     $deductionDetails = '';
            //     if ($deductedFromGift > 0) {
            //         $deductionDetails .= "تم خصم {$deductedFromGift} د.ك من الهدية";
            //     }
            //     if ($deductedFromBalance > 0) {
            //         $deductionDetails .= $deductedFromGift > 0 ? " و" : "تم خصم ";
            //         $deductionDetails .= " {$deductedFromBalance} د.ك من الرصيد الحالي";
            //     }
            //     if ($deductionDetails) {
            //         $message .= ". " . $deductionDetails . ".";
            //     }

            //     $this->sendWhatsAppNotification($client->phone, $message);
            // }

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

            $restoredAmount = $invoice->amount;
            $originalGift = $client->original_gift ?? 0;
            $currentGift = $client->additional_gift ?? 0;
            $restoredToGift = 0;
            $restoredToBalance = 0;

            if ($originalGift > 0) {
                $availableGiftSpace = $originalGift - $currentGift;
                if ($availableGiftSpace > 0) {
                    if ($restoredAmount <= $availableGiftSpace) {
                        $restoredToGift = $restoredAmount;
                        $client->additional_gift += $restoredToGift;
                        $restoredAmount = 0;
                    } else {
                        $restoredToGift = $availableGiftSpace;
                        $client->additional_gift += $restoredToGift;
                        $restoredAmount -= $restoredToGift;
                        $restoredToBalance = $restoredAmount;
                        $client->current_balance += $restoredToBalance;
                    }
                } else {
                    $restoredToBalance = $restoredAmount;
                    $client->current_balance += $restoredToBalance;
                }
            } else {
                $restoredToBalance = $restoredAmount;
                $client->current_balance += $restoredToBalance;
            }

            $client->save();

            // $subscription = $client->subscriptions()->where('status', 'active')->first();
            // if ($subscription) {
            //     $remaining = $client->current_balance;
            //     $message = "تم حذف فاتورة رقم {$invoice->invoice_number} للاشتراك رقم {$client->subscription_number}. تمت استعادة {$invoice->amount} د.ك إلى رصيدك. المتبقي: {$remaining} د.ك. ينتهي اشتراكك في {$subscription->end_date}.";

            //     $restorationDetails = '';
            //     if ($restoredToGift > 0) {
            //         $restorationDetails .= "تمت استعادة {$restoredToGift} د.ك إلى الهدية";
            //     }
            //     if ($restoredToBalance > 0) {
            //         $restorationDetails .= $restoredToGift > 0 ? " و" : "تمت استعادة ";
            //         $restorationDetails .= " {$restoredToBalance} د.ك إلى الرصيد الحالي";
            //     }
            //     if ($restorationDetails) {
            //         $message .= " " . $restorationDetails . ".";
            //     }

            //     $this->sendWhatsAppNotification($client->phone, $message);
            // }

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
