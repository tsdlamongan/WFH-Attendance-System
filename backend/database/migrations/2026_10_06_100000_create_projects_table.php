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
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->onDelete('cascade');
            $table->string('name', 150);
            $table->string('pic')->nullable(); // Free text for the report's PIC column; empty = employees who logged tasks
            $table->unsignedTinyInteger('progress')->default(0); // 0..100 in steps of 10
            $table->string('status')->default('on_progress'); // enum: on_progress, completed, hold
            $table->string('note')->nullable(); // Report's NOTE column, e.g. "Proses QA"
            $table->boolean('is_active')->default(true); // Selectable by employees at check-in
            $table->timestamps();

            $table->unique(['team_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
