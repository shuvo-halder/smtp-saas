<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\Mailbox;
use App\Models\Plan;
use App\Models\User;
use App\Services\Policy\PolicyDecisionService;
use App\Services\Policy\PolicyResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class PolicyDaemonIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $tenant;
    private Domain $domain;
    private Mailbox $mailbox;
    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plan = Plan::create([
            'name' => 'Starter Plan',
            'slug' => 'starter-plan',
            'max_domains' => 1,
            'max_mailboxes_per_domain' => 5,
            'storage_mb_per_mailbox' => 512,
            'max_aliases_per_domain' => 5,
            'price_monthly' => 10.00,
            'price_yearly' => 100.00,
            'daily_outbound_recipients' => 200,
            'mailbox_daily_outbound_recipients' => 50,
        ]);

        $this->tenant = User::factory()->create([
            'status' => 'active',
            'plan_id' => $this->plan->id,
            'plan_expires_at' => now()->addDays(30),
        ]);

        $this->domain = Domain::create([
            'user_id' => $this->tenant->id,
            'domain_name' => 'mycompany.com',
            'status' => 'active',
        ]);

        $this->mailbox = Mailbox::create([
            'domain_id' => $this->domain->id,
            'local_part' => 'support',
            'email' => 'support@mycompany.com',
            'password' => 'secret',
            'quota_mb' => 512,
            'is_active' => true,
        ]);
    }

    public function test_socket_protocol_exchange_allowed_request()
    {
        Redis::shouldReceive('eval')->andReturn(1);
        Redis::shouldReceive('get')->andReturn(null);
        Redis::shouldReceive('setex');

        // Spin up ephemeral TCP server
        $errno = 0;
        $errstr = '';
        $server = @stream_socket_server("tcp://127.0.0.1:0", $errno, $errstr);
        $this->assertNotFalse($server, "Failed to create test TCP server: {$errstr}");

        $serverName = stream_socket_get_name($server, false);

        // Connect client socket
        $client = @stream_socket_client("tcp://{$serverName}", $errno, $errstr, 2);
        $this->assertNotFalse($client, "Failed to connect test client: {$errstr}");

        $accepted = @stream_socket_accept($server, 2);
        $this->assertNotFalse($accepted, "Failed to accept client connection");

        // Send Postfix policy request
        $requestPayload = "request=smtpd_access_policy\n"
                        . "protocol_state=DATA\n"
                        . "sasl_username=support@mycompany.com\n"
                        . "recipient_count=3\n"
                        . "instance=test_inst_001\n\n";

        fwrite($client, $requestPayload);
        stream_socket_shutdown($client, STREAM_SHUT_WR);

        // Service handles accepted client socket
        $decisionService = app(PolicyDecisionService::class);
        $command = app(\App\Console\Commands\PolicyDaemonCommand::class);

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('handleClient');
        $method->setAccessible(true);

        $totalRequests = 0;
        $method->invokeArgs($command, [$accepted, $decisionService, &$totalRequests]);

        // Read response on client socket
        $response = stream_get_contents($client);

        fclose($client);
        fclose($server);

        $this->assertEquals(1, $totalRequests);
        $this->assertEquals("action=DUNNO\n\n", $response);
    }

    public function test_socket_protocol_exchange_quota_rejection()
    {
        Redis::shouldReceive('eval')->andReturn(0); // Quota exceeded
        Redis::shouldReceive('get')->andReturn(null);
        Redis::shouldReceive('setex');

        $errno = 0;
        $errstr = '';
        $server = @stream_socket_server("tcp://127.0.0.1:0", $errno, $errstr);
        $serverName = stream_socket_get_name($server, false);

        $client = @stream_socket_client("tcp://{$serverName}", $errno, $errstr, 2);
        $accepted = @stream_socket_accept($server, 2);

        $requestPayload = "request=smtpd_access_policy\n"
                        . "protocol_state=DATA\n"
                        . "sasl_username=support@mycompany.com\n"
                        . "recipient_count=999\n"
                        . "instance=test_inst_002\n\n";

        fwrite($client, $requestPayload);
        stream_socket_shutdown($client, STREAM_SHUT_WR);

        $decisionService = app(PolicyDecisionService::class);
        $command = app(\App\Console\Commands\PolicyDaemonCommand::class);

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('handleClient');
        $method->setAccessible(true);

        $totalRequests = 0;
        $method->invokeArgs($command, [$accepted, $decisionService, &$totalRequests]);

        $response = stream_get_contents($client);

        fclose($client);
        fclose($server);

        $this->assertEquals(1, $totalRequests);
        $this->assertEquals("action=" . PolicyResponse::REJECT_QUOTA . "\n\n", $response);
    }
}
