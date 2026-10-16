<?php

namespace App\Services;

use App\Models\Domain;
use App\Models\EmailAlias;
use App\Models\Mailbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * PostfixService — Manages Postfix virtual domains and mailboxes
 * via shell scripts with sudo privileges.
 *
 * Security: Scripts are called with specific whitelisted arguments only.
 * The Laravel process must have sudo rights for the scripts in /etc/sudoers:
 *
 * www-data ALL=(ALL) NOPASSWD: /opt/emailsaas/scripts/add_domain.sh
 * www-data ALL=(ALL) NOPASSWD: /opt/emailsaas/scripts/remove_domain.sh
 * www-data ALL=(ALL) NOPASSWD: /opt/emailsaas/scripts/add_mailbox.sh
 * www-data ALL=(ALL) NOPASSWD: /opt/emailsaas/scripts/remove_mailbox.sh
 */
class PostfixService
{
    private string $scriptsDir;

    public function __construct()
    {
        $this->scriptsDir = config('app.scripts_dir', '/opt/emailsaas/scripts');
    }

    // ─── Domain Management ──────────────────────────────────────────────────────

    /**
     * Add a new virtual domain to Postfix + Dovecot
     */
    public function addDomain(Domain $domain): bool
    {
        $this->validateDomainName($domain->domain_name);

        $output = $this->runScript('add_domain.sh', [
            escapeshellarg($domain->domain_name),
        ]);

        Log::info("PostfixService: Domain added", [
            'domain' => $domain->domain_name,
            'output' => $output,
        ]);

        // Extract DKIM public key from output if present
        if (preg_match('/DKIM_TXT_VALUE=(.+)/', $output, $matches)) {
            $domain->update([
                'dkim_public_key' => trim($matches[1]),
            ]);
        }

        return true;
    }

    /**
     * Remove a virtual domain
     */
    public function removeDomain(Domain $domain): bool
    {
        $this->validateDomainName($domain->domain_name);

        $this->runScript('remove_domain.sh', [
            escapeshellarg($domain->domain_name),
        ]);

        Log::info("PostfixService: Domain removed", ['domain' => $domain->domain_name]);
        return true;
    }

    // ─── Mailbox Management ─────────────────────────────────────────────────────

    /**
     * Create a new virtual mailbox
     */
    public function addMailbox(Mailbox $mailbox): bool
    {
        $this->validateEmail($mailbox->email);

        $output = $this->runScript('add_mailbox.sh', [
            escapeshellarg($mailbox->email),
        ]);

        Log::info("PostfixService: Mailbox created", ['email' => $mailbox->email]);
        return true;
    }

    /**
     * Remove a virtual mailbox
     */
    public function removeMailbox(Mailbox $mailbox): bool
    {
        $this->validateEmail($mailbox->email);

        $this->runScript('remove_mailbox.sh', [
            escapeshellarg($mailbox->email),
        ]);

        Log::info("PostfixService: Mailbox removed", ['email' => $mailbox->email]);
        return true;
    }

    // ─── Alias Management & Routing Verification (Step 30) ──────────────────────

    /**
     * Synchronize and verify an email alias in the virtual routing map
     */
    public function syncAlias(EmailAlias $alias): bool
    {
        $this->validateEmail($alias->source_email);
        $this->validateEmail($alias->destination_email);

        $routing = $this->verifyAliasRouting($alias->source_email);

        Log::info("PostfixService: Alias synchronized", [
            'source_email'      => $alias->source_email,
            'destination_email' => $alias->destination_email,
            'is_active'         => $alias->is_active,
            'status'            => $routing['status'],
            'is_routable'       => $routing['is_routable'],
        ]);

        return true;
    }

    /**
     * Remove an email alias from the virtual routing map
     */
    public function removeAlias(EmailAlias $alias): bool
    {
        $this->validateEmail($alias->source_email);

        Log::info("PostfixService: Alias removed", [
            'source_email'      => $alias->source_email,
            'destination_email' => $alias->destination_email,
        ]);

        return true;
    }

