<?php

namespace App\Services;

use App\Models\Domain;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

/**
 * DnsVerificationService — Verifies DNS records for email domains.
 *
 * Checks MX, SPF, DKIM, DMARC, A, and CNAME records against expected mailserver configurations.
 * Supports mock resolver injection for deterministic testing, multi-chunk TXT normalization,
 * RFC 7208 duplicate SPF detection, and deliverability scorecard computation.
 */
class DnsVerificationService
{
    private string $mailServerHost;
    private ?string $mailServerIp;
    /** @var (callable(string, int): (array|false))|null */
    private $resolver = null;

    public const STATUS_VERIFIED       = 'VERIFIED';
    public const STATUS_MISMATCH       = 'MISMATCH';
    public const STATUS_MISSING        = 'MISSING';
    public const STATUS_UNRESOLVED     = 'UNRESOLVED';
    public const STATUS_CHECK_FAILED   = 'CHECK_FAILED';
    public const STATUS_NOT_CONFIGURED = 'NOT_CONFIGURED';
    public const STATUS_EXPECTED       = 'EXPECTED';

    public function __construct(?string $mailServerHost = null, ?callable $resolver = null)
    {
        $this->mailServerHost = $mailServerHost
            ?: Config::get('mail.mail_server_host')
            ?: Config::get('app.mail_server_host')
            ?: env('MAIL_SERVER_HOST')
            ?: env('SMTP_SERVER_HOST')
            ?: 'mail.mailsaas.com';

        $this->mailServerIp = Config::get('mail.mail_server_ip')
            ?: env('MAIL_SERVER_IP')
            ?: null;

        $this->resolver = $resolver;
    }

    /**
     * Inject a custom DNS resolver (callable) for automated testing or alternate nameserver queries.
     *
     * @param (callable(string, int): (array|false))|null $resolver
     */
    public function setResolver(?callable $resolver): self
    {
        $this->resolver = $resolver;
        return $this;
    }

    /**
     * Query DNS records using the configured resolver or system dns_get_record().
     *
     * @return array|false Returns array of records on success (empty array if no records exist), or false on resolver error.
     */
    public function queryDns(string $hostname, int $type): array|false
    {
        if ($this->resolver !== null) {
            return ($this->resolver)($hostname, $type);
        }

        return @dns_get_record($hostname, $type);
    }

    /**
     * Run full DNS verification and update the domain record.
     */
    public function verifyAll(Domain $domain): array
    {
        $results = [
            'mx'           => $this->verifyMx($domain->domain_name),
            'spf'          => $this->verifySpf($domain->domain_name),
            'dkim'         => $this->verifyDkim($domain->domain_name, $domain->dkim_selector ?? 'default', $domain->dkim_public_key),
            'dmarc'        => $this->verifyDmarc($domain->domain_name),
            'autodiscover' => $this->verifyCname("autodiscover.{$domain->domain_name}", $this->mailServerHost),
        ];

        // Update the domain record with latest verification results
        $domain->update([
            'mx_verified'       => $results['mx']['verified'],
            'spf_verified'      => $results['spf']['verified'],
            'dkim_verified'     => $results['dkim']['verified'],
            'dmarc_verified'    => $results['dmarc']['verified'],
            'last_dns_check_at' => now(),
        ]);

        // Activate domain if MX + SPF + DKIM are all verified and domain was pending
        if ($results['mx']['verified'] && $results['spf']['verified'] && $results['dkim']['verified']) {
            if ($domain->status !== 'active') {
                $domain->update([
                    'status'       => 'active',
                    'activated_at' => now(),
                ]);
            }
        }

        // Invalidate cached wizard data
        Cache::forget("domain:dns_wizard:{$domain->id}");

        Log::info('DNS verification completed', [
            'domain'  => $domain->domain_name,
            'results' => [
                'mx'    => $results['mx']['status'],
                'spf'   => $results['spf']['status'],
                'dkim'  => $results['dkim']['status'],
                'dmarc' => $results['dmarc']['status'],
            ],
        ]);

        return $results;
    }

