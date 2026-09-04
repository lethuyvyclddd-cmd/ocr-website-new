<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected array $columnsToDrop = [
        'admission_year',
        'admission_round_code',
        'admission_round_name',
        'student_code',
        'student_id_number',
        'highschool_province_code',
        'highschool_code',
        'priority_area',
        'priority_subject',
        'college_province_code',
        'university_code',
        'major_code',
        'average_score',
        'classification',
        'gb_date',
        'gb_template',
        'entry_date',
        'entered_by',
        'admission_result',
        'tuition_fee_hk1',
        'admission_fee',
        'total_amount',
    ];

    public function up(): void
    {
        Schema::table('applicants', function (Blueprint $table) {
            $existing = array_filter(
                $this->columnsToDrop,
                fn ($column) => Schema::hasColumn('applicants', $column)
            );

            if (!empty($existing)) {
                $table->dropColumn($existing);
            }
        });
    }

    public function down(): void
    {
        Schema::table('applicants', function (Blueprint $table) {
            $table->string('admission_year')->nullable();
            $table->string('admission_round_code')->nullable();
            $table->string('admission_round_name')->nullable();
            $table->string('student_code')->nullable()->index();
            $table->string('student_id_number')->nullable()->index();
            $table->string('highschool_province_code')->nullable();
            $table->string('highschool_code')->nullable();
            $table->string('priority_area')->nullable();
            $table->string('priority_subject')->nullable();
            $table->string('college_province_code')->nullable();
            $table->string('university_code')->nullable();
            $table->string('major_code')->nullable();
            $table->decimal('average_score', 5, 2)->nullable();
            $table->string('classification')->nullable();
            $table->date('gb_date')->nullable();
            $table->string('gb_template')->nullable();
            $table->date('entry_date')->nullable();
            $table->string('entered_by')->nullable();
            $table->string('admission_result')->nullable();
            $table->decimal('tuition_fee_hk1', 12, 2)->nullable();
            $table->decimal('admission_fee', 12, 2)->nullable();
            $table->decimal('total_amount', 12, 2)->nullable();
        });
    }
};