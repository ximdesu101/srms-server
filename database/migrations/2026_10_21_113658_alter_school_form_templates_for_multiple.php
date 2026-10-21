<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Add new columns first.
        Schema::table('school_form_templates', function (Blueprint $table) {
            $table->string('file_hash', 64)->nullable()->after('file_size');
            $table->boolean('is_current')->default(false)->after('file_hash');
        });

        // Remove the unique index only if it exists.
        $indexes = DB::select("SHOW INDEX FROM school_form_templates");

        $uniqueIndexExists = collect($indexes)->contains(function ($index) {
            return $index->Key_name === 'school_form_templates_form_code_unique';
        });

        if ($uniqueIndexExists) {
            Schema::table('school_form_templates', function (Blueprint $table) {
                $table->dropUnique('school_form_templates_form_code_unique');
            });
        }

        // Add indexes for template lookup and duplicate detection.
        Schema::table('school_form_templates', function (Blueprint $table) {
            $table->index(['form_code', 'is_current']);
            $table->index(['form_code', 'file_hash']);
        });

        // Mark existing templates as current.
        DB::table('school_form_templates')->update([
            'is_current' => true,
        ]);
    }

    public function down(): void
    {
        Schema::table('school_form_templates', function (Blueprint $table) {
            $table->dropIndex(['form_code', 'is_current']);
            $table->dropIndex(['form_code', 'file_hash']);
            $table->dropColumn(['file_hash', 'is_current']);
        });
    }
};