    /**
     * Verify the actual Postfix SQL routing resolution for a given source address.
     * Executes the exact query used by Postfix's mysql-virtual-aliases.cf lookup table.
     */
    public function verifyAliasRouting(string $sourceEmail): array
    {
        $email = strtolower(trim($sourceEmail));

        // Postfix virtual_alias_maps SQL lookup
        $result = DB::table('email_aliases')
            ->join('domains', 'email_aliases.domain_id', '=', 'domains.id')
            ->where('email_aliases.source_email', $email)
            ->where('email_aliases.is_active', true)
            ->where('domains.status', 'active')
            ->whereNull('domains.deleted_at')
            ->select('email_aliases.destination_email', 'email_aliases.is_active', 'domains.status as domain_status')
            ->first();

        if (! $result) {
            // Check if record exists on an active, non-deleted domain
            $existing = DB::table('email_aliases')
                ->join('domains', 'email_aliases.domain_id', '=', 'domains.id')
                ->where('email_aliases.source_email', $email)
                ->whereNull('domains.deleted_at')
                ->select('email_aliases.destination_email')
                ->first();

            return [
                'is_routable'     => false,
                'source_email'    => $email,
                'destination'     => $existing?->destination_email,
                'terminal_target' => $existing?->destination_email,
                'status'          => $existing ? 'configured' : 'unknown',
                'hops'            => [$email],
                'hop_count'       => 1,
                'cycle_detected'  => false,
                'warning'         => $existing ? 'Alias is disabled or parent domain is not active.' : 'Alias not found or parent domain is deleted.',
                'mta_driver'      => 'postfix_mysql_map',
            ];
        }

        // Trace recursive multi-hop expansion
        $hops = [$email];
        $curr = strtolower(trim($result->destination_email));
        $visited = [$email => true];
        $cycleDetected = false;
        $maxHops = 30;
        $hopCount = 0;

        while ($curr && $hopCount < $maxHops) {
            $hopCount++;
            $hops[] = $curr;

            if (isset($visited[$curr])) {
                $cycleDetected = true;
                break;
            }
            $visited[$curr] = true;

            $next = DB::table('email_aliases')
                ->join('domains', 'email_aliases.domain_id', '=', 'domains.id')
                ->where('email_aliases.source_email', $curr)
                ->where('email_aliases.is_active', true)
                ->where('domains.status', 'active')
                ->whereNull('domains.deleted_at')
                ->value('email_aliases.destination_email');

            if (! $next) {
                break;
            }
            $curr = strtolower(trim($next));
        }

        $terminalTarget = end($hops);
        $warning = null;
        $status = 'synchronized';

        if ($cycleDetected) {
            $status = 'failed';
            $warning = 'Forwarding cycle detected in virtual alias expansion.';
        } else {
            // Check if terminal destination is on a hosted domain and has a valid recipient
            $targetDomain = substr(strrchr($terminalTarget, '@') ?: '', 1);
            if ($targetDomain) {
                $isHostedDomain = DB::table('domains')
                    ->where('domain_name', $targetDomain)
                    ->where('status', 'active')
                    ->whereNull('deleted_at')
                    ->exists();

                if ($isHostedDomain) {
                    $hasMailbox = DB::table('mailboxes')
                        ->where('email', $terminalTarget)
                        ->where('is_active', true)
                        ->whereNull('deleted_at')
                        ->exists();

                    if (! $hasMailbox) {
                        $warning = "Local destination {$terminalTarget} does not correspond to an active mailbox. Inbound delivery may bounce.";
                        $status = 'failed';
                    }
                }
            }

            if ($status !== 'failed') {
                $status = $this->isPostfixOperational() ? 'operational' : 'synchronized';
            }
        }

        return [
            'is_routable'     => ($status === 'operational' || $status === 'synchronized') && ! $cycleDetected,
            'source_email'    => $email,
            'destination'     => $result->destination_email,
            'terminal_target' => $terminalTarget,
            'status'          => $status,
            'hops'            => $hops,
            'hop_count'       => count($hops),
            'cycle_detected'  => $cycleDetected,
            'warning'         => $warning,
            'mta_driver'      => 'postfix_mysql_map',
        ];
    }

    /**
     * Check if the Postfix mail transfer agent is running and operational
     */
    public function isPostfixOperational(): bool
    {
        if (PHP_OS_FAMILY !== 'Linux' || app()->environment('local', 'testing')) {
            return false;
        }

        $output = shell_exec('systemctl is-active postfix 2>/dev/null');
        return trim((string) $output) === 'active';
    }

    // ─── Catch-All Management & Inbound Recipient Routing (Step 32) ─────────────

