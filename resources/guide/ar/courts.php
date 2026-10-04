<?php

[$admin, $withPermission] = require __DIR__.'/_roles.php';

return [
    'title' => 'المحاكم',
    'intro' => 'قائمة المحاكم التي ترد منها القضايا، بهاتفها وبريدها وعنوانها. تُختار المحكمة في كل قضية وتظهر في الخطابات والتقارير. تجدها في «الإعدادات ← المحاكم».',
    'actions' => [
        [
            'id' => 'list',
            'title' => 'استعراض المحاكم',
            'description' => 'جدول بكل المحاكم مع الاسم والهاتف والبريد والعنوان.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['ViewAny:Court'],
            'steps' => [
                ['text' => 'من القائمة الجانبية ← الإعدادات ← «المحاكم».', 'shot' => 'list-1'],
            ],
        ],
        [
            'id' => 'create',
            'title' => 'إضافة محكمة',
            'description' => 'تضاف المحكمة مرة واحدة ثم تُختار في القضايا.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Create:Court'],
            'steps' => [
                ['text' => 'اضغط «إضافة المحكمة».', 'shot' => 'create-0'],
                ['text' => 'اكتب «الاسم» (إلزامي) و«الهاتف» و«البريد الإلكتروني» و«العنوان».', 'shot' => 'create-2-filled'],
                ['text' => 'اضغط «إضافة». تظهر رسالة «تمت الإضافة» وتفتح صفحة المحكمة وفيها قضاياها.', 'shot' => 'create-3-done'],
            ],
        ],
        [
            'id' => 'edit',
            'title' => 'تعديل محكمة أو حذفها',
            'description' => 'لتصحيح بيانات الاتصال أو حذف محكمة غير مستعملة.',
            'roles' => [$admin, $withPermission],
            'permissions' => ['Update:Court', 'Delete:Court'],
            'steps' => [
                ['text' => 'اضغط «تعديل» في صف المحكمة، غيّر ما تريد ثم «حفظ التغييرات». الحذف من زر «حذف» في أعلى الصفحة.', 'shot' => 'edit-1'],
            ],
            'tips' => ['لا تحذف محكمة مرتبطة بقضايا؛ عدّل بياناتها بدلاً من ذلك.'],
        ],
        [
            'id' => 'view',
            'title' => 'عرض محكمة وقضاياها',
            'description' => 'صفحة المحكمة تعرض بياناتها وجدولاً بقضاياها (عبء العمل).',
            'roles' => [$admin, $withPermission],
            'permissions' => ['View:Court', 'View:CourtMattersRelation'],
            'steps' => [
                ['text' => 'اضغط اسم المحكمة أو أيقونة العين.', 'shot' => 'view-1'],
            ],
        ],
    ],
];
