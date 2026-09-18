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
}
