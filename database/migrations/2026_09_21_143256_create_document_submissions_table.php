<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('document_submissions', function (Blueprint $table) {
            $table->id();
            $table->string('submission_code')->unique();
            $table->foreignId('submission_request_id')->constrained('submission_requests')->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained('teachers')->cascadeOnDelete();
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();

            $table->string('original_name');
            $table->string('stored_name');
            $table->string('file_path');
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->default(0);

            $table->string('status')->default('Submitted');
            $table->unsignedInteger('revision_count')->default(0);
            $table->text('revision_note')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'submitted_at']);
            $table->index(['teacher_id', 'status']);
            $table->index(['submission_request_id']);
        });

        Schema::create('document_submission_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_submission_id')->constrained('document_submissions')->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('original_name');
            $table->string('stored_name');
            $table->string('file_path');
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('status');
            $table->text('revision_note')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['document_submission_id', 'version_number'],
                'doc_submission_version_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_submission_versions');
        Schema::dropIfExists('document_submissions');
    }
};