    /**
     * Verify the actual Postfix SQL routing resolution for catch-all handling on a given domain.
     * Simulates the exact SQL queries executed by Postfix's mysql-virtual-aliases.cf,
     * mysql-virtual-catchall.cf, and mysql-virtual-mailboxes.cf lookup tables.
     */
    public function verifyCatchAllRouting(Domain $domain, ?string $probeLocalPart = null): array
    {
        $domainName = strtolower(trim($domain->domain_name));
        $policy = $domain->catch_all_policy ?? 'disabled';
        $probeLocal = strtolower(trim($probeLocalPart ?: 'catchall_probe_' . substr(md5($domainName), 0, 6)));
        $probeEmail = "{$probeLocal}@{$domainName}";

        // If domain is pending, inactive, or soft-deleted:
        if ($domain->status !== 'active' || $domain->trashed()) {
            return [
                'domain_name'     => $domainName,
                'policy'          => $policy,
                'is_routable'     => false,
                'action'          => 'reject',
                'probe_email'     => $probeEmail,
                'destination'     => null,
                'terminal_target' => null,
                'status'          => 'configured',
                'warning'         => 'Domain is not active or verified. Inbound messages cannot be routed.',
                'mta_driver'      => 'postfix_mysql_map',
            ];
        }

        // If policy is 'disabled' or 'reject':
        if (in_array($policy, ['disabled', 'reject'], true)) {
            return [
                'domain_name'     => $domainName,
                'policy'          => $policy,
                'is_routable'     => false,
                'action'          => 'reject',
                'probe_email'     => $probeEmail,
                'destination'     => null,
                'terminal_target' => null,
                'status'          => $this->isPostfixOperational() ? 'operational' : 'synchronized',
                'warning'         => null,
                'mta_driver'      => 'postfix_mysql_map',
            ];
        }

        // If policy is 'discard':
        if ($policy === 'discard') {
            $status = $this->isPostfixOperational() ? 'operational' : 'synchronized';
            return [
                'domain_name'     => $domainName,
                'policy'          => 'discard',
                'is_routable'     => true,
                'action'          => 'discard',
                'probe_email'     => $probeEmail,
                'destination'     => 'devnull@localhost',
                'terminal_target' => 'devnull@localhost',
                'status'          => $status,
                'warning'         => 'Unknown recipient emails on this domain will be accepted and silently discarded.',
                'mta_driver'      => 'postfix_mysql_map',
            ];
        }

        // Policy is 'mailbox':
        if ($policy === 'mailbox') {
            $mailbox = $domain->catchAllMailbox;

            if (! $mailbox || $mailbox->trashed()) {
                return [
                    'domain_name'     => $domainName,
                    'policy'          => 'mailbox',
                    'is_routable'     => false,
                    'action'          => 'reject',
                    'probe_email'     => $probeEmail,
                    'destination'     => null,
                    'terminal_target' => null,
                    'status'          => 'failed',
                    'warning'         => 'Designated catch-all mailbox record does not exist or has been deleted.',
                    'mta_driver'      => 'postfix_mysql_map',
                ];
            }

            if (! $mailbox->is_active) {
                return [
                    'domain_name'     => $domainName,
                    'policy'          => 'mailbox',
                    'is_routable'     => false,
                    'action'          => 'reject',
                    'probe_email'     => $probeEmail,
                    'destination'     => $mailbox->email,
                    'terminal_target' => $mailbox->email,
                    'status'          => 'failed',
                    'warning'         => 'Designated catch-all mailbox is currently inactive. Inbound mail will be rejected.',
                    'mta_driver'      => 'postfix_mysql_map',
                ];
            }

            // Verify the exact Postfix SQL query simulation:
            $match = DB::table('domains as d')
                ->leftJoin('mailboxes as m_dest', 'd.catch_all_mailbox_id', '=', 'm_dest.id')
                ->where('d.domain_name', $domainName)
                ->where('d.status', 'active')
                ->whereNull('d.deleted_at')
                ->where(function ($query) {
                    $query->where(function ($sub) {
                        $sub->where('d.catch_all_policy', 'mailbox')
                            ->whereNotNull('m_dest.id')
                            ->where('m_dest.is_active', 1)
                            ->whereNull('m_dest.deleted_at');
                    })->orWhere('d.catch_all_policy', 'discard');
                })
                ->whereNotExists(function ($sub) use ($probeEmail) {
                    $sub->select(DB::raw(1))
                        ->from('mailboxes as m_existing')
                        ->where('m_existing.email', $probeEmail)
                        ->where('m_existing.is_active', 1)
                        ->whereNull('m_existing.deleted_at');
                })
                ->whereNotExists(function ($sub) use ($probeEmail) {
                    $sub->select(DB::raw(1))
                        ->from('email_aliases as a_existing')
                        ->where('a_existing.source_email', $probeEmail)
                        ->where('a_existing.is_active', 1);
                })
                ->select(DB::raw("CASE WHEN d.catch_all_policy = 'mailbox' THEN m_dest.email WHEN d.catch_all_policy = 'discard' THEN 'devnull@localhost' ELSE NULL END as destination"))
                ->first();

            $status = $this->isPostfixOperational() ? 'operational' : 'synchronized';

            return [
                'domain_name'     => $domainName,
                'policy'          => 'mailbox',
                'is_routable'     => (bool) $match,
                'action'          => 'mailbox',
                'probe_email'     => $probeEmail,
                'destination'     => $match?->destination ?? $mailbox->email,
                'terminal_target' => $match?->destination ?? $mailbox->email,
                'status'          => $match ? $status : 'failed',
                'warning'         => $match ? null : 'Catch-all query resolution failed in SQL evaluation.',
                'mta_driver'      => 'postfix_mysql_map',
            ];
        }

        return [
            'domain_name'     => $domainName,
            'policy'          => $policy,
            'is_routable'     => false,
            'action'          => 'reject',
            'probe_email'     => $probeEmail,
            'destination'     => null,
            'terminal_target' => null,
            'status'          => 'unknown',
            'warning'         => 'Unsupported catch-all policy: ' . $policy,
            'mta_driver'      => 'postfix_mysql_map',
        ];
    }

