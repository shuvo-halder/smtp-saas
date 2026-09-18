<?php

namespace App\Services\Abuse;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

class PostfixLogParserService
{
    /**
     * Regex matching syslog header with traditional or ISO8601 timestamp.
     * Captures:
     * 1: Timestamp (optional)
     * 2: Daemon string (e.g. "postfix/smtp" or "postfix/submission/smtpd")
     * 3: Process PID (optional)
     * 4: Remainder of message
     */
    private const SYSLOG_LINE_REGEX = '/^(?:([A-Za-z]{3}\s+\d+\s+\d{2}:\d{2}:\d{2}|\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2}))\s+[^\s]+\s+)?(postfix\/[a-zA-Z0-9_\-\/]+)(?:\[(\d+)\])?:\s*(.*)$/';

    /**
     * Regex matching Queue ID followed by colon and message payload.
     * Postfix Queue IDs are typically 10 to 16 alphanumeric characters or hex characters.
     */
    private const QUEUE_MSG_REGEX = '/^([A-Za-z0-9]+):\s*(.*)$/';

    private int $maxLineLength;
    private string $cursorKey;

    public function __construct(?int $maxLineLength = null, ?string $cursorKey = null)
    {
        $this->maxLineLength = $maxLineLength ?? (function_exists('config') ? (int) config('mail_abuse.max_line_length', 4096) : 4096);
        $this->cursorKey = $cursorKey ?? (function_exists('config') ? (string) config('mail_abuse.parser_cursor_key', 'outbound:abuse:parser:cursor') : 'outbound:abuse:parser:cursor');
    }

    /**
     * Parses a single log line into a NormalizedMailEvent DTO.
     * Returns null if line is empty or cannot be parsed as a Postfix event.
     */
    public function parseLine(string $rawLine): ?NormalizedMailEvent
    {
        $trimmed = trim($rawLine);
        if ($trimmed === '') {
            return null;
        }

        // Bounded length check
        if (strlen($trimmed) > $this->maxLineLength) {
            $trimmed = substr($trimmed, 0, $this->maxLineLength);
        }

        if (!preg_match(self::SYSLOG_LINE_REGEX, $trimmed, $matches)) {
            // Check if line directly starts with queue ID or message without syslog header
            return null;
        }

        $timestamp = !empty($matches[1]) ? $matches[1] : null;
        $daemon = $matches[2];
        $payload = $matches[4] ?? '';

        // Extract Queue ID if present
        $queueId = null;
        $body = $payload;

        if (preg_match(self::QUEUE_MSG_REGEX, $payload, $qMatches)) {
            $queueId = $qMatches[1];
            $body = $qMatches[2];
        }

        $sender = null;
        $recipient = null;
        $dsn = null;
        $status = null;
        $smtpCode = null;
        $message = null;
        $reinjectedQueueId = null;
        $eventType = NormalizedMailEvent::TYPE_OTHER;

        // 1. Check for Intermediate Filter Handoff (e.g. postfix/smtp-amavis, relay=127.0.0.1:10024)
        $isIntermediateFilter = str_contains($daemon, 'smtp-amavis')
            || str_contains($daemon, 'amavis')
            || preg_match('/relay=(?:127\.0\.0\.1|localhost)(?:\[127\.0\.0\.1\])?:10024\b/', $body);

        if ($isIntermediateFilter) {
            if (preg_match('/to=<([^>]*)>/', $body, $toMatches)) {
                $recipient = $toMatches[1];
            }
            if (preg_match('/status=([a-zA-Z]+)/', $body, $statusMatches)) {
                $status = strtolower($statusMatches[1]);
            }
            if (preg_match('/dsn=([0-9]\.[0-9]+\.[0-9]+)/', $body, $dsnMatches)) {
                $dsn = $dsnMatches[1];
            }
            if (preg_match('/queued as ([A-Za-z0-9]+)/i', $body, $qMatches)) {
                $reinjectedQueueId = $qMatches[1];
            }
            if (preg_match('/\((.*)\)$/', $body, $diagMatches)) {
                $message = $diagMatches[1];
            }
            $eventType = NormalizedMailEvent::TYPE_INTERMEDIATE_FILTER_HANDOFF;
        }

        // 2. Final Delivery Event (postfix/smtp, postfix/lmtp, postfix/error) - Strictly excluding filter hops & smtpd
        if (!$isIntermediateFilter && (str_ends_with($daemon, '/smtp') || $daemon === 'postfix/smtp' || str_contains($daemon, 'lmtp') || str_contains($daemon, 'error')) && !str_contains($daemon, 'smtpd')) {
            if (preg_match('/to=<([^>]*)>/', $body, $toMatches)) {
                $recipient = $toMatches[1];
                $eventType = NormalizedMailEvent::TYPE_DELIVERY_STATUS;

                if (preg_match('/status=([a-zA-Z]+)/', $body, $statusMatches)) {
                    $status = strtolower($statusMatches[1]);
                }

                if (preg_match('/dsn=([0-9]\.[0-9]+\.[0-9]+)/', $body, $dsnMatches)) {
                    $dsn = $dsnMatches[1];
                }

                if (preg_match('/\((?:host\s+[^\s]+\s+said:\s*)?([0-9]{3})\s+/', $body, $codeMatches)) {
                    $smtpCode = (int) $codeMatches[1];
                }

                if (preg_match('/\((.*)\)$/', $body, $diagMatches)) {
                    $message = $diagMatches[1];
                }
            }
        }

        // 3. Queue Manager Sender Event (postfix/qmgr, postfix/oqmgr)
        if ($eventType === NormalizedMailEvent::TYPE_OTHER && str_contains($daemon, 'qmgr')) {
            if (preg_match('/from=<([^>]*)>/', $body, $fromMatches)) {
                $sender = $fromMatches[1];
                $eventType = NormalizedMailEvent::TYPE_QMGR_FROM;
            }
        }

        // 4. Submission Authenticated Event (postfix/submission/smtpd, postfix/smtps/smtpd, postfix/smtpd)
        if ($eventType === NormalizedMailEvent::TYPE_OTHER && str_contains($daemon, 'smtpd')) {
            if (preg_match('/sasl_username=([^\s,]+)/', $body, $saslMatches)) {
                $sender = $saslMatches[1];
                $eventType = NormalizedMailEvent::TYPE_SUBMISSION;
            }
        }

        // 5. Non-Delivery Notification / Bounce Event (postfix/bounce)
        if ($eventType === NormalizedMailEvent::TYPE_OTHER && str_contains($daemon, 'bounce')) {
            $eventType = NormalizedMailEvent::TYPE_BOUNCE_NOTICE;
            $message = $body;
        }

        return new NormalizedMailEvent(
            timestamp: $timestamp,
            queueId: $queueId,
            daemon: $daemon,
            eventType: $eventType,
            sender: $sender,
            recipient: $recipient,
            dsn: $dsn,
            status: $status,
            smtpCode: $smtpCode,
            message: $message,
            rawLine: $rawLine,
            reinjectedQueueId: $reinjectedQueueId,
        );
    }

