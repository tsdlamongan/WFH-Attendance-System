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
        Schema::create('overtimes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->date('date');
            $table->unsignedSmallInteger('planned_minutes');
            $table->unsignedSmallInteger('approved_minutes')->nullable(); // Manager override of planned_minutes
            $table->text('reason');
            $table->string('status')->default('pending'); // enum: pending, approved, rejected
            $table->string('source')->default('employee'); // enum: employee, manager
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('notes')->nullable(); // Manager's notes when approving/rejecting
            $table->timestamps();

            $table->index(['user_id', 'date']);
            $table->index('status');
            // Same convention as leaves: 'restrict' because SQLite doesn't support 'setNull'
            $table->foreign('approved_by')->references('id')->on('users')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('overtimes');
    }
};
