<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // Who performed the action (polymorphic — Admin or Teacher)
            $table->string('actor_type')->nullable(); // e.g. App\Models\Admin\Admin
            $table->unsignedBigInteger('actor_id')->nullable();

            // Display-friendly actor snapshot (preserved even if the account is deleted)
            $table->string('actor_name')->nullable();  // username or full name
            $table->string('actor_role')->nullable();  // 'Admin' | 'Teacher'

            // What happened
            $table->string('action');         // e.g. 'admin.login', 'teacher.document_request.created'
            $table->string('category');       // e.g. 'Authentication', 'Document Requests', 'User Management'
            $table->text('description');      // human-readable sentence

            // The record that was affected (optional)
            $table->string('subject_type')->nullable();    // e.g. App\Models\Admin\DocumentRequest
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label')->nullable();   // e.g. 'DR-2026-001'

            // Outcome
            $table->string('status')->default('success'); // 'success' | 'failed'

            // Extra context (no passwords, no tokens)
            $table->json('metadata')->nullable();

            $table->timestamps();

            // Indexes for efficient tab filtering and chronological listing
            $table->index(['actor_type', 'actor_id']);
            $table->index(['actor_role', 'created_at']);
            $table->index(['action', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
