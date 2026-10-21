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
            // Drop unique constraint on form_code so multiple versions can exist
            $table->dropUnique(['form_code']);
        });

        Schema::table('school_form_templates', function (Blueprint $table) {
            $table->string('status', 20)->default('current')->after('file_size');
            $table->index(['form_code', 'status']);
        });

        // Existing rows become the current template for their form_code
        DB::table('school_form_templates')->update(['status' => 'current']);
    }

    public function down(): void
    {
        // Keep only the latest (current) template per form_code before restoring unique
        $keepIds = DB::table('school_form_templates')
            ->select(DB::raw('MAX(id) as id'))
            ->groupBy('form_code')
            ->pluck('id');

        DB::table('school_form_templates')
            ->whereNotIn('id', $keepIds)
            ->delete();

        Schema::table('school_form_templates', function (Blueprint $table) {
            $table->dropIndex(['form_code', 'status']);
            $table->dropColumn('status');
        });

        Schema::table('school_form_templates', function (Blueprint $table) {
            $table->unique('form_code');
        });
    }
};
