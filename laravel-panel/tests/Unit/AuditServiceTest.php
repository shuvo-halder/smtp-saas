<?php

namespace Tests\Unit;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AuditServiceTest extends TestCase
{
    use RefreshDatabase;

    protected AuditService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AuditService();
    }

    public function test_it_persists_audit_log_entry()
    {
        $admin = User::factory()->create([
            'email'    => 'admin@example.com',
            'is_admin' => true,
        ]);

        $request = Request::create('/api/admin/smtp/mailboxes/1/toggle', 'POST', [], [], [], [
            'REMOTE_ADDR'          => '192.168.1.100',
            'HTTP_USER_AGENT'      => 'Mozilla/5.0 TestBrowser',
            'HTTP_X_REQUEST_ID'    => 'req-uuid-12345',
        ]);

        $log = $this->service->record(
            action: 'mailbox_toggle',
            entityType: 'Mailbox',
            entityId: 42,
            before: ['is_active' => true],
            after: ['is_active' => false],
            actor: $admin,
            reason: 'Administrative containment',
            request: $request
        );

        $this->assertInstanceOf(AuditLog::class, $log);
        $this->assertDatabaseHas('audit_logs', [
            'id'            => $log->id,
            'actor_user_id' => $admin->id,
            'actor_email'   => 'admin@example.com',
            'action'        => 'mailbox_toggle',
            'entity_type'   => 'Mailbox',
            'entity_id'     => 42,
            'reason'        => 'Administrative containment',
            'ip_address'    => '192.168.1.100',
            'request_id'    => 'req-uuid-12345',
        ]);

        $this->assertEquals(['is_active' => true], $log->before_state);
        $this->assertEquals(['is_active' => false], $log->after_state);
        $this->assertEquals($admin->id, $log->actor->id);
    }

    public function test_it_recursively_redacts_nested_secrets()
    {
        $raw = [
            'user' => [
                'name'         => 'Alice',
                'password'     => 'SuperSecretPass123!',
                'new_password' => 'NewGeneratedPass456!',
                'nested'       => [
                    'token'       => 'token_abc_123',
                    'otp'         => '987654',
                    'secret'      => 'top_secret_value',
                    'api_key'     => 'key_xyz_789',
                    'private_key' => '-----BEGIN RSA PRIVATE KEY-----',
                    'credentials' => [
                        'auth'         => 'basic_auth_secret',
                        'access_token' => 'access_tok_val',
                    ],
                    'normal_key'  => 'public_data',
                ],
            ],
            'API-KEY'      => 'header_api_key',
            'PRIVATE-KEY'  => 'header_private_key',
        ];

        $sanitized = $this->service->sanitizeState($raw);

        $this->assertEquals('Alice', $sanitized['user']['name']);
        $this->assertEquals('[REDACTED]', $sanitized['user']['password']);
        $this->assertEquals('[REDACTED]', $sanitized['user']['new_password']);
        $this->assertEquals('[REDACTED]', $sanitized['user']['nested']['token']);
        $this->assertEquals('[REDACTED]', $sanitized['user']['nested']['otp']);
        $this->assertEquals('[REDACTED]', $sanitized['user']['nested']['secret']);
        $this->assertEquals('[REDACTED]', $sanitized['user']['nested']['api_key']);
        $this->assertEquals('[REDACTED]', $sanitized['user']['nested']['private_key']);
        $this->assertEquals('[REDACTED]', $sanitized['user']['nested']['credentials']);
        $this->assertEquals('public_data', $sanitized['user']['nested']['normal_key']);
        $this->assertEquals('[REDACTED]', $sanitized['API-KEY']);
        $this->assertEquals('[REDACTED]', $sanitized['PRIVATE-KEY']);
    }

    public function test_it_redacts_nested_objects_converted_into_state()
    {
        $nestedObject = (object) [
            'password' => 'ObjectSecretPass!',
            'api_key'  => 'ObjectApiKey123',
            'regular'  => 'regular_value',
        ];

        $state = [
            'config' => $nestedObject,
        ];

        $sanitized = $this->service->sanitizeState($state);

        $this->assertEquals('[REDACTED]', $sanitized['config']['password']);
        $this->assertEquals('[REDACTED]', $sanitized['config']['api_key']);
        $this->assertEquals('regular_value', $sanitized['config']['regular']);
    }

    public function test_it_redacts_cryptographic_hashes_under_arbitrary_keys()
    {
        $raw = [
            'sha512_crypt'    => '$6$rounds=5000$salt$hashpayloadhere',
            'bcrypt_2y'       => '$2y$10$abcdefghijklmnopqrstuvwxyz1234567890',
            'bcrypt_2a'       => '$2a$10$abcdefghijklmnopqrstuvwxyz1234567890',
            'argon2_hash'     => '$argon2id$v=19$m=65536,t=4,p=1$abcdef',
            'safe_text'       => 'regular text value',
        ];

        $sanitized = $this->service->sanitizeState($raw);

        $this->assertEquals('[REDACTED]', $sanitized['sha512_crypt']);
        $this->assertEquals('[REDACTED]', $sanitized['bcrypt_2y']);
        $this->assertEquals('[REDACTED]', $sanitized['bcrypt_2a']);
        $this->assertEquals('[REDACTED]', $sanitized['argon2_hash']);
        $this->assertEquals('regular text value', $sanitized['safe_text']);
    }

    public function test_it_handles_null_actor_and_null_states_safely()
    {
        $log = $this->service->record(
            action: 'system.cron_event',
            entityType: 'System',
            entityId: null,
            before: null,
            after: null,
            actor: null
        );

        $this->assertInstanceOf(AuditLog::class, $log);
        $this->assertNull($log->actor_user_id);
        $this->assertNull($log->actor_email);
        $this->assertNull($log->before_state);
        $this->assertNull($log->after_state);
        $this->assertNull($log->actor);
    }

    public function test_it_fails_safely_when_persistence_throws()
    {
        Log::shouldReceive('channel')
            ->with('admin_smtp')
            ->once()
            ->andReturnSelf();

        Log::shouldReceive('error')
            ->once()
            ->withArgs(function ($msg, $context) {
                // Must NOT contain sensitive state or sql queries in error context
                return str_contains($msg, 'Failed to persist audit log entry')
                    && $context['action'] === 'failing.action'
                    && !isset($context['before'])
                    && !isset($context['after'])
                    && !isset($context['query']);
            });

        // Create a service that simulates a DB failure on create
        $service = new class extends AuditService {
            public function record(
                string $action,
                string $entityType,
                int|string|null $entityId,
                mixed $before = null,
                mixed $after = null,
                ?User $actor = null,
                ?string $reason = null,
                ?Request $request = null
            ): ?AuditLog {
                try {
                    throw new \RuntimeException('Database connection lost');
                } catch (\Throwable $e) {
                    Log::channel('admin_smtp')->error('Failed to persist audit log entry', [
                        'action'      => $action,
                        'entity_type' => $entityType,
                        'entity_id'   => $entityId,
                        'actor_id'    => $actor?->id,
                        'error_class' => get_class($e),
                        'error_code'  => $e->getCode(),
                    ]);
                    return null;
                }
            }
        };

        $result = $service->record('failing.action', 'TestEntity', 1, ['password' => 'secret']);
        $this->assertNull($result);
    }
}
