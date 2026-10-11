<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Shield permission labels (Arabic)
|--------------------------------------------------------------------------
|
| Laravel merges this file over the package's own ar/filament-shield.php, so
| only what is overridden or added needs to appear here.
|
| `shield:translation ar` writes to lang/ar/filament-shield.resource_permission_prefixes_labels.php,
| which Laravel never loads: it resolves a group by the first dot, so that
| filename is read as group "filament-shield", item
| "resource_permission_prefixes_labels...". Shield looks the labels up under its
| own namespace (filament-shield::filament-shield.…), which is this file.
|
| Keys are the snake_case form of the permission affix — Shield derives them
| with Utils::toLocalizationKey(), so `ViewAny` becomes `view_any` and the page
| permission `View:MyMattersReport` becomes `view_my_matters_report`.
|
*/

return [
    'resource_permission_prefixes_labels' => [

        // Standard resource affixes.
        'view' => 'عرض',
        'view_any' => 'عرض الكل',
        'create' => 'إضافة',
        'update' => 'تعديل',
        'delete' => 'حذف',
        'delete_any' => 'حذف الكل',
        'force_delete' => 'حذف نهائي',
        'force_delete_any' => 'حذف نهائي للكل',
        'restore' => 'استرجاع',
        'restore_any' => 'استرجاع الكل',
        'replicate' => 'استنساخ',
        'reorder' => 'إعادة ترتيب',

        // Resource actions & approvals.
        'send' => 'إرسال',
        'approve' => 'اعتماد',
        'print' => 'طباعة',
        'generate' => 'توليد',
        'hr_approve' => 'اعتماد الموارد البشرية',
        'finance_approve' => 'اعتماد المالية',
        'disburse' => 'صرف',
        'view_journal_voucher' => 'عرض قيد اليومية',
        'run_calculation' => 'تشغيل الاحتساب',
        'finalize' => 'اعتماد نهائي',

        // Panel access.
        'access_multiple_systems' => 'الوصول إلى عدة أنظمة',
        'impersonate_user' => 'تقمص مستخدم',

        // Payroll — EOSG closing voucher (no Filament Resource of its own).
        'view_eosg_closing_voucher' => 'عرض سند إقفال مكافأة نهاية الخدمة',
        'view_bulk_mail_campaign_recipients_relation' => 'عرض مستلمي الحملة البريدية',
        'view_court_matters_relation' => 'عرض قضايا المحكمة',
        'view_employee_loan_installments_relation' => 'عرض أقساط سلفة الموظف',
        'view_employee_salary_components_relation' => 'عرض هيكل راتب الموظف',
        'view_employee_leave_balance_relation' => 'عرض رصيد إجازات الموظف',
        'view_employee_flight_tickets_relation' => 'عرض تذاكر سفر الموظف',
        'view_matter_letters_relation' => 'عرض خطابات القضية',
        'view_matter_minutes_relation' => 'عرض محاضر القضية',
        'view_party_matters_relation' => 'عرض قضايا الطرف',
        'view_payroll_run_payslips_relation' => 'عرض قسائم رواتب المسير',
        'view_type_field_definitions_relation' => 'عرض حقول نوع القضية',
        'view_condition_template_items_relation' => 'عرض بنود قالب الشروط',
        'view_lease_print_template_pages_relation' => 'عرض صفحات قالب طباعة العقد',
        'view_lease_installments_relation' => 'عرض أقساط عقد الإيجار',
        'view_owner_group_properties_relation' => 'عرض عقارات مجموعة الملاك',
        'view_property_units_relation' => 'عرض وحدات العقار',
        'view_matter_overview_tab' => 'تبويب القضية: نظرة عامة',
        'view_matter_sessions_tab' => 'تبويب القضية: الجلسات والمواعيد',
        'view_matter_fees_tab' => 'تبويب القضية: الأتعاب والحوافز',
        'view_matter_requests_tab' => 'تبويب القضية: الطلبات والملاحظات',
        'view_matter_files_tab' => 'تبويب القضية: الملفات',
        'view_matter_letters_tab' => 'تبويب القضية: الخطابات',
        'view_matter_minutes_tab' => 'تبويب القضية: المحاضر',
        'view_matter_progress_tab' => 'تبويب القضية: سير العمل',
        'view_matters_all_tab' => 'تبويب القضايا: الكل',
        'view_matters_in_progress_tab' => 'تبويب القضايا: قيد العمل',
        'view_matters_initial_prepared_tab' => 'تبويب القضايا: التقرير المبدئي',
        'view_matters_final_submitted_tab' => 'تبويب القضايا: التقرير النهائي',
        'view_matters_deleted_tab' => 'تبويب القضايا: المحذوفة',
        'view_parties_all_tab' => 'تبويب الأطراف: الكل',
        'view_parties_parties_tab' => 'تبويب الأطراف: الأطراف',
        'view_parties_representatives_tab' => 'تبويب الأطراف: الممثلون',
        'view_parties_experts_tab' => 'تبويب الأطراف: الخبراء',
        'view_parties_employees_tab' => 'تبويب الأطراف: الموظفون',
        'view_financial_incentive_tab' => 'تبويب الإعدادات المالية: الحوافز',
        'view_financial_incentive_rates_tab' => 'تبويب الإعدادات المالية: النسب والخصومات',
        'view_financial_type_configurations_tab' => 'تبويب الإعدادات المالية: إعدادات الأنواع',
        'view_financial_extra_rules_tab' => 'تبويب الإعدادات المالية: قواعد النسب الإضافية',
        'view_financial_meta_adjustments_tab' => 'تبويب الإعدادات المالية: التعديلات الإضافية',
        'view_financial_payroll_tab' => 'تبويب الإعدادات المالية: الرواتب',
        'view_system_settings_maintenance_tab' => 'تبويب إعدادات النظام: الصيانة ووضع عدم الاتصال',
        'view_system_settings_general_tab' => 'تبويب إعدادات النظام: الإعدادات العامة',
        'view_system_settings_email_tab' => 'تبويب إعدادات النظام: البريد الإلكتروني',
        'view_system_settings_notifications_tab' => 'تبويب إعدادات النظام: الإشعارات والإعلانات',
        'view_system_settings_letters_tab' => 'تبويب إعدادات النظام: الخطابات والمراسلات',
        'generate_eosg_closing_voucher' => 'توليد سند إقفال مكافأة نهاية الخدمة',

        // Matter — scope.
        'view_own' => 'عرض ملفاته فقط',
        'view_trashed' => 'عرض المحذوفات',
        'export' => 'تصدير',
        'import' => 'استيراد',

        // Matter — reports.
        'initial_report' => 'التقرير الأولي',
        'final_report' => 'التقرير النهائي',
        'update_initial_report_date' => 'تعديل تاريخ التقرير الأولي',
        'update_final_report_date' => 'تعديل تاريخ التقرير النهائي',
        'bulk_update_final_report_date' => 'تعديل جماعي لتاريخ التقرير النهائي',

        // Matter — notes.
        'create_note' => 'إضافة ملاحظة',
        'update_note' => 'تعديل ملاحظة',
        'delete_note' => 'حذف ملاحظة',

        // Matter — requests.
        'create_request' => 'إنشاء طلب',
        'approve_request' => 'اعتماد الطلب',
        'reject_request' => 'رفض الطلب',
        'create_matter_request' => 'إنشاء طلب قضية',
        'edit_request' => 'تعديل طلب',

        // Matter — fees and collections.
        'create_fee' => 'إضافة أتعاب',
        'update_fee' => 'تعديل الأتعاب',
        'delete_fee' => 'حذف الأتعاب',
        'collect_fee' => 'تحصيل الأتعاب',
        'update_allocation' => 'تعديل دفعة',
        'delete_allocation' => 'حذف دفعة',

        // Matter — attachments.
        'create_attachment' => 'إضافة مرفق',
        'delete_attachment' => 'حذف مرفق',

        // Calendar events.
        'create_single' => 'إنشاء موعد مفرد',
        'create_bulk' => 'إنشاء مواعيد مجمّعة',
        'import_from_outlook' => 'استيراد من Outlook',
        'sync_to_outlook' => 'المزامنة مع Outlook',

        // Custom permissions.
        'create_matter_request_matter_request' => 'إنشاء طلب قضية',
        'edit_request_matter_request' => 'تعديل طلب قضية',
        'delete_any_bulk_mail_campaign' => 'حذف حملات البريد الجماعي',
        'send_bulk_mail_campaign' => 'إرسال حملة البريد الجماعي',
        'delete_any_bulk_mail_campaign_bulk_mail_campaign' => 'حذف حملات البريد الجماعي',
        'send_bulk_mail_campaign_bulk_mail_campaign' => 'إرسال حملة البريد الجماعي',
        'delete_any_incentive_meta_adjustment' => 'حذف تعديلات الحافز',
        'delete_any_letter_template' => 'حذف قوالب الخطابات',

        // Pages.
        'view_admin_dashboard' => 'لوحة التحكم',
        'view_access_control_maintenance' => 'صيانة التحكم بالوصول',
        'view_chat' => 'الدردشة',
        'view_financial_configuration' => 'الإعدادات المالية',
        'view_one_drive_settings' => 'مجلدات OneDrive',
        'view_end_of_service_gratuity_closing_voucher' => 'سند إقفال مكافأة نهاية الخدمة',
        'view_my_incentive_report' => 'تقرير حافزي',
        'view_assistant_matter_fees_report' => 'تقرير أتعاب المساعدين',
        'view_assistant_matters_count' => 'عدد ملفات المساعدين',
        'view_assistant_matters_report' => 'تقرير ملفات المساعدين',
        'view_assistant_performance_report' => 'أداء المساعدين',
        'view_court_workload_report' => 'حجم العمل حسب المحكمة',
        'view_deductions_reconciliation_report' => 'مطابقة الخصومات',
        'view_fee_collection_aging_report' => 'تحصيل الأتعاب والتقادم',
        'view_fee_data_maintenance' => 'صيانة بيانات الأتعاب',
        'view_flight_tickets' => 'تذاكر السفر',
        'view_fix_matters_difficulty' => 'تصحيح صعوبة القضايا',
        'view_incentive_configuration' => 'تهيئة الحافز',
        'view_matter_quality_report' => 'الجودة وإعادة العمل',
        'view_matters_monthly_report' => 'تقرير القضايا الشهري',
        'view_my_incentive' => 'حافزي',
        'view_my_matters_report' => 'قضاياي',
        'view_overdue_matters_report' => 'القضايا المتأخرة',
        'view_permission_maintenance' => 'صيانة الصلاحيات',
        'view_system_settings' => 'إعدادات النظام',
        'view_type_profitability_report' => 'الربحية حسب نوع القضية',
        'view_vat_summary_report' => 'ملخص ضريبة القيمة المضافة',
        'view_audit_dashboard' => 'لوحة تدقيق العمليات',
        'view_activity_logs' => 'سجل الأنشطة',
        'view_failed_import_rows' => 'صفوف الاستيراد الفاشلة',
        'view_import_matters' => 'استيراد القضايا',
        'view_system_updates' => 'تحديثات النظام',
        'view_performance' => 'الأداء',
        'view_user_guide' => 'دليل الاستخدام',
        // PMS reports.
        'view_rent_roll_report' => 'سجل الإيجارات',
        'view_occupancy_report' => 'الإشغال والشواغر',
        'view_collections_report' => 'التحصيلات',
        'view_arrears_aging_report' => 'أعمار المتأخرات',
        'view_lease_expiry_report' => 'انتهاء العقود',
        'view_payments_received_report' => 'المدفوعات المستلمة',
        'view_owner_statement_report' => 'كشوف الملاك',
        'view_vat_report' => 'تقرير ضريبة القيمة المضافة (العقارات)',
        'view_security_deposits_report' => 'التأمينات',

        // Widgets.
        'view_activity_chart_widget' => 'النشاط عبر الوقت',
        'view_activity_heatmap_widget' => 'الخريطة الحرارية للنشاط',
        'view_activity_over_time_widget' => 'النشاط عبر الوقت',
        'view_activity_stats_widget' => 'إحصائيات النشاط',
        'view_assistant_matter_count_table_widget' => 'جدول عدد ملفات المساعدين',
        'view_assistant_matters_count_chart_widget' => 'رسم عدد ملفات المساعدين',
        'view_assistant_matter_count_chart_widget' => 'رسم عدد ملفات المساعدين',
        'view_attention_needed_widget' => 'يتطلب الانتباه',
        'view_calendar_widget' => 'التقويم',
        'view_collections_aging_widget' => 'تقادم التحصيل',
        'view_incentive_extra_rules_overview_widget' => 'نظرة عامة على قواعد النسبة الإضافية',
        'view_incentive_meta_adjustments_overview_widget' => 'نظرة عامة على تعديلات الحافز',
        'view_incentive_summary_table_widget' => 'جدول ملخص الحافز',
        'view_incentive_type_configs_overview_widget' => 'نظرة عامة على إعدادات الحافز حسب النوع',
        'view_latest_activities_widget' => 'آخر الأنشطة',
        'view_latest_activity_widget' => 'آخر الأنشطة',
        'view_matter_stats_widget' => 'إحصائيات القضايا',
        'view_matters_per_year_widget' => 'القضايا المستلمة سنويًا',
        'view_upcoming_sessions_widget' => 'الجلسات القادمة',
        'view_unmatched_event_references_widget' => 'أحداث بأرقام قضايا غير موجودة',
        'view_vacation_calendar_widget' => 'تقويم الإجازات',
        'view_p_m_s_overview_widget' => 'نظرة عامة على نظام إدارة الممتلكات',
        'view_pms_revenue_chart_widget' => 'رسم إيرادات نظام إدارة الممتلكات',
    ],

    // The sidebar group holding Settings, Users, Roles and the activity log.
    'nav.group' => 'الإدارة',
];
