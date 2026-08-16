<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applicants', function (Blueprint $table) {
            $table->id();

            $table->string('admission_year')->nullable();
            $table->string('admission_round_code')->nullable();
            $table->string('admission_round_name')->nullable();
            $table->string('student_code')->nullable()->index();
            $table->string('student_id_number')->nullable()->index();
            $table->string('missing_documents')->nullable();

            $table->string('last_name')->nullable();
            $table->string('first_name')->nullable();
            $table->string('gender')->nullable();
            $table->date('birth_date')->nullable();
            $table->string('id_number')->nullable()->index();
            $table->string('place_of_birth')->nullable();
            $table->string('ethnic')->nullable();
            $table->string('ward_name')->nullable();
            $table->string('province_name')->nullable();

            $table->string('highschool_province_code')->nullable();
            $table->string('highschool_province_name')->nullable();
            $table->string('highschool_code')->nullable();
            $table->string('highschool_name')->nullable();
            $table->string('highschool_graduation_year')->nullable();
            $table->string('priority_area')->nullable();
            $table->string('priority_subject')->nullable();
            $table->string('highschool_academic_rank')->nullable();
            $table->string('highschool_conduct_rank')->nullable();

            $table->string('college_province_code')->nullable();
            $table->string('university_province_name')->nullable();
            $table->string('university_code')->nullable();
            $table->string('university_name')->nullable();
            $table->string('university_graduation_year')->nullable();

            $table->text('permanent_address')->nullable();
            $table->string('phone_1')->nullable();
            $table->string('phone_2')->nullable();

            $table->string('major_code')->nullable();
            $table->string('major_name')->nullable();
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

            $table->text('note_1')->nullable();
            $table->text('note_2')->nullable();

            $table->string('training_type')->nullable();
            $table->string('diploma_number')->nullable();
            $table->string('diploma_registry_number')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('applicants');
    }
};