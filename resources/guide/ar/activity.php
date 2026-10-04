<?php

[$admin, $withPermission] = require __DIR__.'/_roles.php';

return [
    'title' => 'سجلات النشاط ولوحة المراجعة',
    'intro' => 'يسجل النظام كل عملية جوهرية: من فعل ماذا ومتى ومن أي عنوان IP وما الذي تغيّر بالتحديد. هذا هو أثر المراجعة (Audit Trail) لحسم أي خلاف أو اكتشاف عبث. تجده في «الإدارة ← سجلات النشاط» و«لوحة تحكم النشاطات».',
    'actions' => [
        [
            'id' => 'list',
            'title' => 'استعراض سجلات النشاط',
            'description' => 'الجدول يعرض الحدث والسجل والمنفّذ ونوع الكيان ووقت العملية. التبويبان: «كل الأنشطة» و«مخاطر عالية» (العمليات الحساسة كالحذف النهائي وتغيير الصلاحيات).',
            'roles' => [$admin, $withPermission],
            'permissions' => ['ViewAny:Activity', 'View:Activity'],
            'steps' => [
                ['text' => 'من القائمة الجانبية ← «الإدارة» ← «سجلات النشاط».', 'shot' => 'list-1'],
                ['text' => 'اضغط تبويب «مخاطر عالية» لعرض العمليات الحساسة فقط.', 'shot' => 'tab-risk'],
            ],
        ],
        [
            'id' => 'filter',
            'title' => 'البحث والتصفية',
            'description' => 'ابحث بالوصف أو بالمنفّذ، أو صفِّ حسب نوع الحدث (إنشاء/تعديل/حذف) أو السجل أو المنفّذ أو التاريخ.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['ViewAny:Activity'],
            'steps' => [['text' => 'اضغط أيقونة «تصفية» (الرقم يبيّن عدد التصفيات الفعّالة)، اختر ثم طبّق.', 'shot' => 'filters']],
        ],
        [
            'id' => 'view',
            'title' => 'عرض تفاصيل عملية',
            'description' => 'صفحة العملية تعرض الوصف والمنفّذ والكيان، وجدولاً بالقيم «قبل» و«بعد» لكل حقل تغيّر، ومعلومات الجهاز.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['View:Activity'],
            'steps' => [['text' => 'اضغط «عرض» في صف العملية.', 'shot' => 'view-1']],
        ],
        [
            'id' => 'export',
            'title' => 'تصدير السجلات وقيود الاحتفاظ',
            'description' => '«تصدير سجلات النشاط» يُنتج ملفاً بالأعمدة التي تختارها. و«قيود الاحتفاظ» تحدد مدة بقاء السجلات قبل أن تُنظَّف تلقائياً.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['ViewAny:Activity'],
            'steps' => [
                ['text' => 'اضغط «تصدير سجلات النشاط»، فعّل الأعمدة المطلوبة وغيّر تسمياتها ثم «تصدير».', 'shot' => 'export'],
                ['text' => '«قيود الاحتفاظ» لضبط مدة الاحتفاظ بالسجلات.', 'shot' => 'retention'],
            ],
        ],
        [
            'id' => 'audit',
            'title' => 'لوحة تحكم النشاطات',
            'description' => 'رؤية بيانية لحركة النظام: بطاقات إحصائية، ورسم «النشاط عبر الزمن»، و«خريطة حرارية» تبيّن أوقات ذروة الاستخدام، وآخر الأنشطة.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['View:AuditDashboard', 'View:ActivityStatsWidget', 'View:ActivityChartWidget', 'View:ActivityHeatmapWidget', 'View:LatestActivityWidget'],
            'steps' => [['text' => 'من «الإدارة» اختر «لوحة تحكم النشاطات».', 'shot' => 'audit-1']],
        ],
    ],
];
