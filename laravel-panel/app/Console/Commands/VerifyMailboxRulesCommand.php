<?php

namespace App\Console\Commands;

use App\Models\Mailbox;
use App\Services\MailboxRuleService;
use Illuminate\Console\Command;

class VerifyMailboxRulesCommand extends Command
{
    protected $signature = 'mail:verify-mailbox-rules 
                            {mailbox? : Specific mailbox email address or ID}
                            {--all : Verify rules across all active mailboxes}';

    protected $description = 'Verify mailbox forwarding rules, loop detection, auto-reply schedule, and Sieve compilation';

    public function handle(MailboxRuleService $ruleService): int
    {
        $mailboxIdentifier = $this->argument('mailbox');
        $verifyAll = $this->option('all');

        if (!$mailboxIdentifier && !$verifyAll) {
            $this->error('Please specify a mailbox email or ID, or pass --all to verify all mailboxes.');
            return 1;
        }

        $query = Mailbox::with('domain');

        if ($mailboxIdentifier) {
            if (is_numeric($mailboxIdentifier)) {
                $query->where('id', $mailboxIdentifier);
            } else {
                $query->where('email', strtolower(trim($mailboxIdentifier)));
            }
        } elseif ($verifyAll) {
            $query->where('is_active', true);
        }

        $mailboxes = $query->get();

        if ($mailboxes->isEmpty()) {
            $this->warn('No matching mailboxes found.');
            return 0;
        }

        $this->info("Verifying mailbox rules for {$mailboxes->count()} mailbox(es)...\n");

        $rows = [];
        foreach ($mailboxes as $mailbox) {
            $telemetry = $ruleService->verifyMailboxRules($mailbox);

            $fwd = $telemetry['forwarding'];
            $fwdSummary = $fwd['is_enabled']
                ? implode(', ', $fwd['destinations']) . ($fwd['keep_copy'] ? ' (keep copy)' : ' (no copy)')
                : 'Disabled';

            $ar = $telemetry['auto_reply'];
            $arSummary = $ar['is_enabled']
                ? "{$ar['schedule_status']}: " . substr((string) $ar['subject'], 0, 25)
                : 'Disabled';

            $rows[] = [
                $mailbox->id,
                $mailbox->email,
                $fwd['status'],
                $fwdSummary,
                $arSummary,
                $telemetry['sieve']['environment_mode'],
            ];

            if ($mailboxIdentifier && $mailboxes->count() === 1) {
                // Print detailed single mailbox breakdown
                $this->table(
                    ['Attribute', 'Value'],
                    [
                        ['Mailbox ID', $mailbox->id],
                        ['Email', $mailbox->email],
                        ['Domain', $mailbox->domain?->domain_name ?? '-'],
                        ['Forwarding Enabled', $fwd['is_enabled'] ? 'Yes' : 'No'],
                        ['Forwarding Destinations', implode(', ', $fwd['destinations']) ?: 'None'],
                        ['Preserve Local Copy', $fwd['keep_copy'] ? 'Yes' : 'No'],
                        ['Forwarding Routable', $fwd['is_routable'] ? 'Yes' : 'No'],
                        ['Cycle Detected', $fwd['cycle_detected'] ? 'YES (LOOP DETECTED)' : 'No'],
                        ['Cycle Path', $fwd['cycle_path'] ?? 'None'],
                        ['Forwarding Status', $fwd['status']],
                        ['Auto-Reply Enabled', $ar['is_enabled'] ? 'Yes' : 'No'],
                        ['Auto-Reply Status', $ar['schedule_status']],
                        ['Currently Active', $ar['is_currently_active'] ? 'Yes' : 'No'],
                        ['Auto-Reply Subject', $ar['subject'] ?? 'None'],
                        ['Schedule Window', ($ar['start_at'] ?? 'Any') . ' -> ' . ($ar['end_at'] ?? 'Any')],
                        ['Sieve Driver', $telemetry['sieve']['driver']],
                        ['Postfix Map', $telemetry['mta']['map_query']],
                    ]
                );

                $this->line("\n<info>Generated Sieve Script Preview:</info>");
                $this->line($telemetry['sieve']['script_preview']);
                return 0;
            }
        }

        $this->table(
            ['ID', 'Mailbox', 'Forwarding Status', 'Forwarding Targets', 'Auto-Reply', 'Driver Mode'],
            $rows
        );

        return 0;
    }
}
