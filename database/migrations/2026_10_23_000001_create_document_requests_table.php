<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_code')->unique();
            $table->foreignId('teacher_id')->constrained('teachers')->cascadeOnDelete();
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('document_code');
            $table->string('document_name');
            $table->text('purpose')->nullable();

            // Status: Pending | Acknowledged | Processing | Fulfilled | Unavailable | Cancelled
            $table->string('status')->default('Pending');

            // Admin response message (used for both Fulfilled and Unavailable)
            $table->text('admin_message')->nullable();

            // Fulfilled document fields
            $table->string('file_original_name')->nullable();
            $table->string('file_stored_name')->nullable();
            $table->string('file_path')->nullable();
            $table->string('file_mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->default(0);

            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['teacher_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_requests');
    }
};