    /**
     * Verify MX record points to the designated mail server.
     */
    public function verifyMx(string $domain): array
    {
        $expected = $this->mailServerHost;
        $records = $this->queryDns($domain, DNS_MX);

        if ($records === false) {
            return [
                'verified'        => false,
                'status'          => self::STATUS_CHECK_FAILED,
                'found'           => null,
                'records'         => [],
                'expected'        => "10 {$expected}",
                'troubleshooting' => 'DNS query timed out or resolver failed to reach authoritative nameservers.',
            ];
        }

        if (empty($records)) {
            return [
                'verified'        => false,
                'status'          => self::STATUS_MISSING,
                'found'           => null,
                'records'         => [],
                'expected'        => "10 {$expected}",
                'troubleshooting' => "No MX records found for {$domain}. Add an MX record pointing to '{$expected}' with Priority 10.",
            ];
        }

        $parsed = [];
        $hasTarget = false;
        $targetPriority = null;
        $minPriority = PHP_INT_MAX;

        foreach ($records as $record) {
            $target = strtolower(rtrim($record['target'] ?? '', '.'));
            $priority = (int) ($record['pri'] ?? 10);
            $parsed[] = ['target' => $target, 'priority' => $priority];

            if ($priority < $minPriority) {
                $minPriority = $priority;
            }

            if (str_contains($target, strtolower($expected))) {
                $hasTarget = true;
                $targetPriority = $priority;
            }
        }

        $foundSummary = implode(', ', array_map(fn($r) => "{$r['priority']} {$r['target']}", $parsed));

        if ($hasTarget) {
            if ($targetPriority > $minPriority) {
                return [
                    'verified'        => true,
                    'status'          => self::STATUS_VERIFIED,
                    'found'           => $foundSummary,
                    'records'         => $parsed,
                    'expected'        => "10 {$expected}",
                    'troubleshooting' => "Note: {$expected} is listed with priority {$targetPriority}, but another MX record has priority {$minPriority}. Higher priority (lower number) servers will receive mail first.",
                ];
            }

            return [
                'verified'        => true,
                'status'          => self::STATUS_VERIFIED,
                'found'           => $foundSummary,
                'records'         => $parsed,
                'expected'        => "10 {$expected}",
                'troubleshooting' => null,
            ];
        }

        return [
            'verified'        => false,
            'status'          => self::STATUS_MISMATCH,
            'found'           => $foundSummary,
            'records'         => $parsed,
            'expected'        => "10 {$expected}",
            'troubleshooting' => "MX records point to [{$foundSummary}] instead of '{$expected}'. Update your DNS MX records to point to '{$expected}'.",
        ];
    }

