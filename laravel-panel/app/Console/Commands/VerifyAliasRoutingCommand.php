<?php

namespace App\Console\Commands;

use App\Models\EmailAlias;
use App\Services\PostfixService;
use Illuminate\Console\Command;

class VerifyAliasRoutingCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mail:verify-alias 
                            {source_email? : The source alias email address to verify}
                            {--all : Audit routing verification for all aliases in database}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verify Postfix virtual alias resolution and mail routing for tenant aliases';

    /**
     * Execute the console command.
     */
    public function handle(PostfixService $postfixService): int
    {
        $all = $this->option('all');
        $sourceEmail = $this->argument('source_email');

        if (! $all && ! $sourceEmail) {
            $this->error('Please provide a source_email argument or pass --all to audit all aliases.');
            return 1;
        }

        $this->info('=== Postfix Virtual Alias Routing Verifier (Step 30) ===');
        $this->line('Driver: Postfix MySQL Map (mysql-virtual-aliases.cf)');
        $this->line('MTA Daemon Status: ' . ($postfixService->isPostfixOperational() ? '<fg=green>Operational</>' : '<fg=yellow>Simulated / Local (Postfix daemon not active)</>'));
        $this->newLine();

        if ($all) {
            $aliases = EmailAlias::with('domain')->get();
            if ($aliases->isEmpty()) {
                $this->warn('No email aliases found in database.');
                return 0;
            }

            $rows = [];
            $hasFailure = false;

            foreach ($aliases as $alias) {
                $routing = $postfixService->verifyAliasRouting($alias->source_email);
                if (! $routing['is_routable']) {
                    $hasFailure = true;
                }

                $rows[] = [
                    'source'     => $alias->source_email,
                    'domain'     => $alias->domain?->domain_name . ' (' . $alias->domain?->status . ')',
                    'dest'       => $alias->destination_email,
                    'terminal'   => $routing['terminal_target'],
                    'hops'       => $routing['hop_count'],
                    'status'     => $routing['status'],
                    'routable'   => $routing['is_routable'] ? '<fg=green>YES</>' : '<fg=red>NO</>',
                    'warning'    => $routing['warning'] ?? 'None',
                ];
            }

            $this->table(
                ['Source Alias', 'Parent Domain', 'Forwarding Target', 'Terminal Target', 'Hops', 'Status', 'Routable', 'Warning'],
                $rows
            );

            return $hasFailure ? 1 : 0;
        }

        // Single alias check
        $routing = $postfixService->verifyAliasRouting($sourceEmail);

        $this->table(
            ['Property', 'Value'],
            [
                ['Source Address', $routing['source_email']],
                ['Forwarding Target', $routing['destination'] ?? '<fg=red>Not found / Inactive</>'],
                ['Terminal Target', $routing['terminal_target'] ?? '<fg=red>None</>'],
                ['Expansion Chain', implode(' -> ', $routing['hops'])],
                ['Hop Count', $routing['hop_count']],
                ['Cycle Detected', $routing['cycle_detected'] ? '<fg=red>YES</>' : '<fg=green>NO</>'],
                ['Routable in Postfix', $routing['is_routable'] ? '<fg=green>YES</>' : '<fg=red>NO</>'],
                ['Resolution Status', $routing['status']],
                ['MTA Driver', $routing['mta_driver']],
                ['Notes / Warnings', $routing['warning'] ?? 'None. Routing map query is valid.'],
            ]
        );

        if (! $routing['is_routable']) {
            $this->error("Routing resolution check failed for {$sourceEmail}.");
            return 1;
        }

        $this->info("Routing resolution check passed for {$sourceEmail}.");
        return 0;
    }
}
