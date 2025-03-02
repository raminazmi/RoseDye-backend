<!DOCTYPE html>
<html dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }}</title>
    <link href="https://fonts.googleapis.com/css2?family=Almarai:wght@300;400;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Almarai', sans-serif;
            margin: 0;
            padding: 0;
            background-color: #fff;
            color: #000;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        th,
        td {
            padding: 10px;
            border: 1px solid #ddd;
            text-align: right;
        }

        th {
            background-color: #f5f5f5;
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
        }

        .title {
            font-size: 24px;
            color: #333;
        }
    </style>
</head>

<body>
    <div class="header">
        <h1 class="title">{{ $title }}</h1>
        <p>تاريخ التصدير: {{ now()->format('Y-m-d H:i') }}</p>
    </div>

    <table>
        <thead>
            <tr>
                <th>رقم الاشتراك</th>
                <th>عدد الفواتير</th>
                <th>تاريخ الانتهاء</th>
                <th>المجموع المستحق</th>
                <th>هاتف العميل</th>
            </tr>
        </thead>
        <tbody>
            @foreach($subscriptions as $sub)
            <tr>
                <td>{{ $sub['subscription_number'] }}</td>
                <td>{{ $sub['invoices_count'] }}</td>
                <td>{{ $sub['end_date'] }}</td>
                <td>{{ number_format($sub['total_due'], 3) }} د.ك</td>
                <td>{{ $sub['client_phone'] }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</body>

</html>