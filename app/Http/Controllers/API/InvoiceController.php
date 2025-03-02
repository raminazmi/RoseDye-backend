<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

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
            'issue_date' => 'required|date',
            'due_date' => 'required|date|after:issue_date',
            'amount' => 'required|numeric',
            'description' => 'nullable|string',
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
                'issue_date' => $request->issue_date,
                'due_date' => $request->due_date,
                'amount' => $request->amount,
                'description' => $request->description,
                'status' => 'unpaid',
            ]);

            return response()->json([
                'status' => true,
                'data' => $invoice,
                'message' => 'تم إضافة الفاتورة بنجاح'
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'حدث خطأ أثناء الحفظ: ' . $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $invoice = Invoice::with('client')->where('user_id', auth()->id())->findOrFail($id);
            return response()->json([
                'status' => true,
                'data' => $invoice
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'الفاتورة غير موجودة'
            ], 404);
        }
    }

    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'client_id' => 'required|exists:clients,id',
            'issue_date' => 'required|date',
            'due_date' => 'required|date|after:issue_date',
            'amount' => 'required|numeric',
            'description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $invoice = Invoice::where('user_id', auth()->id())->findOrFail($id);

            $invoice->update([
                'client_id' => $request->client_id,
                'issue_date' => $request->issue_date,
                'due_date' => $request->due_date,
                'amount' => $request->amount,
                'description' => $request->description,
            ]);

            return response()->json([
                'status' => true,
                'data' => $invoice,
                'message' => 'تم تعديل الفاتورة بنجاح'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'حدث خطأ أثناء التعديل: ' . $e->getMessage()
            ], 500);
        }
    }

    public function updateStatus(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:paid,unpaid',
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

            \DB::transaction(function () use ($invoice, $client, $request) {
                $oldStatus = $invoice->status;
                $newStatus = $request->status;

                if ($oldStatus === 'paid' && $newStatus === 'unpaid') {
                    $client->current_balance += $invoice->amount;
                } elseif ($oldStatus === 'unpaid' && $newStatus === 'paid') {
                    $client->current_balance -= $invoice->amount;
                }
                $client->save();
                $invoice->update([
                    'status' => $newStatus,
                ]);
            });

            return response()->json([
                'status' => true,
                'data' => $invoice,
                'message' => 'تم تحديث حالة الفاتورة بنجاح'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'حدث خطأ أثناء تحديث الحالة: ' . $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $invoice = Invoice::where('user_id', auth()->id())->findOrFail($id);
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
