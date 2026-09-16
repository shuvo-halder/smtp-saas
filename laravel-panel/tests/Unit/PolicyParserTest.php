<?php

namespace Tests\Unit;

use App\Services\Policy\PostfixPolicyParser;
use PHPUnit\Framework\TestCase;
use OverflowException;

class PolicyParserTest extends TestCase
{
    private PostfixPolicyParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new PostfixPolicyParser();
    }

    public function test_parses_standard_postfix_policy_request()
    {
        $raw = "request=smtpd_access_policy\n"
             . "protocol_state=DATA\n"
             . "protocol_name=ESMTP\n"
             . "client_address=192.168.1.100\n"
             . "client_name=client.example.com\n"
             . "helo_name=mail.example.com\n"
             . "sender=sender@example.com\n"
             . "recipient_count=5\n"
             . "queue_id=4XYZ123\n"
             . "instance=1234.56789\n"
             . "sasl_method=PLAIN\n"
             . "sasl_username=user@example.com\n"
             . "sasl_sender=user@example.com\n\n";

        $requests = $this->parser->feed($raw);

        $this->assertCount(1, $requests);
        $req = $requests[0];

        $this->assertEquals('smtpd_access_policy', $req->request);
        $this->assertEquals('DATA', $req->protocolState);
        $this->assertEquals('ESMTP', $req->protocolName);
        $this->assertEquals('192.168.1.100', $req->clientAddress);
        $this->assertEquals('client.example.com', $req->clientName);
        $this->assertEquals('mail.example.com', $req->heloName);
        $this->assertEquals('sender@example.com', $req->sender);
        $this->assertEquals(5, $req->recipientCount);
        $this->assertEquals('4XYZ123', $req->queueId);
        $this->assertEquals('1234.56789', $req->instance);
        $this->assertEquals('PLAIN', $req->saslMethod);
        $this->assertEquals('user@example.com', $req->saslUsername);
        $this->assertTrue($req->isAuthenticated());
    }

    public function test_handles_crlf_line_endings()
    {
        $raw = "request=smtpd_access_policy\r\n"
             . "protocol_state=DATA\r\n"
             . "sasl_username=user@example.com\r\n"
             . "recipient_count=2\r\n\r\n";

        $requests = $this->parser->feed($raw);

        $this->assertCount(1, $requests);
        $req = $requests[0];
        $this->assertEquals('user@example.com', $req->saslUsername);
        $this->assertEquals(2, $req->recipientCount);
    }

    public function test_handles_streaming_partial_chunks()
    {
        $chunk1 = "request=smtpd_access_policy\nprotocol_state=DA";
        $chunk2 = "TA\nsasl_username=chunked@example.com\n\n";

        $reqs1 = $this->parser->feed($chunk1);
        $this->assertCount(0, $reqs1);

        $reqs2 = $this->parser->feed($chunk2);
        $this->assertCount(1, $reqs2);
        $this->assertEquals('chunked@example.com', $reqs2[0]->saslUsername);
    }

    public function test_handles_multiple_pipelined_requests()
    {
        $stream = "request=smtpd_access_policy\nsasl_username=first@example.com\n\n"
                . "request=smtpd_access_policy\nsasl_username=second@example.com\n\n";

        $requests = $this->parser->feed($stream);

        $this->assertCount(2, $requests);
        $this->assertEquals('first@example.com', $requests[0]->saslUsername);
        $this->assertEquals('second@example.com', $requests[1]->saslUsername);
    }

    public function test_defaults_recipient_count_to_one_when_missing_or_invalid()
    {
        $raw = "request=smtpd_access_policy\n"
             . "sasl_username=user@example.com\n"
             . "recipient_count=-5\n\n";

        $requests = $this->parser->feed($raw);
        $this->assertEquals(1, $requests[0]->recipientCount);

        $this->parser->clear();
        $raw2 = "request=smtpd_access_policy\n"
              . "sasl_username=user@example.com\n"
              . "recipient_count=not_a_number\n\n";

        $requests2 = $this->parser->feed($raw2);
        $this->assertEquals(1, $requests2[0]->recipientCount);
    }

    public function test_unauthenticated_request_detected()
    {
        $raw = "request=smtpd_access_policy\n"
             . "protocol_state=DATA\n"
             . "sender=inbound@remote.org\n\n";

        $requests = $this->parser->feed($raw);
        $this->assertFalse($requests[0]->isAuthenticated());
        $this->assertNull($requests[0]->saslUsername);
    }

    public function test_buffer_overflow_protection()
    {
        $oversized = str_repeat("a=b\n", 20000); // Exceeds 64 KB without terminator

        $this->expectException(OverflowException::class);
        $this->parser->feed($oversized);
    }
}