    /**
     * Verify SPF record authorizes the mail server and adheres to RFC 7208 syntax.
     */
    public function verifySpf(string $domain): array
    {
        $expected = "v=spf1 mx a:{$this->mailServerHost} ~all";
        $records = $this->queryDns($domain, DNS_TXT);

        if ($records === false) {
            return [
                'verified'        => false,
                'status'          => self::STATUS_CHECK_FAILED,
                'found'           => null,
                'records'         => [],
                'has_duplicate'   => false,
                'expected'        => $expected,
                'troubleshooting' => 'DNS query failed or timed out while checking SPF records.',
            ];
        }

        $spfRecords = [];
        foreach ($records as $record) {
            $txt = $this->extractTxtContent($record);
            if (str_starts_with(strtolower($txt), 'v=spf1')) {
                $spfRecords[] = $txt;
            }
        }

        if (empty($spfRecords)) {
            return [
                'verified'        => false,
                'status'          => self::STATUS_MISSING,
                'found'           => null,
                'records'         => [],
                'has_duplicate'   => false,
                'expected'        => $expected,
                'troubleshooting' => "No SPF TXT record found for {$domain}. Add a TXT record for '@' with value '{$expected}'.",
            ];
        }

        // RFC 7208 section 3.2: Domain MUST NOT have more than one SPF record
        if (count($spfRecords) > 1) {
            return [
                'verified'        => false,
                'status'          => self::STATUS_MISMATCH,
                'found'           => implode(' | ', $spfRecords),
                'records'         => $spfRecords,
                'has_duplicate'   => true,
                'expected'        => $expected,
                'troubleshooting' => 'Multiple SPF records detected (' . count($spfRecords) . ' found). Under RFC 7208, having multiple SPF records causes a permanent error (PermError) and deliverability failure. Merge all mechanisms into a single v=spf1 record.',
            ];
        }

        $spf = $spfRecords[0];
        $spfLower = strtolower($spf);

        // Check if our server is authorized
        $isAuthorized = str_contains($spfLower, strtolower($this->mailServerHost))
            || str_contains($spfLower, 'include:' . strtolower($this->mailServerHost))
            || str_contains($spfLower, 'a:' . strtolower($this->mailServerHost))
            || str_contains($spfLower, 'mx')
            || ($this->mailServerIp && str_contains($spfLower, 'ip4:' . $this->mailServerIp));

        if (! $isAuthorized) {
            return [
                'verified'        => false,
                'status'          => self::STATUS_MISMATCH,
                'found'           => $spf,
                'records'         => $spfRecords,
                'has_duplicate'   => false,
                'expected'        => $expected,
                'troubleshooting' => "SPF record found ('{$spf}'), but it does not authorize '{$this->mailServerHost}'. Add 'a:{$this->mailServerHost}' or 'mx' into your existing SPF record.",
            ];
        }

        return [
            'verified'        => true,
            'status'          => self::STATUS_VERIFIED,
            'found'           => $spf,
            'records'         => $spfRecords,
            'has_duplicate'   => false,
            'expected'        => $expected,
            'troubleshooting' => null,
        ];
    }

    /**
     * Verify DKIM public key TXT record exists at the designated selector.
     */
    public function verifyDkim(string $domain, string $selector = 'default', ?string $expectedPublicKey = null): array
    {
        $dkimHost = "{$selector}._domainkey.{$domain}";
        $expected = $expectedPublicKey
            ? (str_starts_with($expectedPublicKey, 'v=DKIM1') ? $expectedPublicKey : "v=DKIM1; k=rsa; p={$expectedPublicKey}")
            : "v=DKIM1; k=rsa; p=<public_key>";

        if (empty($expectedPublicKey) || $expectedPublicKey === 'Generating...') {
            return [
                'verified'        => false,
                'status'          => self::STATUS_NOT_CONFIGURED,
                'found'           => null,
                'expected'        => $expected,
                'troubleshooting' => 'DKIM key pair has not been generated on the server yet. It will be generated automatically when Postfix/OpenDKIM runs.',
            ];
        }

        $records = $this->queryDns($dkimHost, DNS_TXT);

        if ($records === false) {
            return [
                'verified'        => false,
                'status'          => self::STATUS_CHECK_FAILED,
                'found'           => null,
                'expected'        => $expected,
                'troubleshooting' => "DNS query failed or timed out while checking DKIM selector '{$dkimHost}'.",
            ];
        }

        if (empty($records)) {
            return [
                'verified'        => false,
                'status'          => self::STATUS_MISSING,
                'found'           => null,
                'expected'        => $expected,
                'troubleshooting' => "No DKIM TXT record found at '{$dkimHost}'. Add a TXT record with Host '{$selector}._domainkey' and the expected public key value.",
            ];
        }

        foreach ($records as $record) {
            $txt = $this->extractTxtContent($record);

            if (str_contains($txt, 'v=DKIM1') || str_contains($txt, 'k=rsa')) {
                // If expected public key is available, check for matching key payload
                if (! empty($expectedPublicKey)) {
                    $publishedKey = $this->extractDkimKeyPayload($txt);
                    $serverKey = $this->extractDkimKeyPayload($expectedPublicKey);

                    if ($publishedKey && $serverKey && $publishedKey !== $serverKey) {
                        return [
                            'verified'        => false,
                            'status'          => self::STATUS_MISMATCH,
                            'found'           => $txt,
                            'expected'        => $expected,
                            'troubleshooting' => "Published DKIM key at '{$dkimHost}' does not match this server's public key. Outbound signed emails will fail verification until the key is updated.",
                        ];
                    }
                }

                return [
                    'verified'        => true,
                    'status'          => self::STATUS_VERIFIED,
                    'found'           => $txt,
                    'expected'        => $expected,
                    'troubleshooting' => null,
                ];
            }
        }

        return [
            'verified'        => false,
            'status'          => self::STATUS_MISMATCH,
            'found'           => $this->extractTxtContent($records[0] ?? []),
            'expected'        => $expected,
            'troubleshooting' => "TXT record at '{$dkimHost}' exists but does not contain a valid DKIM1 specification.",
        ];
    }

