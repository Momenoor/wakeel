<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Wakeel</title>
    <style>
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Tahoma, Roboto, Arial, sans-serif;
            background-color: #f3f4f6;
            color: #1f2937;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
        }

        .card {
            background: #ffffff;
            border-radius: 1rem;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
            padding: 2.5rem;
            max-width: 32rem;
            width: 90%;
            text-align: center;
        }

        .badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            background-color: #fef3c7;
            color: #92400e;
            font-size: 0.875rem;
            font-weight: 600;
            border-radius: 9999px;
            margin-bottom: 1.25rem;
        }

        p {
            line-height: 1.6;
            color: #4b5563;
            margin: 0 0 0.75rem;
        }

        .en {
            direction: ltr;
            font-size: 0.9rem;
            color: #6b7280;
        }
    </style>
</head>
<body>
<div class="card">
    <div class="badge">صيانة مجدولة &middot; Scheduled maintenance</div>
    {{-- Written into storage/framework/maintenance.php and served before the framework loads, so it
         is fixed text in both languages rather than anything translated or looked up per request. --}}
    <p>يجري الآن تحديث النظام. يرجى المحاولة بعد دقائق قليلة.</p>
    <p class="en">The system is being updated. Please check back in a few minutes.</p>
</div>
</body>
</html>
