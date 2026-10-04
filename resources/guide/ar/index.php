<?php

/**
 * Modules of the User Guide, grouped and in the order shown. A module appears
 * only once its file (resources/guide/ar/{id}.php) exists.
 * "panel": mms | pms | shared (shared shows in both panels).
 */
$m = fn (string $group, string $id, string $panel, string $icon, string $title, string $summary): array => compact('group', 'id', 'panel', 'icon', 'title', 'summary');

return [
    'modules' => [
        $m('البدء', 'start', 'shared', 'heroicon-o-rocket-launch', 'البدء السريع والبيانات التجريبية', 'تسجيل الدخول واللغة وملء النظام ببيانات تجريبية'),

        $m('نظام القضايا', 'dashboard', 'mms', 'heroicon-o-home', 'لوحة التحكم', 'البطاقات والجلسات والتقويم والرسوم'),
        $m('نظام القضايا', 'matters', 'mms', 'heroicon-o-scale', 'القضايا', 'الإنشاء والتقارير والأتعاب والخطابات والمحاضر'),
        $m('نظام القضايا', 'calendar', 'mms', 'heroicon-o-calendar-days', 'مواعيد الأجندة', 'الجلسات والمعاينات ومزامنة Outlook'),
        $m('نظام القضايا', 'parties', 'mms', 'heroicon-o-user-group', 'أطراف التداعي', 'الأطراف والخبراء والممثلون والموظفون'),
        $m('نظام القضايا', 'courts', 'mms', 'heroicon-o-building-library', 'المحاكم', 'قائمة المحاكم وبياناتها'),
        $m('نظام القضايا', 'types', 'mms', 'heroicon-o-tag', 'أنواع القضايا', 'الحافز ومسميات الأطراف وحقول مخصصة'),
        $m('نظام القضايا', 'expertise', 'mms', 'heroicon-o-academic-cap', 'مجالات الخبرة', 'قائمة تخصصات الخبراء'),
        $m('نظام القضايا', 'reports', 'mms', 'heroicon-o-chart-bar', 'التقارير', '14 تقريراً: القضايا والأتعاب والمساعدون والضريبة'),

        $m('المراسلات والقوالب', 'chat', 'mms', 'heroicon-o-chat-bubble-left-right', 'الدردشة', 'محادثات فورية فردية وجماعية'),
        $m('المراسلات والقوالب', 'bulkmail', 'mms', 'heroicon-o-envelope', 'البريد الجماعي', 'حملات البريد وإرسال دفعات مخصصة'),
        $m('المراسلات والقوالب', 'emailtpl', 'mms', 'heroicon-o-envelope-open', 'قوالب البريد', 'صيغ الرسائل الإلكترونية الجاهزة'),
        $m('المراسلات والقوالب', 'lettertpl', 'mms', 'heroicon-o-document-text', 'قوالب الخطابات ومكتبة البنود', 'صيغ الخطابات وحقولها وبنودها'),
        $m('المراسلات والقوالب', 'letterheads', 'mms', 'heroicon-o-document', 'الترويسات وكتل التوقيع والخطوط', 'أوراق المكتب الرسمية'),
        $m('المراسلات والقوالب', 'whatsapp', 'mms', 'heroicon-o-chat-bubble-oval-left', 'قوالب واتساب', 'قوالب Meta وردود الاستقبال'),
        $m('المراسلات والقوالب', 'mailsenders', 'mms', 'heroicon-o-at-symbol', 'مرسلو البريد', 'صناديق الإرسال Microsoft 365 وSMTP'),

        $m('المالية', 'incentive', 'mms', 'heroicon-o-calculator', 'حسابات الحوافز', 'احتساب حوافز المساعدين وشرائحها وقواعدها'),
        $m('المالية', 'payroll', 'mms', 'heroicon-o-banknotes', 'دورات الرواتب', 'المسير الشهري والاعتمادات وقيد اليومية'),
        $m('المالية', 'loans', 'mms', 'heroicon-o-credit-card', 'القروض والعهد النقدية', 'السلف وجداول الأقساط'),
        $m('المالية', 'finconfig', 'mms', 'heroicon-o-cog-8-tooth', 'الإعدادات المالية', 'نسب الحوافز وأرقام الرواتب والقانون'),

        $m('الموارد البشرية', 'employees', 'mms', 'heroicon-o-identification', 'الموظفون', 'الملفات والوثائق وهيكل الراتب'),
        $m('الموارد البشرية', 'leave', 'mms', 'heroicon-o-calendar', 'الإجازات', 'الطلبات والاعتماد وسجل الغياب'),
        $m('الموارد البشرية', 'tickets', 'mms', 'heroicon-o-paper-airplane', 'تذاكر السفر ونهاية الخدمة', 'التذاكر وسند إقفال المكافأة'),

        $m('نظام العقارات', 'pmsdashboard', 'pms', 'heroicon-o-home', 'لوحة تحكم العقارات', 'ملخص التحصيل والأقساط والإيرادات'),
        $m('نظام العقارات', 'pmsprops', 'pms', 'heroicon-o-building-office', 'العقارات والوحدات', 'العقارات وسندات الملكية ووحداتها'),
        $m('نظام العقارات', 'pmsowners', 'pms', 'heroicon-o-user-group', 'الملاك ومجموعات الملاك', 'الملاك وحساباتهم البنكية'),
        $m('نظام العقارات', 'pmstenants', 'pms', 'heroicon-o-users', 'المستأجرون', 'سجل المستأجرين'),
        $m('نظام العقارات', 'quotations', 'pms', 'heroicon-o-document-currency-dollar', 'عروض الأسعار', 'عروض الإيجار وتحويلها لعقود'),
        $m('نظام العقارات', 'leases', 'pms', 'heroicon-o-document-check', 'عقود الإيجار', 'الإنشاء والتصديق والدفعات والتجديد'),
        $m('نظام العقارات', 'pmsconditions', 'pms', 'heroicon-o-clipboard-document-check', 'قوالب الشروط والطباعة', 'الشروط الخاصة ونماذج الطباعة'),
        $m('نظام العقارات', 'pmssettings', 'pms', 'heroicon-o-adjustments-horizontal', 'إعدادات إدارة الأملاك', 'الضريبة والتوثيق والغرامة'),
        $m('نظام العقارات', 'pmsreports', 'pms', 'heroicon-o-chart-pie', 'تقارير العقارات', '9 تقارير للإيجار والتحصيل والملاك'),

        $m('الإدارة', 'users', 'shared', 'heroicon-o-users', 'المستخدمون', 'الحسابات وكلمات المرور والتقمص'),
        $m('الإدارة', 'roles', 'shared', 'heroicon-o-shield-check', 'الأدوار والصلاحيات', 'من يرى ماذا ويفعل ماذا'),
        $m('الإدارة', 'activity', 'shared', 'heroicon-o-clipboard-document-list', 'سجلات النشاط ولوحة المراجعة', 'أثر المراجعة لكل عملية'),
        $m('الإدارة', 'sysettings', 'shared', 'heroicon-o-cog-6-tooth', 'إعدادات النظام', 'الهوية والبريد والإشعارات والصيانة'),
        $m('الإدارة', 'maintenance', 'mms', 'heroicon-o-wrench-screwdriver', 'صفحات الصيانة', 'الصلاحيات والأتعاب وصعوبة القضايا'),
        $m('الإدارة', 'updates', 'mms', 'heroicon-o-arrow-path', 'التحديثات ومجلدات OneDrive', 'تحديث النظام وربط OneDrive'),
    ],
];
