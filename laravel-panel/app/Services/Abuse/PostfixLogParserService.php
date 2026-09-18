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
        $eventType = NormalizedMailEvent::TYPE_OTHER;

        // 1. Delivery Event (postfix/smtp, postfix/lmtp, postfix/error)
        if (str_contains($daemon, 'smtp') || str_contains($daemon, 'lmtp') || str_contains($daemon, 'error')) {
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

        // 2. Queue Manager Sender Event (postfix/qmgr, postfix/oqmgr)
        if ($eventType === NormalizedMailEvent::TYPE_OTHER && str_contains($daemon, 'qmgr')) {
            if (preg_match('/from=<([^>]*)>/', $body, $fromMatches)) {
                $sender = $fromMatches[1];
                $eventType = NormalizedMailEvent::TYPE_QMGR_FROM;
            }
        }

        // 3. Submission Authenticated Event (postfix/submission/smtpd, postfix/smtps/smtpd, postfix/smtpd)
        if ($eventType === NormalizedMailEvent::TYPE_OTHER && str_contains($daemon, 'smtpd')) {
            if (preg_match('/sasl_username=([^\s,]+)/', $body, $saslMatches)) {
                $sender = $saslMatches[1];
                $eventType = NormalizedMailEvent::TYPE_SUBMISSION;
            }
        }

        // 4. Non-Delivery Notification / Bounce Event (postfix/bounce)
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
        );
    }

    /**
     * Reads and parses a chunk of lines from the specified log file incrementally.
     *
     * @param string $filePath
     * @param int $maxLines
     * @param bool $dryRun
     * @return array{events: NormalizedMailEvent[], lines_read: int, has_more: bool, error: ?string}
     */
    public function parseFile(string $filePath, int $maxLines = 1000, bool $dryRun = false): array
    {
        if (!file_exists($filePath)) {
            return [
                'events' => [],
                'lines_read' => 0,
                'has_more' => false,
                'error' => "Log file does not exist: {$filePath}",
            ];
        }

        if (!is_readable($filePath)) {
            return [
                'events' => [],
                'lines_read' => 0,
                'has_more' => false,
                'error' => "Log file is not readable by current process: {$filePath}",
            ];
        }

        $cursor = $this->getCursor();
        $savedInode = $cursor['inode'] ?? null;
        $savedOffset = (int) ($cursor['offset'] ?? 0);

        $stat = @stat($filePath);
        $currentInode = $stat['ino'] ?? null;
        $currentSize = $stat['size'] ?? 0;

        $offset = $savedOffset;

        // Detect rotation or truncation:
        // If file size is less than saved offset, or inode changed, reset offset
        if ($savedInode !== null && $currentInode !== null && $savedInode !== $currentInode) {
            Log::channel('abuse')->info("PostfixLogParser: Inode changed from {$savedInode} to {$currentInode}. Resetting offset to 0.");
            $offset = 0;
        } elseif ($currentSize < $savedOffset) {
            Log::channel('abuse')->info("PostfixLogParser: File truncated ({$currentSize} < {$savedOffset}). Resetting offset to 0.");
            $offset = 0;
        }

        $fp = @fopen($filePath, 'rb');
        if (!$fp) {
            return [
                'events' => [],
                'lines_read' => 0,
                'has_more' => false,
                'error' => "Failed to open log file stream: {$filePath}",
            ];
        }

        if ($offset > 0) {
            @fseek($fp, $offset, SEEK_SET);
        }

        $events = [];
        $linesRead = 0;

        while ($linesRead < $maxLines && !feof($fp)) {
            $line = fgets($fp, $this->maxLineLength);
            if ($line === false) {
                break;
            }

            // If line doesn't end with newline, we may have hit partial chunk at EOF
            if (!str_ends_with($line, "\n") && !str_ends_with($line, "\r") && feof($fp)) {
                // Seek back to before the partial line so it can be completed on next run
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

        if (!$dryRun && $linesRead > 0) {
            $this->saveCursor([
                'inode' => $currentInode,
                'offset' => $newOffset,
                'last_run' => now()->toIso8601String(),
                'lines_processed' => $linesRead,
            ]);
        }

        return [
            'events' => $events,
            'lines_read' => $linesRead,
            'has_more' => $hasMore,
            'error' => null,
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
