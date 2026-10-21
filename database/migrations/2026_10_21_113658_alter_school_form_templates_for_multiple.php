<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('school_form_templates', function (Blueprint $table) {
            $table->dropUnique(['form_code']);
            $table->string('file_hash', 64)->nullable()->after('file_size');
            $table->boolean('is_current')->default(false)->after('file_hash');
            $table->index(['form_code', 'is_current']);
            $table->index(['form_code', 'file_hash']);
        });

        DB::table('school_form_templates')->update(['is_current' => true]);
    }

    public function down(): void
    {
        Schema::table('school_form_templates', function (Blueprint $table) {
            $table->dropIndex(['form_code', 'is_current']);
            $table->dropIndex(['form_code', 'file_hash']);
            $table->dropColumn(['file_hash', 'is_current']);
            $table->unique('form_code');
        });
    }
};
