<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * DmarcReportParserService — Secure parser for RFC 7489 DMARC Aggregate XML Reports.
 *
 * Implements strict size limits, XXE (XML External Entity) prevention, schema validation,
 * aggregate metrics extraction, and deterministic report deduplication digests.
 */
class DmarcReportParserService
{
    /**
     * Maximum allowed XML report size (5 Megabytes).
     */
    public const MAX_REPORT_SIZE_BYTES = 5 * 1024 * 1024;

    /**
     * Parse raw DMARC XML report string into structured telemetry.
     *
     * @param string $xmlContent Raw XML string.
     * @return array Structured DMARC aggregate report telemetry.
     * @throws InvalidArgumentException When report is oversized, malformed, or insecure.
     */
    public function parseXml(string $xmlContent): array
    {
        $contentLength = strlen($xmlContent);
        if ($contentLength === 0) {
            throw new InvalidArgumentException('DMARC report content cannot be empty.');
        }

        if ($contentLength > self::MAX_REPORT_SIZE_BYTES) {
            throw new InvalidArgumentException(
                sprintf('DMARC report exceeds the maximum allowed size of %d MB (size: %.2f MB).',
                    self::MAX_REPORT_SIZE_BYTES / (1024 * 1024),
                    $contentLength / (1024 * 1024)
                )
            );
        }

        // XXE Pre-check: Reject any XML containing DOCTYPE or ENTITY definitions
        if (preg_match('/<!(?:DOCTYPE|ENTITY)\s+/i', $xmlContent)) {
            throw new InvalidArgumentException('DMARC report contains forbidden DOCTYPE/ENTITY declarations (XXE protection).');
        }

        // Disable external entity resolution and load XML safely
        $prevEntityLoader = null;
        if (\PHP_VERSION_ID < 80000 && function_exists('libxml_disable_entity_loader')) {
            /** @psalm-suppress DeprecatedFunction */
            $prevEntityLoader = @libxml_disable_entity_loader(true);
        }

        $prevErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $xml = simplexml_load_string(
            $xmlContent,
            'SimpleXMLElement',
            LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR
        );

        $xmlErrors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($prevErrors);

        if (\PHP_VERSION_ID < 80000 && function_exists('libxml_disable_entity_loader') && $prevEntityLoader !== null) {
            /** @psalm-suppress DeprecatedFunction */
            @libxml_disable_entity_loader($prevEntityLoader);
        }

        if ($xml === false) {
            $errDetail = !empty($xmlErrors) ? trim($xmlErrors[0]->message) : 'XML structure is invalid.';
            throw new InvalidArgumentException("Malformed DMARC XML report: {$errDetail}");
        }

        if (strtolower($xml->getName()) !== 'feedback') {
            throw new InvalidArgumentException("Invalid DMARC report root element '<{$xml->getName()}>'. Expected '<feedback>'.");
        }

        return $this->extractReportData($xml);
    }

    /**
     * Compute a deterministic SHA-256 digest for deduplicating report ingestion.
     *
     * @param array $report Structured report output from parseXml().
     * @return string 64-character SHA-256 hex hash.
     */
    public function computeDigest(array $report): string
    {
        $org = $report['metadata']['org_name'] ?? '';
        $reportId = $report['metadata']['report_id'] ?? '';
        $domain = $report['policy_published']['domain'] ?? '';
        $begin = $report['metadata']['date_range']['begin'] ?? 0;
        $end = $report['metadata']['date_range']['end'] ?? 0;

        return hash('sha256', strtolower(trim("{$org}:{$reportId}:{$domain}:{$begin}:{$end}")));
    }

