<?php

namespace App\Console\Commands;

use App\Models\Domain;
use App\Services\PostfixService;
use Illuminate\Console\Command;

class VerifyCatchAllRoutingCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mail:verify-catchall 
                            {domain? : The domain name to inspect catch-all configuration for}
                            {--all : Audit catch-all routing for all domains in database}
                            {--probe= : Specific recipient local-part to simulate lookup for}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verify Postfix virtual catch-all resolution and unknown-recipient fallback routing (Step 32)';

    /**
     * Execute the console command.
     */
    public function handle(PostfixService $postfixService): int
    {
        $all = $this->option('all');
        $domainName = $this->argument('domain');
        $probe = $this->option('probe');

        if (! $all && ! $domainName) {
            $this->error('Please provide a domain name argument or pass --all to audit all domains.');
            return 1;
        }

        $this->info('=== Postfix Virtual Catch-All Routing Verifier (Step 32) ===');
        $this->line('Driver: Postfix MySQL Map (mysql-virtual-catchall.cf)');
        $this->line('MTA Daemon Status: ' . ($postfixService->isPostfixOperational() ? '<fg=green>Operational</>' : '<fg=yellow>Simulated / Local (Postfix daemon not active)</>'));
        $this->newLine();

        if ($all) {
            $domains = Domain::with('catchAllMailbox')->get();
            if ($domains->isEmpty()) {
                $this->warn('No domains found in database.');
                return 0;
            }

            $rows = [];
            $hasFailure = false;

            foreach ($domains as $domain) {
                $routing = $postfixService->verifyCatchAllRouting($domain, $probe);

                if ($routing['status'] === 'failed') {
                    $hasFailure = true;
                }

                $destinationDisplay = match ($domain->catch_all_policy) {
                    'mailbox'  => $domain->catchAllMailbox?->email ?? '<fg=red>Missing Mailbox</>',
                    'discard'  => 'devnull@localhost (discard)',
                    'reject'   => '[Rejection RFC 550]',
                    default    => '[Disabled - RFC 550 Rejection]',
                };

                $rows[] = [
                    'domain'      => $domain->domain_name . ' (' . $domain->status . ')',
                    'policy'      => $domain->catch_all_policy ?? 'disabled',
                    'destination' => $destinationDisplay,
                    'probe_email' => $routing['probe_email'],
                    'routable'    => $routing['is_routable'] ? '<fg=green>YES</>' : '<fg=yellow>NO (Reject)</>',
                    'status'      => $routing['status'],
                    'warning'     => $routing['warning'] ?? 'None',
                ];
            }

            $this->table(
                ['Domain', 'Policy', 'Destination', 'Probe Address', 'Routable', 'Status', 'Warning'],
                $rows
            );

            return $hasFailure ? 1 : 0;
        }

        // Single domain check
        $domain = Domain::where('domain_name', strtolower(trim($domainName)))->first();

        if (! $domain) {
            $this->error("Domain '{$domainName}' not found in database.");
            return 1;
        }

        $routing = $postfixService->verifyCatchAllRouting($domain, $probe);

        $this->table(
            ['Property', 'Value'],
            [
                ['Domain', $domain->domain_name],
                ['Domain Status', $domain->status],
                ['Catch-All Policy', $domain->catch_all_policy ?? 'disabled'],
                ['Designated Mailbox', $domain->catchAllMailbox?->email ?? 'None'],
                ['Simulated Probe Address', $routing['probe_email']],
                ['Action Taken', strtoupper($routing['action'])],
                ['Destination Target', $routing['destination'] ?? '[None - Reject]'],
                ['Terminal Target', $routing['terminal_target'] ?? '[None - Reject]'],
                ['Routable in Postfix', $routing['is_routable'] ? '<fg=green>YES</>' : '<fg=yellow>NO (550 Rejection)</>'],
                ['Resolution Status', $routing['status']],
                ['MTA Driver', $routing['mta_driver']],
                ['Notes / Warnings', $routing['warning'] ?? 'None. Routing map query is valid.'],
            ]
        );

        if ($routing['status'] === 'failed') {
            $this->error("Catch-all routing resolution check failed for {$domainName}: {$routing['warning']}");
            return 1;
        }

        $this->info("Catch-all routing resolution check passed for {$domainName}.");
        return 0;
    }
}
