<?php

namespace App\Services;

use App\Models\Domain;
use Illuminate\Support\Facades\Log;

/**
 * DnsVerificationService — Verifies DNS records for a domain
 * Checks MX, SPF, DKIM, and DMARC records against expected values.
 */
class DnsVerificationService
{
    private string $mailServerHost;

    public function __construct()
    {
        $this->mailServerHost = config('app.mail_server_host', 'mail.yourdomain.com');
    }

    /**
     * Run full DNS verification and update the domain record
     */
    public function verifyAll(Domain $domain): array
    {
        $results = [
            'mx'    => $this->verifyMx($domain->domain_name),
            'spf'   => $this->verifySpf($domain->domain_name),
            'dkim'  => $this->verifyDkim($domain->domain_name, $domain->dkim_selector ?? 'default'),
            'dmarc' => $this->verifyDmarc($domain->domain_name),
        ];

        // Update the domain record
        $domain->update([
            'mx_verified'       => $results['mx']['verified'],
            'spf_verified'      => $results['spf']['verified'],
            'dkim_verified'     => $results['dkim']['verified'],
            'dmarc_verified'    => $results['dmarc']['verified'],
            'last_dns_check_at' => now(),
        ]);

        // Activate domain if MX + SPF + DKIM are all verified
        if ($results['mx']['verified'] && $results['spf']['verified'] && $results['dkim']['verified']) {
            if ($domain->status !== 'active') {
                $domain->update([
                    'status'       => 'active',
                    'activated_at' => now(),
                ]);
            }
        }

        Log::info('DNS verification completed', [
            'domain'  => $domain->domain_name,
            'results' => $results,
        ]);

        return $results;
    }

    /**
     * Verify MX record points to our mail server
     */
    public function verifyMx(string $domain): array
    {
        $records = @dns_get_record($domain, DNS_MX);

        if (empty($records)) {
            return ['verified' => false, 'found' => null, 'expected' => $this->mailServerHost];
        }

        foreach ($records as $record) {
            if (str_contains(strtolower($record['target']), strtolower($this->mailServerHost))) {
                return ['verified' => true, 'found' => $record['target'], 'expected' => $this->mailServerHost];
            }
        }

        $found = array_column($records, 'target');
        return ['verified' => false, 'found' => implode(', ', $found), 'expected' => $this->mailServerHost];
    }

    /**
     * Verify SPF record includes our server
     */
    public function verifySpf(string $domain): array
    {
        $records = @dns_get_record($domain, DNS_TXT);

        foreach ($records as $record) {
            $txt = $record['txt'] ?? $record['entries'][0] ?? '';
            if (str_starts_with($txt, 'v=spf1')) {
                $hasMailServer = str_contains($txt, $this->mailServerHost)
                    || str_contains($txt, 'include:' . $this->mailServerHost)
                    || str_contains($txt, 'a:' . $this->mailServerHost);

                return [
                    'verified' => $hasMailServer,
                    'found'    => $txt,
                    'expected' => "v=spf1 mx a:{$this->mailServerHost} ~all",
                ];
            }
        }

        return [
            'verified' => false,
            'found'    => null,
            'expected' => "v=spf1 mx a:{$this->mailServerHost} ~all",
        ];
    }

    /**
     * Verify DKIM public key TXT record exists
     */
    public function verifyDkim(string $domain, string $selector = 'default'): array
    {
        $dkimHost = "{$selector}._domainkey.{$domain}";
        $records  = @dns_get_record($dkimHost, DNS_TXT);

        foreach ($records as $record) {
            $txt = $record['txt'] ?? $record['entries'][0] ?? '';
            if (str_contains($txt, 'v=DKIM1') || str_contains($txt, 'k=rsa')) {
                return ['verified' => true, 'found' => $txt, 'expected' => 'v=DKIM1; k=rsa; p=...'];
            }
        }

        return ['verified' => false, 'found' => null, 'expected' => "v=DKIM1; k=rsa; p=<public_key>"];
    }

    /**
     * Verify DMARC policy TXT record exists
     */
    public function verifyDmarc(string $domain): array
    {
        $dmarcHost = "_dmarc.{$domain}";
        $records   = @dns_get_record($dmarcHost, DNS_TXT);

        foreach ($records as $record) {
            $txt = $record['txt'] ?? $record['entries'][0] ?? '';
            if (str_starts_with($txt, 'v=DMARC1')) {
                return ['verified' => true, 'found' => $txt, 'expected' => 'v=DMARC1; p=quarantine;'];
            }
        }

        return [
            'verified' => false,
            'found'    => null,
            'expected' => "v=DMARC1; p=quarantine; rua=mailto:dmarc@{$domain}",
        ];
    }
}