    /**
     * Extract normalized telemetry from SimpleXMLElement tree.
     */
    private function extractReportData(\SimpleXMLElement $xml): array
    {
        // 1. Report Metadata
        $metaNode = $xml->report_metadata ?? null;
        if (! $metaNode) {
            throw new InvalidArgumentException('DMARC report missing required <report_metadata> section.');
        }

        $dateRange = $metaNode->date_range ?? null;
        $dateBegin = (int) ($dateRange->begin ?? 0);
        $dateEnd = (int) ($dateRange->end ?? 0);

        $metadata = [
            'org_name'           => (string) ($metaNode->org_name ?? 'Unknown Organization'),
            'email'              => (string) ($metaNode->email ?? ''),
            'extra_contact_info' => (string) ($metaNode->extra_contact_info ?? ''),
            'report_id'          => (string) ($metaNode->report_id ?? ''),
            'date_range'         => [
                'begin'            => $dateBegin,
                'end'              => $dateEnd,
                'begin_formatted'  => $dateBegin > 0 ? date('Y-m-d H:i:s', $dateBegin) : null,
                'end_formatted'    => $dateEnd > 0 ? date('Y-m-d H:i:s', $dateEnd) : null,
            ],
        ];

        // 2. Published Policy
        $policyNode = $xml->policy_published ?? null;
        if (! $policyNode) {
            throw new InvalidArgumentException('DMARC report missing required <policy_published> section.');
        }

        $policyPublished = [
            'domain' => strtolower(trim((string) ($policyNode->domain ?? ''))),
            'adkim'  => (string) ($policyNode->adkim ?? 'r'),
            'aspf'   => (string) ($policyNode->aspf ?? 'r'),
            'p'      => strtolower(trim((string) ($policyNode->p ?? 'none'))),
            'sp'     => (string) ($policyNode->sp ?? ''),
            'pct'    => (int) ($policyNode->pct ?? 100),
            'fo'     => (string) ($policyNode->fo ?? '0'),
        ];

        if (empty($policyPublished['domain'])) {
            throw new InvalidArgumentException('DMARC report missing domain in <policy_published>.');
        }

        // 3. Evaluate Records
        $records = [];
        $totalMessages = 0;
        $totalPassed = 0;
        $totalFailed = 0;
        $sources = [];

        foreach ($xml->record as $rec) {
            $row = $rec->row ?? null;
            if (! $row) {
                continue;
            }

            $count = (int) ($row->count ?? 0);
            $sourceIp = (string) ($row->source_ip ?? '0.0.0.0');
            $policyEval = $row->policy_evaluated ?? null;

            $disposition = (string) ($policyEval->disposition ?? 'none');
            $dkimResult = (string) ($policyEval->dkim ?? 'fail');
            $spfResult = (string) ($policyEval->spf ?? 'fail');

            $identifiers = $rec->identifiers ?? null;
            $headerFrom = (string) ($identifiers->header_from ?? $policyPublished['domain']);

            // Auth Results breakdown
            $authResultsNode = $rec->auth_results ?? null;
            $dkimAuth = [];
            $spfAuth = [];

            if ($authResultsNode) {
                if (isset($authResultsNode->dkim)) {
                    foreach ($authResultsNode->dkim as $dkimEntry) {
                        $dkimAuth[] = [
                            'domain'   => (string) ($dkimEntry->domain ?? ''),
                            'result'   => (string) ($dkimEntry->result ?? 'fail'),
                            'selector' => (string) ($dkimEntry->selector ?? ''),
                        ];
                    }
                }

                if (isset($authResultsNode->spf)) {
                    foreach ($authResultsNode->spf as $spfEntry) {
                        $spfAuth[] = [
                            'domain' => (string) ($spfEntry->domain ?? ''),
                            'scope'  => (string) ($spfEntry->scope ?? 'mfrom'),
                            'result' => (string) ($spfEntry->result ?? 'fail'),
                        ];
                    }
                }
            }

            $isPassed = ($dkimResult === 'pass' || $spfResult === 'pass');

            $totalMessages += $count;
            if ($isPassed) {
                $totalPassed += $count;
            } else {
                $totalFailed += $count;
            }

            if (! isset($sources[$sourceIp])) {
                $sources[$sourceIp] = [
                    'ip'           => $sourceIp,
                    'total_count'  => 0,
                    'passed_count' => 0,
                    'failed_count' => 0,
                ];
            }
            $sources[$sourceIp]['total_count'] += $count;
            if ($isPassed) {
                $sources[$sourceIp]['passed_count'] += $count;
            } else {
                $sources[$sourceIp]['failed_count'] += $count;
            }

            $records[] = [
                'source_ip'        => $sourceIp,
                'count'            => $count,
                'header_from'      => $headerFrom,
                'policy_evaluated' => [
                    'disposition' => $disposition,
                    'dkim'        => $dkimResult,
                    'spf'         => $spfResult,
                ],
                'auth_results'     => [
                    'dkim' => $dkimAuth,
                    'spf'  => $spfAuth,
                ],
                'is_passed'        => $isPassed,
            ];
        }

        $passRate = $totalMessages > 0 ? round(($totalPassed / $totalMessages) * 100, 1) : 0.0;

        $report = [
            'metadata'         => $metadata,
            'policy_published' => $policyPublished,
            'summary'          => [
                'total_messages'     => $totalMessages,
                'total_passed'       => $totalPassed,
                'total_failed'       => $totalFailed,
                'pass_rate_percent'  => $passRate,
                'unique_sources'     => count($sources),
            ],
            'sources'          => array_values($sources),
            'records'          => $records,
        ];

        $report['digest'] = $this->computeDigest($report);

        return $report;
    }
}
