@extends('legal.layout', ['title' => 'سياسة الخصوصية · Privacy Policy'])

@php
    $company = (string) (\App\Models\Setting::get('company_name') ?: \App\Models\Setting::get('app_name', config('app.name')));
    $system = (string) \App\Models\Setting::get('app_name', config('app.name'));
    $email = 'info@jpaemirates.com';
    $updated = '2026-10-05';
@endphp

@section('content')
    <article lang="ar" dir="rtl">
        <h1>سياسة الخصوصية</h1>
        <p class="meta">آخر تحديث: {{ $updated }}</p>

        <p>توضح هذه السياسة كيف يجمع <strong>{{ $company }}</strong> ("نحن") البيانات الشخصية ويستخدمها ويحميها عند تشغيل نظام <strong>{{ $system }}</strong>، وهو نظام داخلي لإدارة أعمال الخبرة والقضايا يستخدمه موظفو المكتب، وعند التواصل مع أطراف القضايا عبر البريد الإلكتروني وواتساب.</p>

        <h2>البيانات التي نتعامل معها</h2>
        <ul>
            <li><strong>حسابات الموظفين:</strong> الاسم، البريد الإلكتروني، رقم الهاتف، وسجل استخدام النظام.</li>
            <li><strong>أطراف القضايا وممثلوهم:</strong> الاسم، رقم الهوية، أرقام الهواتف، البريد الإلكتروني والعنوان، بالقدر اللازم لأداء مهام الخبرة المسندة إلينا.</li>
            <li><strong>مستندات القضايا:</strong> المحاضر، والخطابات، والتقارير، والملفات المرفقة، والنسخ الموقعة التي ترسلونها إلينا.</li>
            <li><strong>رسائل واتساب:</strong> رقم الهاتف، ومحتوى الرسائل المرسلة إليكم والردود الواردة منكم، والملفات المرفقة بها (مثل المحاضر الموقعة).</li>
            <li><strong>بيانات تقنية:</strong> عنوان IP، ونوع المتصفح، وسجلات النظام اللازمة للأمان واستقرار الخدمة.</li>
        </ul>

        <h2>أغراض الاستخدام</h2>
        <ul>
            <li>إدارة القضايا ومهام الخبرة وجلساتها ومحاضرها.</li>
            <li>إرسال المحاضر والخطابات والإشعارات المتعلقة بالقضية، واستلام النسخ الموقعة وحفظها في ملف القضية.</li>
            <li>الوفاء بالالتزامات القانونية والمهنية، وحماية النظام وأمنه.</li>
        </ul>
        <p>لا نستخدم بياناتكم لأغراض تسويقية، ولا نبيعها لأي جهة.</p>

        <h2>التواصل عبر واتساب</h2>
        <p>نراسلكم عبر واتساب للأعمال فقط فيما يخص قضية أنتم طرف فيها أو ممثل لطرف فيها. تُحفظ ردودكم ومرفقاتها في ملف القضية. يمكنكم طلب إيقاف المراسلة عبر واتساب في أي وقت بالرد على الرسالة أو بمراسلتنا على العنوان أدناه.</p>

        <h2>مشاركة البيانات</h2>
        <p>نشارك البيانات بالقدر اللازم فقط مع:</p>
        <ul>
            <li>مقدمي الخدمات الذين نعتمد عليهم في تشغيل النظام: Meta (منصة واتساب للأعمال)، وMicrosoft (OneDrive وOutlook)، ومزود البريد الإلكتروني، وخدمة الإشعارات الفورية، والاستضافة.</li>
            <li>المحاكم والجهات المختصة وأطراف القضية، وفق ما تقتضيه مهمة الخبرة أو القانون.</li>
        </ul>

        <h2>مدة الاحتفاظ</h2>
        <p>نحتفظ بالبيانات طوال مدة القضية وما يقتضيه القانون والالتزامات المهنية بعد ذلك، ثم نحذفها أو نجعلها مجهولة الهوية.</p>

        <h2>الحماية</h2>
        <p>يُتاح النظام للموظفين المخولين فقط، بحسابات وصلاحيات محددة، عبر اتصال مشفر (HTTPS)، مع سجل للعمليات.</p>

        <h2>حقوقكم</h2>
        <p>يحق لكم طلب الاطلاع على بياناتكم أو تصحيحها أو حذفها أو تقييد استخدامها، ما لم يوجب القانون أو مهمة الخبرة الاحتفاظ بها.</p>

        <h2 id="data-deletion">طلب حذف البيانات</h2>
        <p>لحذف بياناتكم، بما فيها رسائل واتساب المحفوظة لدينا، أرسلوا طلبًا إلى <a href="mailto:{{ $email }}">{{ $email }}</a> متضمنًا اسمكم ورقم الهاتف أو البريد الإلكتروني المستخدم في التواصل معنا. نؤكد استلام الطلب وننفذه خلال 30 يومًا، إلا ما يوجب القانون الاحتفاظ به، ونبلغكم بذلك.</p>

        <h2>التواصل معنا</h2>
        <p>{{ $company }} — <a href="mailto:{{ $email }}">{{ $email }}</a></p>
    </article>

    <article lang="en" dir="ltr">
        <h1>Privacy Policy</h1>
        <p class="meta">Last updated: {{ $updated }}</p>

        <p>This policy explains how <strong>{{ $company }}</strong> ("we") collects, uses and protects personal data when operating <strong>{{ $system }}</strong> — an internal case and expert-work management system used by our staff — and when contacting parties to our matters by email and WhatsApp.</p>

        <h2>Data we process</h2>
        <ul>
            <li><strong>Staff accounts:</strong> name, email, phone number and system activity.</li>
            <li><strong>Parties to matters and their representatives:</strong> name, ID number, phone numbers, email and address, as needed to carry out the expert work assigned to us.</li>
            <li><strong>Matter documents:</strong> meeting minutes, letters, reports, attachments, and signed copies you send us.</li>
            <li><strong>WhatsApp messages:</strong> phone number, the content of messages we send you and your replies, and their attachments (such as signed minutes).</li>
            <li><strong>Technical data:</strong> IP address, browser type and system logs needed for security and reliability.</li>
        </ul>

        <h2>How we use it</h2>
        <ul>
            <li>To manage matters, expert assignments, sessions and minutes.</li>
            <li>To send minutes, letters and notifications about a matter, and to receive signed copies and file them with the matter.</li>
            <li>To meet our legal and professional obligations and keep the system secure.</li>
        </ul>
        <p>We do not use your data for marketing and we never sell it.</p>

        <h2>WhatsApp messaging</h2>
        <p>We message you through WhatsApp Business only about a matter you are a party to, or represent a party in. Your replies and attachments are stored in the matter file. You can ask us to stop WhatsApp messages at any time by replying to the message or emailing us at the address below.</p>

        <h2>Sharing</h2>
        <p>We share data only as far as needed with:</p>
        <ul>
            <li>Service providers that run the system for us: Meta (WhatsApp Business Platform), Microsoft (OneDrive and Outlook), our email provider, our real-time notification service and our hosting provider.</li>
            <li>Courts, competent authorities and the parties to a matter, as the expert assignment or the law requires.</li>
        </ul>

        <h2>Retention</h2>
        <p>We keep data for the life of the matter and for as long afterwards as the law and our professional obligations require, then delete or anonymise it.</p>

        <h2>Security</h2>
        <p>The system is available only to authorised staff, with individual accounts and permissions, over an encrypted connection (HTTPS), with an audit log of operations.</p>

        <h2>Your rights</h2>
        <p>You may ask to access, correct, delete or restrict the use of your data, unless the law or the expert assignment requires us to keep it.</p>

        <h2 id="data-deletion-en">Data deletion request</h2>
        <p>To have your data deleted — including WhatsApp messages we hold — email <a href="mailto:{{ $email }}">{{ $email }}</a> with your name and the phone number or email address used to contact us. We confirm receipt and complete the request within 30 days, except for data the law requires us to keep, and let you know.</p>

        <h2>Contact</h2>
        <p>{{ $company }} — <a href="mailto:{{ $email }}">{{ $email }}</a></p>
    </article>
@endsection
