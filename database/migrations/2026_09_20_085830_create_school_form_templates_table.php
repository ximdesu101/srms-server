<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_form_templates', function (Blueprint $table) {
            $table->id();
            $table->string('form_code', 10)->unique();
            $table->string('original_name');
            $table->string('stored_name');
            $table->string('file_path');
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->default(0); 
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_form_templates');
    }
};