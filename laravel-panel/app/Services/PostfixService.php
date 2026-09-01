<?php

namespace App\Services;

use App\Models\Domain;
use App\Models\Mailbox;
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
            escapeshellarg((string) $domain->user_id),
        ]);

        Log::info("PostfixService: Domain added", [
            'domain' => $domain->domain_name,
            'output' => $output,
        ]);

        // Extract DKIM public key from output if present
        if (preg_match('/DKIM_TXT_VALUE=(.+)/', $output, $matches)) {
            $domain->update([
                'dkim_public_key' => trim($matches[1]),
                'server_domain_id' => $this->getServerDomainId($domain->domain_name),
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
    public function addMailbox(Mailbox $mailbox, string $password): bool
    {
        $this->validateEmail($mailbox->email);

        $output = $this->runScript('add_mailbox.sh', [
            escapeshellarg($mailbox->email),
            escapeshellarg($password),
            escapeshellarg((string) $mailbox->domain_id),
            escapeshellarg((string) $mailbox->quota_mb),
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

    /**
     * Change mailbox password
     */
    public function changeMailboxPassword(Mailbox $mailbox, string $newPassword): bool
    {
        // Re-run add_mailbox which does ON DUPLICATE KEY UPDATE
        return $this->addMailbox($mailbox, $newPassword);
    }

    // ─── Private Helpers ────────────────────────────────────────────────────────

    private function runScript(string $scriptName, array $args = []): string
    {
        $scriptPath = $this->scriptsDir . '/' . $scriptName;

        if (! file_exists($scriptPath)) {
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

    private function getServerDomainId(string $domainName): ?int
    {
        // Query the mail DB directly to get the auto-increment ID
        $result = \DB::connection('mail_db')
            ->table('virtual_domains')
            ->where('name', $domainName)
            ->value('id');
        return $result;
    }
}
