<!DOCTYPE html>
<html dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }}</title>
    <link href="https://fonts.googleapis.com/css2?family=Almarai:wght@300;400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            font-family: 'Almarai', sans-serif;
            margin: 0;
            padding: 20px;
            background: #f8fafc;
        }

        .header {
            text-align: center;
            margin: 20px auto 40px;
            padding: 30px 20px;
            background: #3A8BCD;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            color: white;
            max-width: 95%;
            position: relative;
            overflow: hidden;
        }

        .header::after {
            content: "";
            position: absolute;
            top: -30px;
            right: -30px;
            width: 100px;
            height: 100px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
        }

        .header img {
            width: 60px;
            height: auto;
            border-radius: 10px;
            margin-bottom: 15px;
        }

        .title {
            font-size: 2.2rem;
            font-weight: 800;
            margin: 0;
            letter-spacing: -1px;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .export-date {
            background: rgba(255, 255, 255, 0.15);
            padding: 8px 20px;
            border-radius: 20px;
            display: inline-block;
            margin-top: 15px;
            font-size: 0.9rem;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin: 30px auto;
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.05);
        }

        th,
        td {
            padding: 16px 12px;
            text-align: center;
            border-bottom: 1px solid #f0f4f8;
        }

        th {
            background: #2A5C82;
            color: white;
            font-weight: 700;
            font-size: 0.95rem;
            letter-spacing: 0.5px;
        }

        td {
            color: #444;
            font-size: 0.92rem;
            background: #ffffff;
        }

        tr:nth-child(even) td {
            background: #f8fafc;
        }

        .status-active {
            color: #28A745;
            font-weight: 700;
        }

        .status-expired {
            color: #DC3545;
            font-weight: 700;
        }

        .status-canceled {
            color: #6C757D;
            font-weight: 700;
        }

        .total-due-green {
            background: #d4edda;
            color: #155724;
            padding: 4px 8px;
            border-radius: 9999px;
        }

        .total-due-orange {
            background: #fff3cd;
            color: #856404;
            padding: 4px 8px;
            border-radius: 9999px;
        }

        .total-due-red {
            background: #f8d7da;
            color: #721c24;
            padding: 4px 8px;
            border-radius: 9999px;
        }

        @media print {
            body {
                padding: 0;
                background: white;
            }

            .header {
                box-shadow: none;
                border-radius: 0;
                margin-bottom: 20px;
            }

            table {
                box-shadow: none;
            }
        }
    </style>
</head>

<body>
    <div class="header">
        <img src="{{ public_path('logo.jpg') }}" alt="صورة مصغرة">
        <h1 class="title">مصبغة عطر الورد</h1>
        <div class="export-date">
            {{ now()->format('Y-m-d H:i') }}
            <i class="fas fa-calendar-alt"></i>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>الحالة</th>
                <th>المجموع المستحق</th>
                <th>مجموع الفواتير</th>
                <th>تاريخ الانتهاء</th>
                <th>هاتف العميل</th>
                <th>عدد الفواتير</th>
                <th>رقم الاشتراك</th>
            </tr>
        </thead>
        <tbody>
            @foreach($subscriptions as $sub)
            <tr>
                <td class="status-{{ strtolower($sub['status'] ?? 'unknown') }}">
                    @if($sub['status'] == 'active')
                    <span class="status-active">نشط</span>
                    @elseif($sub['status'] == 'expired')
                    <span class="status-expired">منتهي</span>
                    @else
                    <span class="status-canceled">موقوف</span>
                    @endif
                </td>
                <td>
                    <span
                        class="@if($sub['total_due'] <= 30) total-due-green @elseif($sub['total_due'] >= 35 && $sub['total_due'] <= 40) total-due-orange @else total-due-red @endif">
                        {{ number_format($sub['total_due'] ?? 0, 3) }} د.ك
                    </span>
                </td>
                <td>{{ $sub['client_phone'] ?? '--' }}</td>
                <td>{{ number_format($sub['total_inv'] ?? 0, 3) }} د.ك</td>
                <td>
                    @php
                    $date = new DateTime($sub['end_date']);
                    echo $date->format('d-m-Y');
                    @endphp
                </td>
                <td>{{ $sub['invoices_count'] ?? 0 }}</td>
                <td>{{ $sub['subscription_number'] ?? '--' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</body>

</html>