    /**
     * Verify DMARC policy TXT record exists at _dmarc.<domain>.
     */
    public function verifyDmarc(string $domain): array
    {
        $dmarcHost = "_dmarc.{$domain}";
        $expected = "v=DMARC1; p=quarantine; rua=mailto:dmarc@{$domain}";
        $records = $this->queryDns($dmarcHost, DNS_TXT);

        if ($records === false) {
            return [
                'verified'        => false,
                'status'          => self::STATUS_CHECK_FAILED,
                'found'           => null,
                'policy'          => null,
                'expected'        => $expected,
                'troubleshooting' => "DNS query failed or timed out while querying '{$dmarcHost}'.",
            ];
        }

        if (empty($records)) {
            return [
                'verified'        => false,
                'status'          => self::STATUS_MISSING,
                'found'           => null,
                'policy'          => null,
                'expected'        => $expected,
                'troubleshooting' => "No DMARC record found at '{$dmarcHost}'. Add a TXT record with Host '_dmarc' and Value '{$expected}' to protect your domain from spoofing.",
            ];
        }

        foreach ($records as $record) {
            $txt = $this->extractTxtContent($record);
            if (str_starts_with(strtolower($txt), 'v=dmarc1')) {
                // Extract policy
                $policy = 'none';
                if (preg_match('/p\s*=\s*(none|quarantine|reject)/i', $txt, $matches)) {
                    $policy = strtolower($matches[1]);
                }

                return [
                    'verified'        => true,
                    'status'          => self::STATUS_VERIFIED,
                    'found'           => $txt,
                    'policy'          => $policy,
                    'expected'        => $expected,
                    'troubleshooting' => null,
                ];
            }
        }

        return [
            'verified'        => false,
            'status'          => self::STATUS_MISMATCH,
            'found'           => $this->extractTxtContent($records[0] ?? []),
            'policy'          => null,
            'expected'        => $expected,
            'troubleshooting' => "Record at '{$dmarcHost}' does not start with 'v=DMARC1'. Ensure syntax matches standard DMARC format.",
        ];
    }

