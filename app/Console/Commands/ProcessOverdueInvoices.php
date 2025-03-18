<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\Client;
use Illuminate\Console\Command;

class ProcessOverdueInvoices extends Command
{
    protected $signature = 'invoices:process-overdue';
    protected $description = 'خصم مبلغ الفواتير المنتهية تلقائياً من رصيد العميل';

    public function handle()
    {
        $overdueInvoices = Invoice::where('due_date', '<=', now())
            ->where('is_processed', false)
            ->get();

        foreach ($overdueInvoices as $invoice) {
            try {
                \DB::transaction(function () use ($invoice) {
                    $client = Client::find($invoice->client_id);

                    if ($client) {
                        $client->current_balance -= $invoice->amount;
                        $client->save();

                        $invoice->update([
                            'is_processed' => true,
                        ]);
                    }
                });
            } catch (\Exception $e) {
                \Log::error('فشل في معالجة الفاتورة: ' . $invoice->id . ' - ' . $e->getMessage());
            }
        }

        $this->info('تم معالجة ' . $overdueInvoices->count() . ' فاتورة بنجاح');
    }
}
