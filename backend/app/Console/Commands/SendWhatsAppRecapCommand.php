<?php

namespace App\Console\Commands;

use App\Models\Team;
use App\Services\WhatsAppRecapService;
use Carbon\Carbon;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendWhatsAppRecapCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'whatsapp:send-recap';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send daily WhatsApp attendance recap to configured teams';

    /**
     * Execute the console command.
     */
    public function handle(WhatsAppRecapService $whatsAppRecapService): int
    {
        $currentHour = Carbon::now()->format('H:00:00');

        // Find teams scheduled for recap at current hour
        $teams = Team::where('whatsapp_recap_enabled', true)
            ->where('whatsapp_connected', true)
            ->whereNotNull('whatsapp_recipient_phone')
            ->whereTime('whatsapp_recap_time', $currentHour)
            ->get();

        $this->info("Found {$teams->count()} team(s) scheduled for recap at {$currentHour}");

        if ($teams->isEmpty()) {
            $this->info('No teams to process.');

            return Command::SUCCESS;
        }

        foreach ($teams as $team) {
            try {
                $this->info("Sending recap for team: {$team->name} (ID: {$team->id})");

                $success = $whatsAppRecapService->sendRecap($team);

                if ($success) {
                    $this->info("✓ Successfully sent recap for team: {$team->name}");
                } else {
                    $this->error("✗ Failed to send recap for team: {$team->name}");
                    $this->error("  Error: {$team->whatsapp_last_error}");
                }
            } catch (Exception $e) {
                $this->error("Error processing team {$team->name}: ".$e->getMessage());
                Log::error("WhatsApp recap command failed for team {$team->id}: ".$e->getMessage());
                // Continue to next team
            }
        }

        $this->info('WhatsApp recap command completed.');

        return Command::SUCCESS;
    }
}
