<?php

namespace Tests\Unit;

use App\Services\Abuse\NormalizedMailEvent;
use App\Services\Abuse\PostfixLogParserService;
use PHPUnit\Framework\TestCase;

class PostfixLogParserTest extends TestCase
{
    private PostfixLogParserService $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new PostfixLogParserService(4096, 'outbound:abuse:parser:cursor');
    }

    public function test_parses_qmgr_sender_event(): void
    {
        $line = 'Sep 19 01:23:45 mail postfix/qmgr[10416]: 4Y1z9M2dZ1z3x4y: from=<sales@company.com>, size=2048, nrcpt=1 (queue active)';
        $event = $this->parser->parseLine($line);

        $this->assertNotNull($event);
        $this->assertEquals('4Y1z9M2dZ1z3x4y', $event->queueId);
        $this->assertEquals('postfix/qmgr', $event->daemon);
        $this->assertEquals(NormalizedMailEvent::TYPE_QMGR_FROM, $event->eventType);
        $this->assertEquals('sales@company.com', $event->sender);
        $this->assertTrue($event->isQmgrSenderEvent());
    }

    public function test_parses_smtp_success_event(): void
    {
        $line = 'Sep 19 01:23:46 mail postfix/smtp[10430]: 4Y1z9M2dZ1z8888: to=<target@remote.com>, relay=mx.remote.com[93.184.216.34]:25, delay=1.2, delays=0.01/0/0.4/0.79, dsn=2.0.0, status=sent (250 2.0.0 OK 1726700000 abc123def)';
        $event = $this->parser->parseLine($line);

        $this->assertNotNull($event);
        $this->assertEquals('4Y1z9M2dZ1z8888', $event->queueId);
        $this->assertEquals('postfix/smtp', $event->daemon);
        $this->assertEquals(NormalizedMailEvent::TYPE_DELIVERY_STATUS, $event->eventType);
        $this->assertEquals('target@remote.com', $event->recipient);
        $this->assertEquals('sent', $event->status);
        $this->assertEquals('2.0.0', $event->dsn);
        $this->assertEquals(250, $event->smtpCode);
        $this->assertTrue($event->isDeliveryEvent());
    }

    public function test_parses_smtp_hard_bounce_event(): void
    {
        $line = 'Sep 19 01:23:47 mail postfix/smtp[10430]: 4Y1z9M2dZ1z8888: to=<invalid@remote.com>, relay=mx.remote.com[93.184.216.34]:25, delay=0.8, delays=0.01/0/0.4/0.39, dsn=5.1.1, status=bounced (host mx.remote.com[93.184.216.34] said: 550 5.1.1 <invalid@remote.com>: Recipient address rejected: User unknown)';
        $event = $this->parser->parseLine($line);

        $this->assertNotNull($event);
        $this->assertEquals('4Y1z9M2dZ1z8888', $event->queueId);
        $this->assertEquals(NormalizedMailEvent::TYPE_DELIVERY_STATUS, $event->eventType);
        $this->assertEquals('invalid@remote.com', $event->recipient);
        $this->assertEquals('bounced', $event->status);
        $this->assertEquals('5.1.1', $event->dsn);
        $this->assertEquals(550, $event->smtpCode);
        $this->assertStringContainsString('User unknown', $event->message);
    }

    public function test_parses_smtp_deferred_soft_bounce_event(): void
    {
        $line = 'Sep 19 01:23:48 mail postfix/smtp[10430]: 4Y1z9M2dZ1z8888: to=<greylisted@remote.com>, relay=none, delay=30, delays=0.01/0/30/0, dsn=4.4.1, status=deferred (connect to mx.remote.com: Connection timed out)';
        $event = $this->parser->parseLine($line);

        $this->assertNotNull($event);
        $this->assertEquals('4Y1z9M2dZ1z8888', $event->queueId);
        $this->assertEquals('deferred', $event->status);
        $this->assertEquals('4.4.1', $event->dsn);
    }

    public function test_parses_iso8601_timestamp_format(): void
    {
        $line = '2026-09-19T01:23:45.123456+00:00 mail postfix/qmgr[10416]: 4Y1z9M2dZ1z3x4y: from=<sender@domain.com>, size=1024, nrcpt=1 (queue active)';
        $event = $this->parser->parseLine($line);

        $this->assertNotNull($event);
        $this->assertEquals('4Y1z9M2dZ1z3x4y', $event->queueId);
        $this->assertEquals('sender@domain.com', $event->sender);
    }

    public function test_parses_submission_sasl_event(): void
    {
        $line = 'Sep 19 01:23:45 mail postfix/submission/smtpd[10412]: 4Y1z9M2dZ1z3x4y: client=mail.client.com[198.51.100.5], sasl_method=PLAIN, sasl_username=user@domain.com';
        $event = $this->parser->parseLine($line);

        $this->assertNotNull($event);
        $this->assertEquals('4Y1z9M2dZ1z3x4y', $event->queueId);
        $this->assertEquals('user@domain.com', $event->sender);
        $this->assertEquals(NormalizedMailEvent::TYPE_SUBMISSION, $event->eventType);
    }

    public function test_ignores_non_postfix_daemon_lines(): void
    {
        $line = 'Sep 19 01:23:45 mail systemd[1]: Started Postfix Mail Transport Agent.';
        $event = $this->parser->parseLine($line);

        $this->assertNull($event);
    }

    public function test_handles_empty_and_malformed_lines(): void
    {
        $this->assertNull($this->parser->parseLine(''));
        $this->assertNull($this->parser->parseLine('   '));
        $this->assertNull($this->parser->parseLine('This is not a syslog line at all'));
    }

    public function test_line_length_bounds_enforced(): void
    {
        $hugeLine = 'Sep 19 01:23:45 mail postfix/qmgr[10416]: 4Y1z9M2dZ1z3x4y: from=<user@domain.com>, ' . str_repeat('X', 10000);
        $event = $this->parser->parseLine($hugeLine);

        $this->assertNotNull($event);
        $this->assertEquals('user@domain.com', $event->sender);
    }

    public function test_identifies_smtp_amavis_as_intermediate_filter_handoff(): void
    {
        $line = 'Sep 19 01:23:46 mail postfix/smtp-amavis[10420]: 4Y1z9M2dZ1z3x4y: to=<recipient@example.net>, relay=127.0.0.1[127.0.0.1]:10024, delay=0.5, dsn=2.0.0, status=sent (250 2.0.0 from MTA(smtp:[127.0.0.1]:10025): 250 2.0.0 Ok: queued as 4Y1z9M2dZ1z9999)';
        $event = $this->parser->parseLine($line);

        $this->assertNotNull($event);
        $this->assertEquals('4Y1z9M2dZ1z3x4y', $event->queueId);
        $this->assertEquals('4Y1z9M2dZ1z9999', $event->reinjectedQueueId);
        $this->assertEquals('recipient@example.net', $event->recipient);
        $this->assertEquals('sent', $event->status);
        $this->assertEquals(NormalizedMailEvent::TYPE_INTERMEDIATE_FILTER_HANDOFF, $event->eventType);
        $this->assertTrue($event->isIntermediateFilterEvent());
        $this->assertFalse($event->isDeliveryEvent());
    }

    public function test_identifies_localhost_filter_relay_as_intermediate_handoff(): void
    {
        $line = 'Sep 19 01:23:46 mail postfix/smtp[10420]: 4Y1z9M2dZ1z3x4y: to=<recipient@example.net>, relay=127.0.0.1:10024, dsn=2.0.0, status=sent (250 Ok: queued as 4Y1z9M2dZ1z8888)';
        $event = $this->parser->parseLine($line);

        $this->assertNotNull($event);
        $this->assertTrue($event->isIntermediateFilterEvent());
        $this->assertFalse($event->isDeliveryEvent());
        $this->assertEquals('4Y1z9M2dZ1z8888', $event->reinjectedQueueId);
    }

    public function test_distinguishes_remote_smtp_from_filter_relay(): void
    {
        $line = 'Sep 19 01:23:47 mail postfix/smtp[10430]: 4Y1z9M2dZ1z8888: to=<recipient@example.net>, relay=mx.remote.com[93.184.216.34]:25, delay=1.2, dsn=2.0.0, status=sent (250 2.0.0 OK)';
        $event = $this->parser->parseLine($line);

        $this->assertNotNull($event);
        $this->assertTrue($event->isDeliveryEvent());
        $this->assertFalse($event->isIntermediateFilterEvent());
        $this->assertEquals('recipient@example.net', $event->recipient);
    }

    public function test_rotation_drains_rotated_tail_before_reading_active_file(): void
    {
        $tempDir = sys_get_temp_dir();
        $activeLog = $tempDir . DIRECTORY_SEPARATOR . 'active_' . uniqid() . '.log';
        $rotatedLog = $activeLog . '.1';

        // Write rotated log with 2 lines
        $rotLine1 = "Sep 19 01:00:00 mail postfix/qmgr[100]: Q1: from=<a@b.com>, size=100\n";
        $rotLine2 = "Sep 19 01:00:01 mail postfix/qmgr[100]: Q2: from=<c@d.com>, size=100\n";
        file_put_contents($rotatedLog, $rotLine1 . $rotLine2);

        // Write active log with 1 line
        $activeLine = "Sep 19 01:05:00 mail postfix/qmgr[100]: Q3: from=<e@f.com>, size=100\n";
        file_put_contents($activeLog, $activeLine);

        // Simulate cursor pointing after rotLine1 on the rotated file
        $rotStat = stat($rotatedLog);
        $savedInode = $rotStat['ino'];
        $savedOffset = strlen($rotLine1);

        // Create parser instance with custom cursor
        $customCursor = [
            'inode' => $savedInode,
            'offset' => $savedOffset,
        ];

        // Subclass or mock cursor if needed, or invoke parseFile
        // In PostfixLogParserService, getCursor reads from Redis, but we can verify parseLine and file logic
        // Let's create a test parser where getCursor returns $customCursor
        $testParser = new class(4096, 'test_key', $customCursor) extends PostfixLogParserService {
            public function __construct(int $len, string $k, private array $mockCursor) {
                parent::__construct($len, $k);
            }
            public function getCursor(): array {
                return $this->mockCursor;
            }
        };

        $result = $testParser->parseFile($activeLog, 100);

        // Cleanup files
        @unlink($activeLog);
        @unlink($rotatedLog);

        $this->assertCount(2, $result['events']);
        $this->assertEquals('Q2', $result['events'][0]->queueId);
        $this->assertEquals('Q3', $result['events'][1]->queueId);
        $this->assertNotNull($result['next_cursor']);
        $this->assertEquals(strlen($activeLine), $result['next_cursor']['offset']);
    }
}