    /**
     * Reads and parses a chunk of lines from the specified log file incrementally.
     *
     * @param string $filePath
     * @param int $maxLines
     * @param bool $dryRun
     * @return array{events: NormalizedMailEvent[], lines_read: int, has_more: bool, error: ?string, next_cursor: ?array}
     */
    public function parseFile(string $filePath, int $maxLines = 1000, bool $dryRun = false): array
    {
        if (!file_exists($filePath)) {
            return [
                'events' => [],
                'lines_read' => 0,
                'has_more' => false,
                'error' => "Log file does not exist: {$filePath}",
                'next_cursor' => null,
            ];
        }

        if (!is_readable($filePath)) {
            return [
                'events' => [],
                'lines_read' => 0,
                'has_more' => false,
                'error' => "Log file is not readable by current process: {$filePath}",
                'next_cursor' => null,
            ];
        }

        $cursor = $this->getCursor();
        $savedInode = $cursor['inode'] ?? null;
        $savedOffset = (int) ($cursor['offset'] ?? 0);

        $stat = @stat($filePath);
        $currentInode = $stat['ino'] ?? null;
        $currentSize = $stat['size'] ?? 0;

        $events = [];
        $linesRead = 0;
        $nextCursor = null;

        // Detect rotation or truncation
        $inodeChanged = ($savedInode !== null && $currentInode !== null && $savedInode !== $currentInode);

        if ($inodeChanged) {
            $rotatedPath = $filePath . '.1';
            // Check if rotated file exists and has unread tail
            if (file_exists($rotatedPath) && is_readable($rotatedPath)) {
                $rotStat = @stat($rotatedPath);
                $rotInode = $rotStat['ino'] ?? null;
                $rotSize = $rotStat['size'] ?? 0;

                $isMatchingRotated = ($rotInode !== null && $rotInode === $savedInode)
                    || ($rotInode === null && $rotSize >= $savedOffset);

                if ($isMatchingRotated && $savedOffset < $rotSize) {
                    $fpRot = @fopen($rotatedPath, 'rb');
                    if ($fpRot) {
                        if ($savedOffset > 0) {
                            @fseek($fpRot, $savedOffset, SEEK_SET);
                        }

                        while ($linesRead < $maxLines && !feof($fpRot)) {
                            $line = fgets($fpRot, $this->maxLineLength);
                            if ($line === false) {
                                break;
                            }
                            if (!str_ends_with($line, "\n") && !str_ends_with($line, "\r") && feof($fpRot)) {
                                @fseek($fpRot, -strlen($line), SEEK_CUR);
                                break;
                            }
                            $linesRead++;
                            $event = $this->parseLine($line);
                            if ($event !== null) {
                                $events[] = $event;
                            }
                        }

                        $rotOffset = ftell($fpRot);
                        $rotEof = feof($fpRot);
                        @fclose($fpRot);

                        // If maxLines reached before rotated file finished, cursor stays on rotated file
                        if (!$rotEof && $linesRead >= $maxLines) {
                            return [
                                'events' => $events,
                                'lines_read' => $linesRead,
                                'has_more' => true,
                                'error' => null,
                                'next_cursor' => [
                                    'inode' => $savedInode,
                                    'offset' => $rotOffset,
                                    'last_run' => now()->toIso8601String(),
                                    'lines_processed' => $linesRead,
                                ],
                            ];
                        }
                    }
                }
            }
            $offset = 0;
        } elseif ($currentSize < $savedOffset) {
            Log::channel('abuse')->info("PostfixLogParser: File truncated ({$currentSize} < {$savedOffset}). Resetting offset to 0.");
            $offset = 0;
        } else {
            $offset = $savedOffset;
        }

        // Continue reading from active file
        $hasMore = false;
        if ($linesRead < $maxLines) {
            $fp = @fopen($filePath, 'rb');
            if (!$fp) {
                return [
                    'events' => $events,
                    'lines_read' => $linesRead,
                    'has_more' => false,
                    'error' => "Failed to open log file stream: {$filePath}",
                    'next_cursor' => null,
                ];
            }

            if ($offset > 0) {
                @fseek($fp, $offset, SEEK_SET);
            }

            while ($linesRead < $maxLines && !feof($fp)) {
                $line = fgets($fp, $this->maxLineLength);
                if ($line === false) {
                    break;
                }

                if (!str_ends_with($line, "\n") && !str_ends_with($line, "\r") && feof($fp)) {
                    @fseek($fp, -strlen($line), SEEK_CUR);
                    break;
                }

                $linesRead++;
                $event = $this->parseLine($line);
                if ($event !== null) {
                    $events[] = $event;
                }
            }

            $newOffset = ftell($fp);
            $hasMore = !feof($fp);
            @fclose($fp);

            $nextCursor = [
                'inode' => $currentInode,
                'offset' => $newOffset,
                'last_run' => now()->toIso8601String(),
                'lines_processed' => $linesRead,
            ];
        } else {
            $hasMore = true;
            $nextCursor = [
                'inode' => $currentInode,
                'offset' => 0,
                'last_run' => now()->toIso8601String(),
                'lines_processed' => $linesRead,
            ];
        }

        return [
            'events' => $events,
            'lines_read' => $linesRead,
            'has_more' => $hasMore,
            'error' => null,
            'next_cursor' => $nextCursor,
        ];
    }

    /**
     * Get stored parser cursor state from Redis.
     */
    public function getCursor(): array
    {
        try {
            $raw = Redis::get($this->cursorKey);
            if (!empty($raw)) {
                $decoded = json_decode((string) $raw, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        } catch (Throwable $e) {
            Log::channel('abuse')->warning('PostfixLogParser: Failed to read cursor from Redis', [
                'error' => $e->getMessage(),
            ]);
        }

        return ['inode' => null, 'offset' => 0];
    }

    /**
     * Save parser cursor state to Redis.
     */
    public function saveCursor(array $cursor): void
    {
        try {
            Redis::set($this->cursorKey, json_encode($cursor));
        } catch (Throwable $e) {
            Log::channel('abuse')->error('PostfixLogParser: Failed to save cursor to Redis', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
