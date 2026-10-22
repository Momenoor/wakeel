<?php

use App\Support\ScreenPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Employee requests (Human Resources → Employee requests): a loan or petty cash, or a certificate. Whoever
 * requests leave may make them, whoever approves leave decides them, whoever sees payroll confirms the money
 * paid. Two certificate templates to start from: a salary certificate and a no-objection certificate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('party_id')->constrained('parties')->cascadeOnDelete()->comment('The employee');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 20)->comment('loan | petty_cash | certificate');
            $table->string('status', 16)->default('pending');
            // Money
            $table->decimal('amount', 12, 2)->nullable();
            $table->unsignedSmallInteger('months')->nullable();
            $table->date('starts_on')->nullable()->comment('The salary month the first instalment is deducted from');
            // Certificate
            $table->foreignId('letter_template_id')->nullable()->constrained('letter_templates')->nullOnDelete();
            $table->string('addressed_to')->nullable();
            $table->string('purpose')->nullable();
            $table->text('reason')->nullable();
            // Decision
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('approved_comment')->nullable()->comment('The approval note, or the reason it was rejected');
            // Paid (money): the advance it became
            $table->date('paid_on')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('payment_note')->nullable();
            $table->foreignId('employee_loan_id')->nullable()->constrained('employee_loans')->nullOnDelete();
            // Issued (certificate)
            $table->string('reference')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['party_id', 'status']);
            $table->index(['status', 'type']);
        });

        foreach (['ViewAny', 'View', 'Create', 'Update', 'Delete'] as $ability) {
            ScreenPermissions::grant($ability.':EmployeeRequest', ['ViewAny:LeaveRequest']);
        }
        ScreenPermissions::grant('Approve:EmployeeRequest', ['Approve:LeaveRequest']);
        ScreenPermissions::grant('Pay:EmployeeRequest', ['ViewAny:PayrollRun', 'Approve:EmployeeLoan']);

        $now = now();
        $signature = '<p style="text-align: left;">{{signature}}</p><p style="text-align: left;">{{stamp}}</p>';

        DB::table('letter_templates')->insertOrIgnore([
            [
                'name' => 'شهادة راتب',
                'slug' => 'salary-certificate',
                'subject' => 'شهادة راتب',
                'locale' => 'ar',
                'category' => 'certificates',
                'is_active' => true,
                'is_default' => false,
                'placeholders' => '[]',
                'body' => '<p style="text-align: center;"><strong>شهادة راتب</strong></p>'
                    .'<p>التاريخ: {{date}}<br>المرجع: {{reference}}</p>'
                    .'<p>إلى: {{request.addressed_to}}</p>'
                    .'<p>تشهد {{company.name}} بأن السيد/ة <strong>{{employee.name}}</strong><<، حامل جواز السفر رقم {{employee.passport_no}}>>، يعمل لديها بوظيفة {{employee.designation}} منذ {{employee.joining_date}}، ويتقاضى راتباً شهرياً إجمالياً قدره {{employee.salary.total}} درهم ({{employee.salary.total.words}} درهم إماراتي)، منها الراتب الأساسي {{employee.salary.basic}} درهم.</p>'
                    .'<p>وقد أُعطيت هذه الشهادة بناءً على طلبه<< لتقديمها لغرض {{request.purpose}}>>، دون أدنى مسؤولية على الشركة.</p>'
                    .$signature,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'شهادة عدم ممانعة',
                'slug' => 'no-objection-certificate',
                'subject' => 'شهادة عدم ممانعة',
                'locale' => 'ar',
                'category' => 'certificates',
                'is_active' => true,
                'is_default' => false,
                'placeholders' => '[]',
                'body' => '<p style="text-align: center;"><strong>شهادة عدم ممانعة</strong></p>'
                    .'<p>التاريخ: {{date}}<br>المرجع: {{reference}}</p>'
                    .'<p>إلى: {{request.addressed_to}}</p>'
                    .'<p>تشهد {{company.name}} بأن السيد/ة <strong>{{employee.name}}</strong><<، حامل جواز السفر رقم {{employee.passport_no}}>>، يعمل لديها بوظيفة {{employee.designation}} منذ {{employee.joining_date}}، وأنه لا مانع لدى الشركة<< من {{request.purpose}}>>.</p>'
                    .'<p>وقد أُعطيت هذه الشهادة بناءً على طلبه، دون أدنى مسؤولية على الشركة.</p>'
                    .$signature,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        DB::table('letter_templates')->whereIn('slug', ['salary-certificate', 'no-objection-certificate'])->delete();
        DB::table('permissions')->where('name', 'like', '%:EmployeeRequest')->delete();
        Schema::dropIfExists('employee_requests');
    }
};
