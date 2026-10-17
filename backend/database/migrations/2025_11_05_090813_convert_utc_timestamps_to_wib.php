<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Convert all existing UTC timestamps to WIB (Asia/Jakarta) by adding 7 hours.
     * This migration is necessary because the application timezone was changed from UTC to Asia/Jakarta.
     *
     * IMPORTANT: This migration should only be run ONCE on existing data.
     * If you're setting up a fresh installation, you can skip this migration.
     */
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        // Update attendances table
        $this->updateAttendances($driver, '+');

        // Update leaves table
        $this->updateLeaves($driver, '+');

        // Update activity_logs table (no updated_at column)
        $this->updateActivityLogs($driver, '+');

        // Update users table
        $this->updateUsers($driver, '+');

        // Update holidays table
        $this->updateHolidays($driver, '+');

        // Update tasks table
        $this->updateTasks($driver, '+');
    }

    /**
     * Reverse the migrations.
     *
     * Convert WIB timestamps back to UTC by subtracting 7 hours.
     */
    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        // Revert all tables
        $this->updateAttendances($driver, '-');
        $this->updateLeaves($driver, '-');
        $this->updateActivityLogs($driver, '-');
        $this->updateUsers($driver, '-');
        $this->updateHolidays($driver, '-');
        $this->updateTasks($driver, '-');
    }

    /**
     * Get the SQL expression for adding/subtracting hours based on database driver
     */
    private function getDateAddExpression(string $driver, string $column, string $operation): string
    {
        $hours = $operation === '+' ? 7 : -7;

        return match ($driver) {
            'pgsql' => "{$column} + INTERVAL '{$hours} hours'",
            'mysql' => "DATE_ADD({$column}, INTERVAL {$hours} HOUR)",
            'sqlite' => "datetime({$column}, '{$operation}7 hours')",
            default => throw new Exception("Unsupported database driver: {$driver}"),
        };
    }

    private function updateAttendances(string $driver, string $operation): void
    {
        $checkInExpr = $this->getDateAddExpression($driver, 'check_in', $operation);
        $checkOutExpr = $this->getDateAddExpression($driver, 'check_out', $operation);
        $createdAtExpr = $this->getDateAddExpression($driver, 'created_at', $operation);
        $updatedAtExpr = $this->getDateAddExpression($driver, 'updated_at', $operation);

        DB::statement("
            UPDATE attendances 
            SET 
                check_in = {$checkInExpr},
                check_out = CASE 
                    WHEN check_out IS NOT NULL THEN {$checkOutExpr}
                    ELSE NULL 
                END,
                created_at = {$createdAtExpr},
                updated_at = {$updatedAtExpr}
        ");
    }

    private function updateLeaves(string $driver, string $operation): void
    {
        $createdAtExpr = $this->getDateAddExpression($driver, 'created_at', $operation);
        $updatedAtExpr = $this->getDateAddExpression($driver, 'updated_at', $operation);

        DB::statement("
            UPDATE leaves 
            SET 
                created_at = {$createdAtExpr},
                updated_at = {$updatedAtExpr}
        ");
    }

    private function updateActivityLogs(string $driver, string $operation): void
    {
        $createdAtExpr = $this->getDateAddExpression($driver, 'created_at', $operation);

        DB::statement("
            UPDATE activity_logs 
            SET 
                created_at = {$createdAtExpr}
        ");
    }

    private function updateUsers(string $driver, string $operation): void
    {
        $emailVerifiedAtExpr = $this->getDateAddExpression($driver, 'email_verified_at', $operation);
        $createdAtExpr = $this->getDateAddExpression($driver, 'created_at', $operation);
        $updatedAtExpr = $this->getDateAddExpression($driver, 'updated_at', $operation);

        DB::statement("
            UPDATE users 
            SET 
                email_verified_at = CASE 
                    WHEN email_verified_at IS NOT NULL THEN {$emailVerifiedAtExpr}
                    ELSE NULL 
                END,
                created_at = {$createdAtExpr},
                updated_at = {$updatedAtExpr}
        ");
    }

    private function updateHolidays(string $driver, string $operation): void
    {
        $createdAtExpr = $this->getDateAddExpression($driver, 'created_at', $operation);
        $updatedAtExpr = $this->getDateAddExpression($driver, 'updated_at', $operation);

        DB::statement("
            UPDATE holidays 
            SET 
                created_at = {$createdAtExpr},
                updated_at = {$updatedAtExpr}
        ");
    }

    private function updateTasks(string $driver, string $operation): void
    {
        $createdAtExpr = $this->getDateAddExpression($driver, 'created_at', $operation);
        $updatedAtExpr = $this->getDateAddExpression($driver, 'updated_at', $operation);

        DB::statement("
            UPDATE tasks 
            SET 
                created_at = {$createdAtExpr},
                updated_at = {$updatedAtExpr}
        ");
    }
};
