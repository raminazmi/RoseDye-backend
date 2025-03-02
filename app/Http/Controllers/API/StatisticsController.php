<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\{Client, Invoice, Subscription};
use Illuminate\Http\Request;
use Carbon\Carbon;

class StatisticsController extends Controller
{
    public function index()
    {
        $previousMonth = Carbon::now()->subMonth();

        // عدد العملاء
        $current_clients = Client::count();
        $previous_clients = Client::where('created_at', '<', $previousMonth)->count();
        $clients_rate = $previous_clients > 0 ? (($current_clients - $previous_clients) / $previous_clients * 100) : 0;

        // عدد الاشتراكات النشطة
        $current_subscriptions = Subscription::where('status', 'active')->count();
        $previous_subscriptions = Subscription::where('status', 'active')->where('created_at', '<', $previousMonth)->count();
        $subscriptions_rate = $previous_subscriptions > 0 ? (($current_subscriptions - $previous_subscriptions) / $previous_subscriptions * 100) : 0;

        // إجمالي الإيرادات
        $current_revenue = Invoice::sum('amount') ?? 0;
        $previous_revenue = Invoice::where('created_at', '<', $previousMonth)->sum('amount') ?? 0;
        $revenue_rate = $previous_revenue > 0 ? (($current_revenue - $previous_revenue) / $previous_revenue * 100) : 0;

        // الفواتير المدفوعة وغير المدفوعة وإجمالي الفواتير
        $current_paid_invoices = Invoice::where('status', 'paid')->count();
        $current_unpaid_invoices = Invoice::where('status', 'unpaid')->count();
        $total_invoices = $current_paid_invoices + $current_unpaid_invoices;

        $previous_paid_invoices = Invoice::where('status', 'paid')->where('created_at', '<', $previousMonth)->count();
        $previous_unpaid_invoices = Invoice::where('status', 'unpaid')->where('created_at', '<', $previousMonth)->count();
        $previous_total_invoices = $previous_paid_invoices + $previous_unpaid_invoices;

        $paid_invoices_rate = $previous_paid_invoices > 0 ? (($current_paid_invoices - $previous_paid_invoices) / $previous_paid_invoices * 100) : 0;
        $unpaid_invoices_rate = $previous_unpaid_invoices > 0 ? (($current_unpaid_invoices - $previous_unpaid_invoices) / $previous_unpaid_invoices * 100) : 0;
        $total_invoices_rate = $previous_total_invoices > 0 ? (($total_invoices - $previous_total_invoices) / $previous_total_invoices * 100) : 0;

        return response()->json([
            'total_clients' => $current_clients,
            'active_subscriptions' => $current_subscriptions,
            'total_revenue' => $current_revenue,
            'total_invoices' => $total_invoices,
            'paid_invoices' => $current_paid_invoices,
            'unpaid_invoices' => $current_unpaid_invoices,
            'recent_clients' => Client::latest()->take(5)->get(),
            'upcoming_renewals' => Subscription::where('end_date', '>', now())
                ->orderBy('end_date')
                ->take(5)
                ->get(),
            'clients_rate' => number_format($clients_rate, 2),
            'subscriptions_rate' => number_format($subscriptions_rate, 2),
            'revenue_rate' => number_format($revenue_rate, 2),
            'total_invoices_rate' => number_format($total_invoices_rate, 2),
            'paid_invoices_rate' => number_format($paid_invoices_rate, 2),
            'unpaid_invoices_rate' => number_format($unpaid_invoices_rate, 2),
        ]);
    }

    // إحصائيات يومية
    public function daily()
    {
        $today = Carbon::today();
        $hours = [];
        $revenue = [];
        $invoices = [];

        for ($i = 0; $i < 24; $i++) {
            $hour = $today->copy()->setHour($i);
            $hours[] = $hour->format('H:00');
            $revenue[] = Invoice::whereBetween('created_at', [$hour, $hour->copy()->endOfHour()])
                ->sum('amount') ?? 0;
            $invoices[] = Invoice::whereBetween('created_at', [$hour, $hour->copy()->endOfHour()])
                ->count() ?? 0;
        }

        return response()->json([
            'hours' => $hours,
            'revenue' => $revenue,
            'invoices' => $invoices,
        ]);
    }

    // إحصائيات أسبوعية
    public function weekly()
    {
        $startOfWeek = Carbon::now()->startOfWeek();
        $days = [];
        $revenue = [];
        $invoices = [];

        for ($i = 0; $i < 7; $i++) {
            $day = $startOfWeek->copy()->addDays($i);
            $days[] = $day->locale('ar')->isoFormat('ddd'); // أسماء الأيام بالعربية
            $revenue[] = Invoice::whereDate('created_at', $day)->sum('amount') ?? 0;
            $invoices[] = Invoice::whereDate('created_at', $day)->count() ?? 0;
        }

        return response()->json([
            'days' => $days,
            'revenue' => $revenue,
            'invoices' => $invoices,
        ]);
    }

    public function lastWeek(Request $request)
    {
        try {
            $isLastWeek = $request->query('last_week') === 'true';
            $startDate = $isLastWeek ? Carbon::now()->subWeek()->startOfWeek() : Carbon::now()->startOfWeek();
            $endDate = $startDate->copy()->endOfWeek();

            $days = [];
            $revenue = [];
            $subscriptions = [];
            $currentDate = $startDate->copy();

            for ($i = 0; $i < 7; $i++) {
                $dayName = $currentDate->locale('ar')->dayName;
                $days[] = $dayName;

                $dailyRevenue = Subscription::whereDate('created_at', $currentDate)
                    ->with('client.invoices')
                    ->get()
                    ->sum(function ($sub) {
                        return $sub->client->invoices->where('status', 'paid')->sum('amount');
                    });
                $revenue[] = $dailyRevenue;

                $dailySubscriptions = Subscription::whereDate('created_at', $currentDate)->count();
                $subscriptions[] = $dailySubscriptions;

                $currentDate->addDay();
            }

            return response()->json([
                'status' => true,
                'data' => [
                    'days' => $days,
                    'revenue' => $revenue,
                    'subscriptions' => $subscriptions,
                ],
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error fetching weekly statistics: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'فشل في جلب الإحصائيات الأسبوعية',
            ], 500);
        }
    }

    // إحصائيات شهرية
    public function monthly()
    {
        if (!auth('sanctum')->check()) {
            return response()->json(['message' => 'غير مصرح لك'], 401);
        }

        $startOfYear = Carbon::now()->startOfYear();
        $months = [];
        $revenue = [];
        $invoices = [];

        foreach (range(0, 11) as $i) {
            $month = $startOfYear->copy()->addMonths($i);
            $monthName = $month->locale('ar')->isoFormat('MMM');
            $months[] = $monthName;
            $rev = Invoice::whereMonth('created_at', $month->month)
                ->whereYear('created_at', $month->year)
                ->sum('amount') ?? 0;
            $inv = Invoice::whereMonth('created_at', $month->month)
                ->whereYear('created_at', $month->year)
                ->count() ?? 0;
            $revenue[] = $rev;
            $invoices[] = $inv;

            \Log::info("Month: {$monthName}, Revenue: {$rev}, Invoices: {$inv}");
        }

        return response()->json([
            'months' => $months,
            'revenue' => $revenue,
            'invoices' => $invoices,
        ]);
    }
}
