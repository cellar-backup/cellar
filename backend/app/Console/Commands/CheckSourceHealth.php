<?php

namespace App\Console\Commands;

use App\Models\Source;
use Illuminate\Console\Command;

class CheckSourceHealth extends Command
{
    protected $signature = 'cellar:check-source-health';

    protected $description = 'Check connectivity for all enabled sources';

    public function handle(): int
    {
        $sources = Source::where('enabled', true)->get();

        if ($sources->isEmpty()) {
            $this->info('No enabled sources to check.');

            return self::SUCCESS;
        }

        foreach ($sources as $source) {
            try {
                $reachable = $source->checkConnection();
            } catch (\Throwable) {
                $reachable = false;
            }

            $source->update([
                'is_reachable' => $reachable,
                'last_checked_at' => now(),
            ]);

            $status = $reachable ? '<fg=green>OK</>' : '<fg=red>FAIL</>';
            $this->line("  {$source->display_label}: {$status}");
        }

        $this->info("Checked {$sources->count()} sources.");

        return self::SUCCESS;
    }
}