    /**
     * Verify A record for a given hostname.
     */
    public function verifyA(string $hostname, ?string $expectedIp = null): array
    {
        $records = $this->queryDns($hostname, DNS_A);

        if ($records === false) {
            return [
                'verified'        => false,
                'status'          => self::STATUS_CHECK_FAILED,
                'found'           => null,
                'expected'        => $expectedIp ?? 'Server IP',
                'troubleshooting' => "DNS query failed while checking A record for '{$hostname}'.",
            ];
        }

        if (empty($records)) {
            return [
                'verified'        => false,
                'status'          => self::STATUS_MISSING,
                'found'           => null,
                'expected'        => $expectedIp ?? 'Server IP',
                'troubleshooting' => "No A record found for '{$hostname}'.",
            ];
        }

        $ips = array_column($records, 'ip');
        $foundStr = implode(', ', $ips);

        if ($expectedIp) {
            $match = in_array($expectedIp, $ips, true);
            return [
                'verified'        => $match,
                'status'          => $match ? self::STATUS_VERIFIED : self::STATUS_MISMATCH,
                'found'           => $foundStr,
                'expected'        => $expectedIp,
                'troubleshooting' => $match ? null : "A record resolves to [{$foundStr}] instead of {$expectedIp}.",
            ];
        }

        return [
            'verified'        => true,
            'status'          => self::STATUS_VERIFIED,
            'found'           => $foundStr,
            'expected'        => 'Server IP',
            'troubleshooting' => null,
        ];
    }

    /**
     * Verify CNAME record points to the expected canonical target.
     */
    public function verifyCname(string $hostname, string $expectedTarget): array
    {
        $records = $this->queryDns($hostname, DNS_CNAME);
        $expected = strtolower(rtrim($expectedTarget, '.'));

        if ($records === false) {
            return [
                'verified'        => false,
                'status'          => self::STATUS_CHECK_FAILED,
                'found'           => null,
                'expected'        => $expected,
                'troubleshooting' => "DNS query timed out while checking CNAME for '{$hostname}'.",
            ];
        }

        if (empty($records)) {
            return [
                'verified'        => false,
                'status'          => self::STATUS_MISSING,
                'found'           => null,
                'expected'        => $expected,
                'troubleshooting' => "Optional: Add a CNAME record with Host '{$hostname}' pointing to '{$expected}'.",
            ];
        }

        $target = strtolower(rtrim($records[0]['target'] ?? '', '.'));

        if ($target === $expected || str_contains($target, $expected)) {
            return [
                'verified'        => true,
                'status'          => self::STATUS_VERIFIED,
                'found'           => $target,
                'expected'        => $expected,
                'troubleshooting' => null,
            ];
        }

        return [
            'verified'        => false,
            'status'          => self::STATUS_MISMATCH,
            'found'           => $target,
            'expected'        => $expected,
            'troubleshooting' => "CNAME points to '{$target}' instead of '{$expected}'.",
        ];
    }

