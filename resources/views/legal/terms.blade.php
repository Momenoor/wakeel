@extends('legal.layout', ['title' => 'شروط الاستخدام · Terms of Use'])

@php
    $company = \App\Support\CompanyContact::name();
    $system = (string) \App\Models\Setting::get('app_name', config('app.name'));
    $updated = '2026-10-05';
@endphp

@section('content')
    <article lang="ar" dir="rtl">
        <h1>شروط الاستخدام</h1>
        <p class="meta">آخر تحديث: {{ $updated }}</p>

        <p>تحكم هذه الشروط استخدام نظام <strong>{{ $system }}</strong> الذي يشغله <strong>{{ $company }}</strong>، والتواصل الذي يتم من خلاله عبر البريد الإلكتروني وواتساب.</p>

        <h2>النظام ومن يستخدمه</h2>
        <p>النظام أداة داخلية لإدارة أعمال الخبرة والقضايا، ولا يحق استخدامه إلا للموظفين والأشخاص المخولين من المكتب، كلٌّ بحسابه وفي حدود صلاحياته.</p>

        <h2>الحسابات</h2>
        <ul>
            <li>المستخدم مسؤول عن سرية بيانات دخوله وعن كل ما يتم من خلال حسابه.</li>
            <li>يجب إبلاغ إدارة المكتب فورًا بأي استخدام غير مصرح به.</li>
            <li>يحق للمكتب إيقاف أي حساب أو تعديل صلاحياته في أي وقت.</li>
        </ul>

        <h2>السرية والاستخدام المقبول</h2>
        <ul>
            <li>بيانات القضايا ومستنداتها سرية، ولا تُستخدم إلا لأغراض العمل، ولا يجوز نسخها أو نشرها خارج ما تقتضيه المهمة.</li>
            <li>يُمنع أي استخدام يخالف القانون أو يضر بالنظام أو بالآخرين، أو أي محاولة للوصول إلى ما لا تشمله الصلاحيات.</li>
        </ul>

        <h2>المراسلات مع أطراف القضايا</h2>
        <p>نرسل المحاضر والخطابات والإشعارات عبر البريد الإلكتروني وواتساب للأعمال لأغراض القضية فقط. ما يرسله الأطراف من ردود ومستندات موقعة يُحفظ في ملف القضية ويُعامل وفق <a href="{{ route('legal.privacy') }}">سياسة الخصوصية</a>.</p>

        <h2>إخلاء المسؤولية</h2>
        <p>يُقدَّم النظام كما هو لدعم العمل الداخلي، ونسعى لإتاحته واستقراره دون ضمان عدم انقطاعه. لا يُغني النظام عن المراجعة المهنية للمستندات قبل اعتمادها.</p>

        <h2>تعديل الشروط</h2>
        <p>قد نعدّل هذه الشروط، ويسري التعديل من تاريخ نشره على هذه الصفحة.</p>

        <h2>القانون المطبق</h2>
        <p>تخضع هذه الشروط لقوانين دولة الإمارات العربية المتحدة.</p>

        <h2>التواصل معنا</h2>
        @include('legal.contact', ['arabic' => true])
    </article>

    <article lang="en" dir="ltr">
        <h1>Terms of Use</h1>
        <p class="meta">Last updated: {{ $updated }}</p>

        <p>These terms govern the use of <strong>{{ $system }}</strong>, operated by <strong>{{ $company }}</strong>, and the email and WhatsApp communication sent through it.</p>

        <h2>The system and who may use it</h2>
        <p>The system is an internal tool for managing expert work and matters. Only staff and people authorised by the office may use it, each with their own account and within their permissions.</p>

        <h2>Accounts</h2>
        <ul>
            <li>Users are responsible for keeping their sign-in details confidential and for everything done through their account.</li>
            <li>Any unauthorised use must be reported to the office immediately.</li>
            <li>The office may suspend any account or change its permissions at any time.</li>
        </ul>

        <h2>Confidentiality and acceptable use</h2>
        <ul>
            <li>Matter data and documents are confidential, used only for work, and may not be copied or disclosed beyond what the assignment requires.</li>
            <li>Any unlawful use, use that harms the system or others, or attempt to access what one's permissions do not cover, is prohibited.</li>
        </ul>

        <h2>Communication with parties</h2>
        <p>We send minutes, letters and notifications by email and WhatsApp Business only for the purposes of a matter. Replies and signed documents that parties send are filed with the matter and handled under our <a href="{{ route('legal.privacy') }}">Privacy Policy</a>.</p>

        <h2>Disclaimer</h2>
        <p>The system is provided as is, to support internal work. We aim to keep it available and reliable but do not guarantee uninterrupted service. It does not replace professional review of documents before they are issued.</p>

        <h2>Changes</h2>
        <p>We may change these terms; changes take effect when published on this page.</p>

        <h2>Governing law</h2>
        <p>These terms are governed by the laws of the United Arab Emirates.</p>

        <h2>Contact</h2>
        @include('legal.contact', ['arabic' => false])
    </article>
@endsection
