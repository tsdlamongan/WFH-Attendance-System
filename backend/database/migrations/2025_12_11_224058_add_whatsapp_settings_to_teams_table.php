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
            // WhatsApp Gateway Connection
            $table->text('whatsapp_api_secret')->nullable()->after('check_in_window_end');
            $table->string('whatsapp_account_unique_id')->nullable();
            $table->string('whatsapp_account_phone')->nullable();
            $table->string('whatsapp_account_name')->nullable();
            $table->string('whatsapp_token')->nullable();
            $table->boolean('whatsapp_connected')->default(false);
            $table->timestamp('whatsapp_connected_at')->nullable();

            // Recap Configuration
            $table->string('whatsapp_recipient_phone')->nullable();
            $table->time('whatsapp_recap_time')->default('22:00:00');
            $table->boolean('whatsapp_recap_enabled')->default(false);

            // Tracking
            $table->timestamp('whatsapp_last_sent_at')->nullable();
            $table->text('whatsapp_last_error')->nullable();

            // Indexes for performance
            $table->index('whatsapp_recap_enabled');
            $table->index('whatsapp_connected');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropIndex(['whatsapp_recap_enabled']);
            $table->dropIndex(['whatsapp_connected']);

            $table->dropColumn([
                'whatsapp_api_secret',
                'whatsapp_account_unique_id',
                'whatsapp_account_phone',
                'whatsapp_account_name',
                'whatsapp_token',
                'whatsapp_connected',
                'whatsapp_connected_at',
                'whatsapp_recipient_phone',
                'whatsapp_recap_time',
                'whatsapp_recap_enabled',
                'whatsapp_last_sent_at',
                'whatsapp_last_error',
            ]);
        });
    }
};