    /**
     * Get the full DNS Wizard setup and deliverability diagnostics for a domain.
     */
    public function getDnsWizardData(Domain $domain, bool $refresh = false): array
    {
        $cacheKey = "domain:dns_wizard:{$domain->id}";

        if (! $refresh && Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        // Perform live checks
        $mxCheck = $this->verifyMx($domain->domain_name);
        $spfCheck = $this->verifySpf($domain->domain_name);
        $dkimCheck = $this->verifyDkim($domain->domain_name, $domain->dkim_selector ?? 'default', $domain->dkim_public_key);
        $dmarcCheck = $this->verifyDmarc($domain->domain_name);
        $autodiscoverCheck = $this->verifyCname("autodiscover.{$domain->domain_name}", $this->mailServerHost);
        $autoconfigCheck = $this->verifyCname("autoconfig.{$domain->domain_name}", $this->mailServerHost);

        $dkimSelector = $domain->dkim_selector ?? 'default';
        $dkimValue = $domain->dkim_public_key
            ? (str_starts_with($domain->dkim_public_key, 'v=DKIM1') ? $domain->dkim_public_key : "v=DKIM1; k=rsa; p={$domain->dkim_public_key}")
            : 'v=DKIM1; k=rsa; p=<pending_generation>';

        $lastChecked = $domain->last_dns_check_at ? $domain->last_dns_check_at->toIso8601String() : null;

        // Structured record list
        $records = [
            [
                'id'                  => 'mx',
                'type'                => 'MX',
                'host'                => '@',
                'value'               => "10 {$this->mailServerHost}",
                'expected_value'      => "10 {$this->mailServerHost}",
                'priority'            => 10,
                'purpose'             => 'Inbound Mail Routing',
                'purpose_description' => "Directs all incoming email sent to @{$domain->domain_name} to the EmailSaaS mail server.",
                'status'              => $mxCheck['status'],
                'verified'            => $mxCheck['verified'],
                'actual_value'        => $mxCheck['found'],
                'is_required'         => true,
                'last_checked_at'     => $lastChecked,
                'troubleshooting'     => $mxCheck['troubleshooting'],
            ],
            [
                'id'                  => 'spf',
                'type'                => 'TXT',
                'host'                => '@',
                'value'               => "v=spf1 mx a:{$this->mailServerHost} ~all",
                'expected_value'      => "v=spf1 mx a:{$this->mailServerHost} ~all",
                'priority'            => null,
                'purpose'             => 'SPF Sender Authorization',
                'purpose_description' => "Authorizes the EmailSaaS mail server to send emails on behalf of @{$domain->domain_name}.",
                'status'              => $spfCheck['status'],
                'verified'            => $spfCheck['verified'],
                'actual_value'        => $spfCheck['found'],
                'is_required'         => true,
                'last_checked_at'     => $lastChecked,
                'troubleshooting'     => $spfCheck['troubleshooting'],
            ],
            [
                'id'                  => 'dkim',
                'type'                => 'TXT',
                'host'                => "{$dkimSelector}._domainkey",
                'value'               => $dkimValue,
                'expected_value'      => $dkimValue,
                'priority'            => null,
                'purpose'             => 'DKIM Cryptographic Signature',
                'purpose_description' => "Cryptographically signs outbound messages to prove authenticity and prevent email tampering.",
                'status'              => $dkimCheck['status'],
                'verified'            => $dkimCheck['verified'],
                'actual_value'        => $dkimCheck['found'],
                'is_required'         => true,
                'last_checked_at'     => $lastChecked,
                'troubleshooting'     => $dkimCheck['troubleshooting'],
            ],
            [
                'id'                  => 'dmarc',
                'type'                => 'TXT',
                'host'                => '_dmarc',
                'value'               => "v=DMARC1; p=quarantine; rua=mailto:dmarc@{$domain->domain_name}",
                'expected_value'      => "v=DMARC1; p=quarantine; rua=mailto:dmarc@{$domain->domain_name}",
                'priority'            => null,
                'purpose'             => 'DMARC Policy & Reporting',
                'purpose_description' => "Specifies how recipient servers should handle SPF/DKIM failures and where to send forensic reports.",
                'status'              => $dmarcCheck['status'],
                'verified'            => $dmarcCheck['verified'],
                'actual_value'        => $dmarcCheck['found'],
                'is_required'         => false,
                'last_checked_at'     => $lastChecked,
                'troubleshooting'     => $dmarcCheck['troubleshooting'],
            ],
            [
                'id'                  => 'autodiscover',
                'type'                => 'CNAME',
                'host'                => 'autodiscover',
                'value'               => $this->mailServerHost,
                'expected_value'      => $this->mailServerHost,
                'priority'            => null,
                'purpose'             => 'Mail Client Auto-Configuration (Outlook)',
                'purpose_description' => 'Allows Microsoft Outlook and Windows mail clients to automatically discover incoming and outgoing server parameters.',
                'status'              => $autodiscoverCheck['status'],
                'verified'            => $autodiscoverCheck['verified'],
                'actual_value'        => $autodiscoverCheck['found'],
                'is_required'         => false,
                'last_checked_at'     => $lastChecked,
                'troubleshooting'     => $autodiscoverCheck['troubleshooting'],
            ],
            [
                'id'                  => 'autoconfig',
                'type'                => 'CNAME',
                'host'                => 'autoconfig',
                'value'               => $this->mailServerHost,
                'expected_value'      => $this->mailServerHost,
                'priority'            => null,
                'purpose'             => 'Mail Client Auto-Configuration (Thunderbird)',
                'purpose_description' => 'Allows Mozilla Thunderbird and Linux mail clients to automatically discover IMAP and SMTP settings.',
                'status'              => $autoconfigCheck['status'],
                'verified'            => $autoconfigCheck['verified'],
                'actual_value'        => $autoconfigCheck['found'],
                'is_required'         => false,
                'last_checked_at'     => $lastChecked,
                'troubleshooting'     => $autoconfigCheck['troubleshooting'],
            ],
        ];

        // Deliverability Scorecard
        $score = $this->calculateDeliverabilityScore([
            'mx'    => $mxCheck['verified'],
            'spf'   => $spfCheck['verified'],
            'dkim'  => $dkimCheck['verified'],
            'dmarc' => $dmarcCheck['verified'],
        ]);

        // Inbound Routing Diagnostics
        $inboundRouting = [
            'status'      => $mxCheck['verified'] ? 'operational' : ($mxCheck['status'] === self::STATUS_MISMATCH ? 'misconfigured' : 'unverified'),
            'mx_target'   => $this->mailServerHost,
            'mx_priority' => 10,
            'details'     => $mxCheck['verified']
                ? "Inbound MX routes to {$this->mailServerHost} with priority 10. Receiving mailboxes can receive messages."
                : ($mxCheck['found'] ? "MX points to foreign targets ({$mxCheck['found']})." : "No MX records found. Inbound messages cannot reach this domain."),
        ];

        // Outbound Authentication Diagnostics
        $outboundAuth = [
            'spf_status'   => $spfCheck['status'],
            'dkim_status'  => $dkimCheck['status'],
            'dmarc_status' => $dmarcCheck['status'],
            'spf_policy'   => $spfCheck['verified'] ? '~all' : null,
            'dmarc_policy' => $dmarcCheck['policy'] ?? null,
            'details'      => ($spfCheck['verified'] && $dkimCheck['verified'])
                ? 'Outbound email is protected with SPF authorization and DKIM cryptographic signatures.'
                : 'Outbound emails risk being marked as spam or rejected until SPF and DKIM records are verified.',
        ];

        // Infrastructure Status (Multi-Layer Separation)
        $isPostfixActive = app(PostfixService::class)->isPostfixOperational();
        $allVerified = $mxCheck['verified'] && $spfCheck['verified'] && $dkimCheck['verified'];

        $infrastructureStatus = [
            'dns_publication'    => $allVerified ? 'published' : ($mxCheck['verified'] || $spfCheck['verified'] ? 'partial' : 'missing'),
            'application_config' => $domain->status,
            'mail_server_mta'    => $isPostfixActive ? 'operational' : (PHP_OS_FAMILY === 'Linux' ? 'offline' : 'local_development'),
            'mta_driver'         => 'postfix_dovecot_linux',
            'mta_note'           => $isPostfixActive
                ? 'Postfix MTA and Dovecot services are active and operational on this server.'
                : 'Local Windows development environment — Linux Postfix/Dovecot MTA daemons run on dedicated staging/production Linux VPS.',
        ];

        $recommendations = $this->buildRecommendations($records, $domain);

        $payload = [
            'domain' => [
                'id'                => $domain->id,
                'domain_name'       => $domain->domain_name,
                'status'            => $domain->status,
                'is_verified'       => $domain->status === 'active',
                'activated_at'      => $domain->activated_at ? $domain->activated_at->toIso8601String() : null,
                'last_dns_check_at' => $lastChecked,
            ],
            'deliverability_score'    => $score,
            'records'                 => $records,
            'inbound_routing'         => $inboundRouting,
            'outbound_authentication' => $outboundAuth,
            'infrastructure_status'   => $infrastructureStatus,
            'recommendations'         => $recommendations,
        ];

        Cache::put($cacheKey, $payload, 60);

        return $payload;
    }

    /**
     * Compute deliverability score and status label based on weighted authentication factors.
     */
    private function calculateDeliverabilityScore(array $verifications): array
    {
        $score = 0;
        if (! empty($verifications['mx']))    $score += 40;
        if (! empty($verifications['spf']))   $score += 25;
        if (! empty($verifications['dkim']))  $score += 25;
        if (! empty($verifications['dmarc'])) $score += 10;

        if ($score === 100) {
            return [
                'score_percent' => 100,
                'status'        => 'ready',
                'status_label'  => 'Production Ready',
                'summary'       => 'Domain is fully authenticated and ready for reliable inbound delivery and high-inbox deliverability.',
            ];
        }

        if ($score >= 65) {
            return [
                'score_percent' => $score,
                'status'        => 'needs_attention',
                'status_label'  => 'Partially Configured',
                'summary'       => 'Essential email routing is functional, but missing authentication records (DKIM/DMARC) increase spam risks.',
            ];
        }

        return [
            'score_percent' => $score,
            'status'        => 'critical',
            'status_label'  => 'Incomplete Setup',
            'summary'       => 'Critical records (MX or SPF) are missing. Inbound mail delivery is not functional until DNS records propagate.',
        ];
    }

    /**
     * Generate step-by-step actionable recommendations based on failed checks.
     */
    private function buildRecommendations(array $records, Domain $domain): array
    {
        $recs = [];

        foreach ($records as $r) {
            if ($r['is_required'] && ! $r['verified']) {
                if ($r['id'] === 'mx') {
                    $recs[] = "Add the MX record pointing to '{$this->mailServerHost}' (Priority 10) to enable incoming email delivery.";
                } elseif ($r['id'] === 'spf') {
                    $recs[] = "Add the SPF TXT record on '@' with value 'v=spf1 mx a:{$this->mailServerHost} ~all' to authorize outbound emails.";
                } elseif ($r['id'] === 'dkim') {
                    if ($r['status'] === self::STATUS_NOT_CONFIGURED) {
                        $recs[] = "DKIM key is generating on the mail server. Once ready, publish the DKIM TXT record at '{$r['host']}'.";
                    } else {
                        $recs[] = "Publish the DKIM TXT record at '{$r['host']}' to enable cryptographic signature verification.";
                    }
                }
            }
        }

        if (! empty($recs)) {
            return $recs;
        }

        $dmarc = collect($records)->firstWhere('id', 'dmarc');
        if ($dmarc && ! $dmarc['verified']) {
            $recs[] = "Recommended: Add a DMARC record at '_dmarc' to protect your domain from spoofing and monitor deliverability reports.";
        }

        return $recs;
    }

    /**
     * Extract concatenated text content from a PHP dns_get_record TXT array.
     * Correctly handles multiple chunks (entries) for 2048-bit DKIM keys.
     */
    private function extractTxtContent(array $record): string
    {
        if (isset($record['entries']) && is_array($record['entries'])) {
            $txt = implode('', $record['entries']);
        } else {
            $txt = $record['txt'] ?? '';
        }

        return trim(trim($txt), '"\'');
    }

    /**
     * Extract the raw public key payload from a DKIM TXT string or raw key parameter.
     */
    private function extractDkimKeyPayload(string $dkimTxt): ?string
    {
        if (preg_match('/p\s*=\s*([^;]+)/', $dkimTxt, $matches)) {
            // Strip any internal whitespace/newlines that DNS providers might insert
            return preg_replace('/\s+/', '', $matches[1]);
        }

        // If the string is already a raw key without p= tag
        $clean = preg_replace('/\s+/', '', $dkimTxt);
        return ! empty($clean) ? $clean : null;
    }
}