    /**
     * Synchronize and verify domain catch-all rule
     */
    public function syncCatchAll(Domain $domain): array
    {
        $routing = $this->verifyCatchAllRouting($domain);

        Log::info("PostfixService: Catch-all synchronized", [
            'domain'      => $domain->domain_name,
            'policy'      => $domain->catch_all_policy,
            'mailbox_id'  => $domain->catch_all_mailbox_id,
            'status'      => $routing['status'],
            'is_routable' => $routing['is_routable'],
        ]);

        return $routing;
    }

    /**
     * Disable catch-all rule on domain
     */
    public function disableCatchAll(Domain $domain): bool
    {
        Log::info("PostfixService: Catch-all disabled", [
            'domain' => $domain->domain_name,
        ]);

        return true;
    }

    // ─── Private Helpers ────────────────────────────────────────────────────────

    private function runScript(string $scriptName, array $args = []): string
    {
        $scriptPath = $this->scriptsDir . '/' . $scriptName;

        if (! file_exists($scriptPath)) {
            if (app()->environment('local', 'testing') || PHP_OS_FAMILY !== 'Linux') {
                Log::info("PostfixService [SIMULATED]: Script {$scriptName} simulated in " . app()->environment() . " environment", [
                    'script' => $scriptName,
                    'args'   => $args,
                ]);
                return "SIMULATED_SUCCESS\nDKIM_TXT_VALUE=\"v=DKIM1; k=rsa; p=MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQC3SIMULATED\"";
            }
            throw new RuntimeException("Script not found: {$scriptPath}");
        }

        $argsStr = implode(' ', $args);
        $command = "sudo bash " . escapeshellarg($scriptPath) . " " . $argsStr . " 2>&1";

        exec($command, $output, $exitCode);

        $outputStr = implode("\n", $output);

        if ($exitCode !== 0) {
            Log::error("PostfixService script failed", [
                'script'   => $scriptName,
                'exit'     => $exitCode,
                'output'   => $outputStr,
            ]);
            throw new RuntimeException("Mail server operation failed: {$outputStr}");
        }

        return $outputStr;
    }

    private function validateDomainName(string $domain): void
    {
        if (! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\-]{0,61}[a-zA-Z0-9]\.[a-zA-Z]{2,}$/', $domain)) {
            throw new RuntimeException("Invalid domain name: {$domain}");
        }
    }

    private function validateEmail(string $email): void
    {
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException("Invalid email address: {$email}");
        }
    }


}
