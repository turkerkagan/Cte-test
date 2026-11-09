<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 40);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['institution_id', 'code']);
        });

        Schema::create('wards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('block_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 40);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['block_id', 'code']);
        });

        Schema::create('beds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ward_id')->constrained()->cascadeOnDelete();
            $table->string('name', 40)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['ward_id', 'name']);
        });

        Schema::create('inmate_files', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->nullable()->unique();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->string('national_id', 30)->nullable();
            $table->string('full_name');
            $table->date('registered_at');
            $table->date('institution_entry_date')->nullable();
            $table->foreignId('status_id')->nullable()->constrained('lookup_values')->nullOnDelete();
            $table->foreignId('risk_id')->nullable()->constrained('lookup_values')->nullOnDelete();
            $table->text('general_notes')->nullable();
            $table->unsignedBigInteger('legacy_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['institution_id', 'national_id']);
            $table->index(['institution_id', 'full_name']);
            $table->index(['institution_id', 'registered_at']);
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->foreign('inmate_file_id')->references('id')->on('inmate_files')->cascadeOnDelete();
        });

        Schema::create('housing_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inmate_file_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bed_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('started_at');
            $table->date('ended_at')->nullable();
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['bed_id', 'ended_at']);
        });

        Schema::create('workplaces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('work_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inmate_file_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workplace_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('decision_id')->nullable()->constrained('lookup_values')->nullOnDelete();
            $table->date('started_at')->nullable();
            $table->date('ended_at')->nullable();
            $table->string('status')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('inmate_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inmate_file_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('category', 60);
            $table->string('original_name');
            $table->string('storage_path')->unique();
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size');
            $table->string('sha256', 64);
            $table->timestamps();
        });

        Schema::create('intake_interviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inmate_file_id')->constrained()->cascadeOnDelete();
            $table->date('interviewed_at')->nullable();
            $table->date('birth_date')->nullable();
            $table->string('profession')->nullable();
            $table->text('health_summary')->nullable();
            $table->string('disability_status')->nullable();
            $table->text('disability_details')->nullable();
            $table->string('execution_awareness')->nullable();
            $table->string('family_status')->nullable();
            $table->text('family_details')->nullable();
            $table->string('informed_status')->nullable();
            $table->string('acquaintance_status')->nullable();
            $table->text('acquaintance_details')->nullable();
            $table->text('self_harm')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('health_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inmate_file_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('general_status')->nullable();
            $table->text('chronic_conditions')->nullable();
            $table->string('infectious_status')->nullable();
            $table->string('porter_status')->nullable();
            $table->date('porter_date_1')->nullable();
            $table->date('porter_date_2')->nullable();
            $table->string('uyap_status')->nullable();
            $table->timestamps();
        });

        Schema::create('health_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inmate_file_id')->constrained()->cascadeOnDelete();
            $table->dateTime('recorded_at');
            $table->text('complaint')->nullable();
            $table->text('diagnosis')->nullable();
            $table->text('treatment')->nullable();
            $table->text('referral')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('execution_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inmate_file_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('uyap_number', 30)->nullable()->index();
            $table->string('arrival_reason')->nullable();
            $table->date('supervised_release_date')->nullable()->index();
            $table->date('release_date')->nullable()->index();
            $table->string('status')->nullable();
            $table->date('sexual_offense_letter_date')->nullable();
            $table->string('public_case_court')->nullable();
            $table->date('sexual_offense_decision_date')->nullable();
            $table->unsignedSmallInteger('sentence_years')->nullable();
            $table->unsignedTinyInteger('sentence_months')->nullable();
            $table->unsignedTinyInteger('sentence_days')->nullable();
            $table->string('special_board')->nullable();
            $table->text('special_board_status')->nullable();
            $table->date('special_board_date')->nullable();
            $table->string('additional_supervision')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        foreach (['execution_events', 'psychosocial_records', 'spiritual_activities', 'mesem_events'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->foreignId('inmate_file_id')->constrained()->cascadeOnDelete();
                $table->dateTime('recorded_at')->nullable();
                $table->string('type')->nullable();
                $table->json('details')->nullable();
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        Schema::create('leave_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inmate_file_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->nullable()->constrained('lookup_values')->nullOnDelete();
            $table->unsignedSmallInteger('day_count')->nullable();
            $table->dateTime('departed_at')->nullable();
            $table->dateTime('expected_return_at')->nullable();
            $table->dateTime('returned_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        foreach (['service_records', 'ministry_referrals', 'visit_records', 'inmate_evaluations'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->foreignId('inmate_file_id')->constrained()->cascadeOnDelete();
                $table->dateTime('recorded_at')->nullable();
                $table->json('details')->nullable();
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        foreach (['spiritual_profiles', 'education_profiles', 'mesem_profiles'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->foreignId('inmate_file_id')->unique()->constrained()->cascadeOnDelete();
                $table->json('details')->nullable();
                $table->timestamps();
            });
        }

        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('instructor')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('course_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->restrictOnDelete();
            $table->foreignId('inmate_file_id')->constrained()->cascadeOnDelete();
            $table->date('started_at')->nullable();
            $table->date('ended_at')->nullable();
            $table->string('status')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('hygiene_trainings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inmate_file_id')->constrained()->cascadeOnDelete();
            $table->date('training_date')->nullable();
            $table->boolean('occupational_safety_received')->nullable();
            $table->boolean('hygiene_received')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', fn (Blueprint $table) => $table->dropForeign(['inmate_file_id']));
        foreach (['hygiene_trainings', 'course_enrollments', 'courses', 'mesem_profiles', 'education_profiles', 'spiritual_profiles', 'inmate_evaluations', 'visit_records', 'ministry_referrals', 'service_records', 'leave_records', 'mesem_events', 'spiritual_activities', 'psychosocial_records', 'execution_events', 'execution_profiles', 'health_records', 'health_profiles', 'intake_interviews', 'inmate_documents', 'work_assignments', 'workplaces', 'housing_assignments', 'inmate_files', 'beds', 'wards', 'blocks'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
