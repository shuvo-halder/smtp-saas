<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Domain;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

/**
 * DnsVerificationService — Verifies DNS records for email domains.
 *
 * Checks MX, SPF, DKIM, DMARC, A, and CNAME records against expected mailserver configurations.
 * Supports mock resolver injection for deterministic testing, multi-chunk TXT normalization,
 * RFC 7208 duplicate SPF detection, deep syntax analysis, DMARC policy guidance,
 * deliverability scorecard computation, and DNS drift detection.
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
                'warnings'        => [],
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
                'warnings'        => [],
                'expected'        => "10 {$expected}",
                'troubleshooting' => "No MX records found for {$domain}. Add an MX record pointing to '{$expected}' with Priority 10.",
            ];
        }

        $parsed = [];
        $warnings = [];
        $hasTarget = false;
        $targetPriority = null;
        $minPriority = PHP_INT_MAX;

        foreach ($records as $record) {
            $rawTarget = rtrim($record['target'] ?? '', '.');
            $target = strtolower($rawTarget);
            $priority = (int) ($record['pri'] ?? 10);
            $parsed[] = ['target' => $target, 'priority' => $priority];

            // RFC 5321 section 5.1 Prohibition against bare IP address MX targets
            if (filter_var($rawTarget, FILTER_VALIDATE_IP)) {
                $warnings[] = "MX target '{$rawTarget}' is an IP address. RFC 5321 section 5.1 prohibits MX records from pointing to bare IP addresses; it must point to a valid domain name (FQDN).";
            }

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
                $warnings[] = "Priority conflict: {$expected} has priority {$targetPriority}, but another MX server has priority {$minPriority}. Inbound mail may route to other servers first.";
                return [
                    'verified'        => true,
                    'status'          => self::STATUS_VERIFIED,
                    'found'           => $foundSummary,
                    'records'         => $parsed,
                    'warnings'        => $warnings,
                    'expected'        => "10 {$expected}",
                    'troubleshooting' => "Note: {$expected} is listed with priority {$targetPriority}, but another MX record has priority {$minPriority}. Higher priority (lower number) servers will receive mail first.",
                ];
            }

            return [
                'verified'        => true,
                'status'          => self::STATUS_VERIFIED,
                'found'           => $foundSummary,
                'records'         => $parsed,
                'warnings'        => $warnings,
                'expected'        => "10 {$expected}",
                'troubleshooting' => null,
            ];
        }

        return [
            'verified'        => false,
            'status'          => self::STATUS_MISMATCH,
            'found'           => $foundSummary,
            'records'         => $parsed,
            'warnings'        => $warnings,
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
                'syntax_valid'    => false,
                'warnings'        => [],
                'syntax_errors'   => ['DNS lookup failed or timed out.'],
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
                'syntax_valid'    => false,
                'warnings'        => [],
                'syntax_errors'   => ['No SPF record found.'],
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
                'syntax_valid'    => false,
                'warnings'        => ['Multiple SPF records detected.'],
                'syntax_errors'   => ['Multiple SPF records violate RFC 7208 section 3.2 resulting in PermError.'],
                'expected'        => $expected,
                'troubleshooting' => 'Multiple SPF records detected (' . count($spfRecords) . ' found). Under RFC 7208, having multiple SPF records causes a permanent error (PermError) and deliverability failure. Merge all mechanisms into a single v=spf1 record.',
            ];
        }

        $spf = $spfRecords[0];
        $syntaxAnalysis = $this->analyzeSpfSyntax($spf, $domain);

        if (! $syntaxAnalysis['syntax_valid']) {
            $firstErr = $syntaxAnalysis['syntax_errors'][0] ?? 'SPF syntax error';
            return [
                'verified'        => false,
                'status'          => self::STATUS_MISMATCH,
                'found'           => $spf,
                'records'         => $spfRecords,
                'has_duplicate'   => false,
                'syntax_valid'    => false,
                'warnings'        => $syntaxAnalysis['warnings'],
                'syntax_errors'   => $syntaxAnalysis['syntax_errors'],
                'expected'        => $expected,
                'troubleshooting' => "Malformed SPF record: {$firstErr}. Correct your SPF record to follow RFC 7208 standard format.",
            ];
        }

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
                'syntax_valid'    => true,
                'warnings'        => $syntaxAnalysis['warnings'],
                'syntax_errors'   => [],
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
            'syntax_valid'    => true,
            'warnings'        => $syntaxAnalysis['warnings'],
            'syntax_errors'   => [],
            'lookup_count'    => $syntaxAnalysis['lookup_count'],
            'expected'        => $expected,
            'troubleshooting' => null,
        ];
    }

    /**
     * Analyze RFC 7208 SPF record syntax, mechanisms, qualifiers, and DNS lookup counts.
     */
    private function analyzeSpfSyntax(string $spf, string $domain): array
    {
        $tokens = preg_split('/\s+/', trim($spf));
        $prefix = array_shift($tokens);

        $errors = [];
        $warnings = [];
        $lookupCount = 0;
        $allCount = 0;
        $allQualifier = null;

        if (strtolower($prefix) !== 'v=spf1') {
            $errors[] = "Record does not begin with exact 'v=spf1' identifier.";
        }

        foreach ($tokens as $token) {
            $tokenLower = strtolower($token);

            // Modifiers
            if (str_starts_with($tokenLower, 'redirect=')) {
                $lookupCount++;
                $target = substr($tokenLower, 9);
                if (empty($target)) {
                    $errors[] = "Modifier 'redirect=' has an empty target domain.";
                }
                continue;
            }

            if (str_starts_with($tokenLower, 'exp=')) {
                $lookupCount++;
                continue;
            }

            // Mechanisms with optional qualifiers (+, -, ~, ?)
            $qualifier = '+';
            $mechanism = $tokenLower;
            if (in_array($tokenLower[0] ?? '', ['+', '-', '~', '?'], true)) {
                $qualifier = $tokenLower[0];
                $mechanism = substr($tokenLower, 1);
            }

            if ($mechanism === 'all') {
                $allCount++;
                $allQualifier = $qualifier;
                if ($qualifier === '+') {
                    $warnings[] = "Insecure SPF configuration: '+all' allows ANY sender on the internet to send mail on behalf of your domain without rejection.";
                }
                continue;
            }

            if ($mechanism === 'mx' || str_starts_with($mechanism, 'mx:') || str_starts_with($mechanism, 'mx/')) {
                $lookupCount++;
                continue;
            }

            if ($mechanism === 'a' || str_starts_with($mechanism, 'a:') || str_starts_with($mechanism, 'a/')) {
                $lookupCount++;
                continue;
            }

            if (str_starts_with($mechanism, 'include:')) {
                $lookupCount++;
                $incDomain = substr($mechanism, 8);
                if (empty($incDomain)) {
                    $errors[] = "Mechanism 'include:' has an empty domain target.";
                }
                continue;
            }

            if (str_starts_with($mechanism, 'ip4:')) {
                $ipPart = substr($mechanism, 4);
                $ipOnly = explode('/', $ipPart)[0];
                if (! filter_var($ipOnly, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $errors[] = "Mechanism 'ip4:{$ipPart}' contains an invalid IPv4 address.";
                }
                continue;
            }

            if (str_starts_with($mechanism, 'ip6:')) {
                $ipPart = substr($mechanism, 4);
                $ipOnly = explode('/', $ipPart)[0];
                if (! filter_var($ipOnly, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    $errors[] = "Mechanism 'ip6:{$ipPart}' contains an invalid IPv6 address.";
                }
                continue;
            }

            if (str_starts_with($mechanism, 'exists:')) {
                $lookupCount++;
                continue;
            }

            if ($mechanism === 'ptr' || str_starts_with($mechanism, 'ptr:')) {
                $lookupCount++;
                $warnings[] = "Mechanism 'ptr' is deprecated in RFC 7208 section 5.5 and should be replaced with 'a' or 'ip4'.";
                continue;
            }

            // Unknown mechanism
            $errors[] = "Unrecognized SPF mechanism '{$token}'.";
        }

        if ($allCount > 1) {
            $warnings[] = "Multiple 'all' mechanisms detected ({$allCount} found). RFC 7208 specifies that mechanisms following the first 'all' are ignored.";
        }

        if ($allCount === 0) {
            $warnings[] = "No default 'all' fallback mechanism found (e.g. '~all' or '-all'). Senders not matched may produce unexpected Neutral outcomes.";
        }

        if ($lookupCount > 10) {
            $warnings[] = "SPF record requires approximately {$lookupCount} DNS lookups, exceeding the RFC 7208 limit of 10 lookups. MTAs may fail verification with PermError.";
        }

        return [
            'syntax_valid'  => empty($errors),
            'syntax_errors' => $errors,
            'warnings'      => $warnings,
            'lookup_count'  => $lookupCount,
            'all_qualifier' => $allQualifier,
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
                'selector'        => $selector,
                'key_length_bits' => null,
                'syntax_valid'    => false,
                'warnings'        => [],
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
                'selector'        => $selector,
                'key_length_bits' => null,
                'syntax_valid'    => false,
                'warnings'        => [],
                'expected'        => $expected,
                'troubleshooting' => "DNS query failed or timed out while checking DKIM selector '{$dkimHost}'.",
            ];
        }

        if (empty($records)) {
            return [
                'verified'        => false,
                'status'          => self::STATUS_MISSING,
                'found'           => null,
                'selector'        => $selector,
                'key_length_bits' => null,
                'syntax_valid'    => false,
                'warnings'        => [],
                'expected'        => $expected,
                'troubleshooting' => "No DKIM TXT record found at '{$dkimHost}'. Add a TXT record with Host '{$selector}._domainkey' and the expected public key value.",
            ];
        }

        foreach ($records as $record) {
            $txt = $this->extractTxtContent($record);

            if (str_contains($txt, 'v=DKIM1') || str_contains($txt, 'k=rsa') || str_contains($txt, 'p=')) {
                $warnings = [];

                // Check for revoked key (p= tag with empty value)
                if (preg_match('/p\s*=\s*(?:;|$)/', $txt)) {
                    return [
                        'verified'        => false,
                        'status'          => self::STATUS_MISMATCH,
                        'found'           => $txt,
                        'selector'        => $selector,
                        'is_revoked'      => true,
                        'key_length_bits' => 0,
                        'syntax_valid'    => false,
                        'warnings'        => ['DKIM public key is explicitly revoked (empty p= value) under RFC 6376.'],
                        'expected'        => $expected,
                        'troubleshooting' => "The published DKIM key at '{$dkimHost}' has an empty 'p=' value, which signals key revocation under RFC 6376. Replace with your active public key.",
                    ];
                }

                $publishedKey = $this->extractDkimKeyPayload($txt);
                $serverKey = $this->extractDkimKeyPayload($expectedPublicKey);

                // If expected public key is available, check for matching key payload first
                if (! empty($serverKey) && ! empty($publishedKey)) {
                    if ($publishedKey !== $serverKey) {
                        return [
                            'verified'        => false,
                            'status'          => self::STATUS_MISMATCH,
                            'found'           => $txt,
                            'selector'        => $selector,
                            'key_length_bits' => null,
                            'syntax_valid'    => true,
                            'warnings'        => $warnings,
                            'expected'        => $expected,
                            'troubleshooting' => "Published DKIM key at '{$dkimHost}' does not match this server's public key. Outbound signed emails will fail verification until the key is updated.",
                        ];
                    }
                }

                // Estimated key length
                $keyLengthBits = null;
                if ($publishedKey) {
                    $decoded = base64_decode($publishedKey, true);
                    if ($decoded !== false) {
                        $rawLen = strlen($decoded);
                        // Standard RSA SubjectPublicKeyInfo ASN.1 wrapper is ~24-38 bytes overhead
                        if ($rawLen >= 500) {
                            $keyLengthBits = 4096;
                        } elseif ($rawLen >= 250) {
                            $keyLengthBits = 2048;
                        } elseif ($rawLen >= 120) {
                            $keyLengthBits = 1024;
                        }
                    } else {
                        // Warn if non-standard characters exist in payload
                        if (! preg_match('/^[a-zA-Z0-9+\/=_.]+$/', $publishedKey)) {
                            $warnings[] = 'DKIM public key contains non-standard characters.';
                        }
                    }
                }

                if ($keyLengthBits && $keyLengthBits < 2048) {
                    $warnings[] = "Key size is {$keyLengthBits}-bit. RFC 6376 recommends minimum 2048-bit RSA keys for modern deliverability.";
                }

                return [
                    'verified'        => true,
                    'status'          => self::STATUS_VERIFIED,
                    'found'           => $txt,
                    'selector'        => $selector,
                    'key_length_bits' => $keyLengthBits,
                    'syntax_valid'    => true,
                    'warnings'        => $warnings,
                    'expected'        => $expected,
                    'troubleshooting' => null,
                ];
            }
        }

        return [
            'verified'        => false,
            'status'          => self::STATUS_MISMATCH,
            'found'           => $this->extractTxtContent($records[0] ?? []),
            'selector'        => $selector,
            'syntax_valid'    => false,
            'warnings'        => ['Record does not contain standard DKIM tags.'],
            'expected'        => $expected,
            'troubleshooting' => "TXT record at '{$dkimHost}' exists but does not contain a valid DKIM1 specification.",
        ];
    }

    /**
     * Verify DMARC policy TXT record exists at _dmarc.<domain> and adheres to RFC 7489.
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
                'has_duplicate'   => false,
                'syntax_valid'    => false,
                'warnings'        => [],
                'explanation'     => null,
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
                'has_duplicate'   => false,
                'syntax_valid'    => false,
                'warnings'        => [],
                'explanation'     => 'No DMARC policy published. Domain has no protection against domain impersonation.',
                'expected'        => $expected,
                'troubleshooting' => "No DMARC record found at '{$dmarcHost}'. Add a TXT record with Host '_dmarc' and Value '{$expected}' to protect your domain from spoofing.",
            ];
        }

        $dmarcRecords = [];
        foreach ($records as $record) {
            $txt = $this->extractTxtContent($record);
            if (str_starts_with(strtolower($txt), 'v=dmarc1')) {
                $dmarcRecords[] = $txt;
            }
        }

        // RFC 7489 section 6.6.3: If more than one DMARC record is returned, MTAs MUST ignore all of them.
        if (count($dmarcRecords) > 1) {
            return [
                'verified'        => false,
                'status'          => self::STATUS_MISMATCH,
                'found'           => implode(' | ', $dmarcRecords),
                'policy'          => null,
                'has_duplicate'   => true,
                'syntax_valid'    => false,
                'warnings'        => ['Multiple DMARC records detected on domain.'],
                'explanation'     => 'RFC 7489 Section 6.6.3: Having multiple DMARC records causes recipient MTAs to disregard all DMARC policies, completely disabling domain authentication.',
                'expected'        => $expected,
                'troubleshooting' => "Multiple DMARC records detected on '{$dmarcHost}'. Under RFC 7489 section 6.6.3, mail servers MUST ignore all records and treat your domain as unprotected. Remove duplicates and keep a single record.",
            ];
        }

        foreach ($records as $record) {
            $txt = $this->extractTxtContent($record);
            if (str_starts_with(strtolower($txt), 'v=dmarc1')) {
                $analysis = $this->analyzeDmarcSyntax($txt);

                if (! $analysis['syntax_valid']) {
                    return [
                        'verified'        => false,
                        'status'          => self::STATUS_MISMATCH,
                        'found'           => $txt,
                        'policy'          => $analysis['policy'],
                        'has_duplicate'   => false,
                        'syntax_valid'    => false,
                        'warnings'        => $analysis['warnings'],
                        'explanation'     => $analysis['explanation'],
                        'expected'        => $expected,
                        'troubleshooting' => "Malformed DMARC record: {$analysis['warnings'][0]}. Correct syntax to match RFC 7489 format.",
                    ];
                }

                return [
                    'verified'        => true,
                    'status'          => self::STATUS_VERIFIED,
                    'found'           => $txt,
                    'policy'          => $analysis['policy'],
                    'subdomain_policy'=> $analysis['subdomain_policy'],
                    'pct'             => $analysis['pct'],
                    'rua'             => $analysis['rua'],
                    'ruf'             => $analysis['ruf'],
                    'adkim'           => $analysis['adkim'],
                    'aspf'            => $analysis['aspf'],
                    'has_duplicate'   => false,
                    'syntax_valid'    => true,
                    'warnings'        => $analysis['warnings'],
                    'explanation'     => $analysis['explanation'],
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
            'has_duplicate'   => false,
            'syntax_valid'    => false,
            'warnings'        => ["Record does not begin with 'v=DMARC1' tag."],
            'explanation'     => null,
            'expected'        => $expected,
            'troubleshooting' => "Record at '{$dmarcHost}' does not start with 'v=DMARC1'. Ensure syntax matches standard DMARC format.",
        ];
    }

    /**
     * Parse and analyze RFC 7489 DMARC record syntax and tags.
     */
    private function analyzeDmarcSyntax(string $dmarcTxt): array
    {
        $warnings = [];
        $tags = [];

        // Split by semicolon
        $parts = explode(';', $dmarcTxt);
        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part)) {
                continue;
            }
            if (str_contains($part, '=')) {
                [$key, $val] = explode('=', $part, 2);
                $tags[strtolower(trim($key))] = trim($val);
            }
        }

        $v = $tags['v'] ?? null;
        if (strtoupper($v ?? '') !== 'DMARC1') {
            $warnings[] = "First tag must be exact 'v=DMARC1'.";
        }

        $policy = strtolower($tags['p'] ?? '');
        if (! in_array($policy, ['none', 'quarantine', 'reject'], true)) {
            $warnings[] = "Tag 'p=' is mandatory and must be 'none', 'quarantine', or 'reject'.";
            $policy = 'none';
        }

        $sp = isset($tags['sp']) ? strtolower($tags['sp']) : null;
        if ($sp && ! in_array($sp, ['none', 'quarantine', 'reject'], true)) {
            $warnings[] = "Optional tag 'sp=' must be 'none', 'quarantine', or 'reject'.";
        }

        $pct = isset($tags['pct']) ? (int) $tags['pct'] : 100;
        if ($pct < 0 || $pct > 100) {
            $warnings[] = "Tag 'pct=' must be an integer between 0 and 100.";
            $pct = 100;
        }

        $rua = $tags['rua'] ?? null;
        $ruf = $tags['ruf'] ?? null;
        $adkim = strtolower($tags['adkim'] ?? 'r');
        $aspf = strtolower($tags['aspf'] ?? 'r');

        $explanations = [
            'none'       => "Monitoring Policy (p=none): Inbound messages failing SPF/DKIM are still delivered to recipient inboxes. Aggregate reports are generated for visibility.",
            'quarantine' => "Protection Policy (p=quarantine): Inbound messages failing SPF/DKIM are directed to recipient Spam/Junk folders.",
            'reject'     => "Full Enforcement Policy (p=reject): Inbound messages failing SPF/DKIM are strictly rejected and blocked at SMTP connection.",
        ];

        return [
            'syntax_valid'     => empty($warnings),
            'policy'           => $policy,
            'subdomain_policy' => $sp,
            'pct'              => $pct,
            'rua'              => $rua,
            'ruf'              => $ruf,
            'adkim'            => $adkim,
            'aspf'             => $aspf,
            'warnings'         => $warnings,
            'explanation'      => $explanations[$policy] ?? "Policy: {$policy}",
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
     * Retrieve recent verification run history from audit logs.
     *
     * @param Domain $domain Domain instance.
     * @param int $limit Max history entries (1 to 20).
     * @return array Chronological verification log runs.
     */
    public function getVerificationHistory(Domain $domain, int $limit = 10): array
    {
        $limit = min(max($limit, 1), 20);

        $logs = AuditLog::where('entity_type', 'Domain')
            ->where('entity_id', $domain->id)
            ->where('action', 'domain.dns_verify')
            ->latest('id')
            ->take($limit)
            ->get();

        return $logs->map(function ($log) {
            $after = $log->after_state ?? [];
            return [
                'id'                 => $log->id,
                'checked_at'         => $log->created_at?->toIso8601String(),
                'score'              => $after['score'] ?? null,
                'status'             => $after['status'] ?? 'unknown',
                'is_verified'        => ($after['status'] ?? '') === 'active',
                'mx_status'          => $after['mx_status'] ?? ($after['mx_verified'] ? self::STATUS_VERIFIED : self::STATUS_MISMATCH),
                'spf_status'         => $after['spf_status'] ?? ($after['spf_verified'] ? self::STATUS_VERIFIED : self::STATUS_MISMATCH),
                'dkim_status'        => $after['dkim_status'] ?? ($after['dkim_verified'] ? self::STATUS_VERIFIED : self::STATUS_MISMATCH),
                'dmarc_status'       => $after['dmarc_status'] ?? ($after['dmarc_verified'] ? self::STATUS_VERIFIED : self::STATUS_MISSING),
                'dns_drift_detected' => (bool) ($after['dns_drift_detected'] ?? false),
                'actor_email'        => $log->actor_email,
            ];
        })->values()->all();
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
            'warnings'    => $mxCheck['warnings'] ?? [],
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
            'spf_warnings' => $spfCheck['warnings'] ?? [],
            'dkim_warnings'=> $dkimCheck['warnings'] ?? [],
            'dmarc_warnings'=> $dmarcCheck['warnings'] ?? [],
            'dmarc_explanation' => $dmarcCheck['explanation'] ?? null,
            'details'      => ($spfCheck['verified'] && $dkimCheck['verified'])
                ? 'Outbound email is protected with SPF authorization and DKIM cryptographic signatures.'
                : 'Outbound emails risk being marked as spam or rejected until SPF and DKIM records are verified.',
        ];

        // DNS Drift Detection
        $dnsDrift = ($domain->status === 'active') && (! $mxCheck['verified'] || ! $spfCheck['verified'] || ! $dkimCheck['verified']);

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

        $recommendations = $this->buildRecommendations($records, $domain, $dmarcCheck);
        $history = $this->getVerificationHistory($domain, 5);

        $payload = [
            'domain' => [
                'id'                => $domain->id,
                'domain_name'       => $domain->domain_name,
                'status'            => $domain->status,
                'is_verified'       => $domain->status === 'active',
                'activated_at'      => $domain->activated_at ? $domain->activated_at->toIso8601String() : null,
                'last_dns_check_at' => $lastChecked,
                'dns_drift_detected'=> $dnsDrift,
            ],
            'deliverability_score'    => $score,
            'records'                 => $records,
            'inbound_routing'         => $inboundRouting,
            'outbound_authentication' => $outboundAuth,
            'infrastructure_status'   => $infrastructureStatus,
            'recommendations'         => $recommendations,
            'verification_history'    => $history,
        ];

        Cache::put($cacheKey, $payload, 60);

        return $payload;
    }

    /**
     * Generate complete Tenant Deliverability Report with policy progression matrix and audit history.
     */
    public function getDeliverabilityReport(Domain $domain, bool $refresh = false): array
    {
        $wizardData = $this->getDnsWizardData($domain, $refresh);

        $dmarcCheck = $this->verifyDmarc($domain->domain_name);
        $currentPolicy = $dmarcCheck['policy'] ?? 'none';

        // DMARC Policy Advisor Matrix
        $dmarcPolicyAdvisor = [
            'current_policy' => $currentPolicy,
            'policies'       => [
                [
                    'policy'      => 'none',
                    'name'        => 'Monitoring Mode (p=none)',
                    'is_current'  => $currentPolicy === 'none',
                    'status'      => 'recommended_start',
                    'description' => 'Unauthenticated messages are delivered normally to recipients. Aggregate reports are collected without impacting live mail flow.',
                    'risk_level'  => 'None (Safest)',
                ],
                [
                    'policy'      => 'quarantine',
                    'name'        => 'Spam Quarantine Mode (p=quarantine)',
                    'is_current'  => $currentPolicy === 'quarantine',
                    'status'      => 'gradual_escalation',
                    'description' => 'Messages failing both SPF and DKIM are placed in the recipient\'s Spam or Junk folder.',
                    'risk_level'  => 'Moderate (Spam Folder)',
                ],
                [
                    'policy'      => 'reject',
                    'name'        => 'Strict Block Mode (p=reject)',
                    'is_current'  => $currentPolicy === 'reject',
                    'status'      => 'full_enforcement',
                    'description' => 'Messages failing authentication are rejected at the SMTP dialog. Only enable after monitoring shows 100% legitimate sender alignment.',
                    'risk_level'  => 'High (Total Rejection)',
                ],
            ],
            'policy_escalation_rule' => 'EmailSaaS never automatically escalates your DMARC policy from none to quarantine or reject without tenant configuration. Premature policy escalation can block legitimate email delivery.',
        ];

        return array_merge($wizardData, [
            'dmarc_advisor'        => $dmarcPolicyAdvisor,
            'verification_history' => $this->getVerificationHistory($domain, 10),
        ]);
    }

    /**
     * Compute deliverability score and status label based on weighted authentication factors.
     */
    public function calculateDeliverabilityScore(array $verifications): array
    {
        $score = 0;
        $breakdown = [];

        if (! empty($verifications['mx'])) {
            $score += 40;
            $breakdown['mx'] = ['weight' => 40, 'earned' => 40, 'verified' => true, 'label' => 'Inbound MX Routing'];
        } else {
            $breakdown['mx'] = ['weight' => 40, 'earned' => 0, 'verified' => false, 'label' => 'Inbound MX Routing'];
        }

        if (! empty($verifications['spf'])) {
            $score += 25;
            $breakdown['spf'] = ['weight' => 25, 'earned' => 25, 'verified' => true, 'label' => 'SPF Sender Authorization'];
        } else {
            $breakdown['spf'] = ['weight' => 25, 'earned' => 0, 'verified' => false, 'label' => 'SPF Sender Authorization'];
        }

        if (! empty($verifications['dkim'])) {
            $score += 25;
            $breakdown['dkim'] = ['weight' => 25, 'earned' => 25, 'verified' => true, 'label' => 'DKIM Cryptographic Signature'];
        } else {
            $breakdown['dkim'] = ['weight' => 25, 'earned' => 0, 'verified' => false, 'label' => 'DKIM Cryptographic Signature'];
        }

        if (! empty($verifications['dmarc'])) {
            $score += 10;
            $breakdown['dmarc'] = ['weight' => 10, 'earned' => 10, 'verified' => true, 'label' => 'DMARC Policy & Reporting'];
        } else {
            $breakdown['dmarc'] = ['weight' => 10, 'earned' => 0, 'verified' => false, 'label' => 'DMARC Policy & Reporting'];
        }

        $disclaimer = 'DNS verification confirms publication of authentication records in public DNS. It does not evaluate live message delivery, cryptographic message signatures on transmitted mail, DMARC alignment on live streams, sender reputation, or external spam folder placement.';

        if ($score === 100) {
            return [
                'score_percent' => 100,
                'status'        => 'ready',
                'status_label'  => 'Production Ready',
                'breakdown'     => $breakdown,
                'summary'       => 'Domain is fully authenticated and ready for reliable inbound delivery and high-inbox deliverability.',
                'disclaimer'    => $disclaimer,
            ];
        }

        if ($score >= 65) {
            return [
                'score_percent' => $score,
                'status'        => 'needs_attention',
                'status_label'  => 'Partially Configured',
                'breakdown'     => $breakdown,
                'summary'       => 'Essential email routing is functional, but missing authentication records (DKIM/DMARC) increase spam risks.',
                'disclaimer'    => $disclaimer,
            ];
        }

        return [
            'score_percent' => $score,
            'status'        => 'critical',
            'status_label'  => 'Incomplete Setup',
            'breakdown'     => $breakdown,
            'summary'       => 'Critical records (MX or SPF) are missing. Inbound mail delivery is not functional until DNS records propagate.',
            'disclaimer'    => $disclaimer,
        ];
    }

    /**
     * Generate step-by-step actionable recommendations based on failed checks.
     */
    private function buildRecommendations(array $records, Domain $domain, ?array $dmarcCheck = null): array
    {
        $recs = [];

        foreach ($records as $r) {
            if ($r['is_required'] && ! $r['verified']) {
                if ($r['id'] === 'mx') {
                    $recs[] = "Add the MX record pointing to '{$this->mailServerHost}' (Priority 10) to enable incoming email delivery.";
                } elseif ($r['id'] === 'spf') {
                    if ($r['status'] === self::STATUS_MISMATCH) {
                        $recs[] = "Fix your SPF record: ensure only one 'v=spf1' record is published and includes 'a:{$this->mailServerHost}' or 'mx'.";
                    } else {
                        $recs[] = "Add the SPF TXT record on '@' with value 'v=spf1 mx a:{$this->mailServerHost} ~all' to authorize outbound emails.";
                    }
                } elseif ($r['id'] === 'dkim') {
                    if ($r['status'] === self::STATUS_NOT_CONFIGURED) {
                        $recs[] = "DKIM key is generating on the mail server. Once ready, publish the DKIM TXT record at '{$r['host']}'.";
                    } else {
                        $recs[] = "Publish the DKIM TXT record at '{$r['host']}' to enable cryptographic signature verification.";
                    }
                }
            }
        }

        $dmarc = collect($records)->firstWhere('id', 'dmarc');
        if ($dmarc && ! $dmarc['verified']) {
            if (($dmarc['status'] ?? '') === self::STATUS_MISMATCH) {
                $recs[] = "DMARC syntax or duplicate record issue detected on '_dmarc'. Ensure only one record exists with valid 'v=DMARC1; p=...' tags.";
            } else {
                $recs[] = "Recommended: Add a DMARC record at '_dmarc' (e.g. 'v=DMARC1; p=quarantine; rua=mailto:dmarc@{$domain->domain_name}') to protect your domain from spoofing.";
            }
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
