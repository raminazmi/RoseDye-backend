<!DOCTYPE html>
<html>
<head>
    <title>Invoice {{ $invoice->invoice_number }}</title>
    <style>
        /* إضافة تصميم الفاتورة هنا */
    </style>
</head>
<body>
    <h1>Invoice #{{ $invoice->invoice_number }}</h1>
    <p>Date: {{ $invoice->issue_date->format('Y-m-d') }}</p>
    <p>Amount: ${{ number_format($invoice->amount, 2) }}</p>
    <!-- إضافة المزيد من تفاصيل الفاتورة -->
</body>
</html>