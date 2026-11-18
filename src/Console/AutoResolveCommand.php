<?php

namespace Lookout\Console;

use Illuminate\Console\Command;
use Lookout\Models\Issue;
use Lookout\Services\IssueStatus;
use Lookout\Services\Settings;

/**
 * Resolves open NightOwl issues not seen for the configured number of days (Settings::AUTO_RESOLVE_KEY). The
 * agent has no auto-resolve of its own; it reopens a resolved issue when it recurs.
 */
class AutoResolveCommand extends Command
{
    protected $signature = 'lookout:auto-resolve {--limit=1000 : Most issues resolved in one run}';

    /** The name the command had before it moved into the package. */
    protected $aliases = ['nightowl:auto-resolve'];

    protected $description = 'Resolve open NightOwl issues not seen for the days chosen in Lookout settings';

    public function handle(IssueStatus $status): int
    {
        $days = Settings::autoResolveDays();
        if ($days === null) {
            $this->info('Auto-resolve is off.');

            return self::SUCCESS;
        }

        $resolved = 0;
        Issue::query()->where('status', 'open')->where('last_seen_at', '<', now('UTC')->subDays($days))
            ->orderBy('id')->limit((int) $this->option('limit'))->get()
            ->each(function (Issue $issue) use ($status, &$resolved): void {
                $resolved += (int) $status->change($issue, 'resolved', null, 'auto_resolve');
            });

        $this->info("Resolved {$resolved} issue(s) not seen for {$days} days.");

        return self::SUCCESS;
    }
}
