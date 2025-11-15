<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->time('check_in_window_start')->default('09:00:00')->after('max_leave_days_per_month');
            $table->time('check_in_window_end')->default('10:00:00')->after('check_in_window_start');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn(['check_in_window_start', 'check_in_window_end']);
        });
    }
};
