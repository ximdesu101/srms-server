<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teachers', function (Blueprint $table) {
            $table->string('profile_picture')->nullable()->after('contact_number');
        });

        Schema::table('staffs', function (Blueprint $table) {
            $table->string('profile_picture')->nullable()->after('position');
        });
    }

    public function down(): void
    {
        Schema::table('teachers', function (Blueprint $table) {
            $table->dropColumn('profile_picture');
        });

        Schema::table('staffs', function (Blueprint $table) {
            $table->dropColumn('profile_picture');
        });
    }
};
