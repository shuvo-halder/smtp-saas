<?php

namespace App\Console\Commands;

use App\Models\AbuseIncident;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Mailbox;
use App\Models\Plan;
use App\Models\TenantOutboundUsage;
use App\Models\User;
use App\Services\AuditService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SeedAuditDemoCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'emailsaas:seed-audit-demo
                            {--dry-run : Simulate execution without writing database records}
                            {--cleanup : Safely remove synthetic dummy records}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Seed or cleanup controlled dummy data in local development for integration and security audit.';

    /**
     * Explicit synthetic identifiers for safe cleanup and idempotency.
     */
    protected const DEMO_USER_EMAILS = [
        'audit-demo-active@example.test',
        'audit-demo-expired@example.test',
        'audit-demo-suspended@example.test',
        'audit-demo-noplan@example.test',
        'audit-demo-overquota@example.test',
    ];

    protected const DEMO_DOMAINS = [
        'audit-demo-active.example.test',
        'audit-demo-pending.example.test',
        'audit-demo-suspended.example.test',
    ];

    protected const DEMO_MAILBOX_EMAILS = [
        'sales@audit-demo-active.example.test',
        'inactive@audit-demo-active.example.test',
        'support@audit-demo-active.example.test',
        'bulk@audit-demo-active.example.test',
    ];

    protected const DEMO_INVOICE_NUMBERS = [
        'INV-AUDIT-DEMO-0001',
        'INV-AUDIT-DEMO-0002',
        'INV-AUDIT-DEMO-0003',
        'INV-AUDIT-DEMO-0004',
    ];

    protected const DEMO_ABUSE_IDEMPOTENCY_KEYS = [
        'audit-demo-abuse-1',
        'audit-demo-abuse-2',
    ];

    protected const DEMO_REQUEST_ID_PREFIX = 'audit-demo-req-';

    public function handle(AuditService $auditService): int
    {
        // 1. Strict environment verification
        if (!app()->environment('local')) {
            $this->error("Refusing execution: SeedAuditDemoCommand is strictly restricted to 'local' environment. Current: " . app()->environment());
            return 1;
        }

        // 2. Strict database host & database verification
        $defaultConn = config('database.default');
        $host = (string) config("database.connections.{$defaultConn}.host");
        $port = (int) config("database.connections.{$defaultConn}.port");
        $dbName = (string) config("database.connections.{$defaultConn}.database");

        $allowedHosts = ['127.0.0.1', 'localhost'];
        if (!in_array(strtolower($host), $allowedHosts, true) || $port !== 3306 || $dbName !== 'emailsaas_local') {
            $this->error("Refusing execution: Unapproved database configuration. Host: {$host}, Port: {$port}, Database: {$dbName}. Must be 127.0.0.1:3306/emailsaas_local.");
            return 1;
        }

        if ($host === '103.131.144.61') {
            $this->error("CRITICAL SAFETY HALT: Configured database host is remote production IP 103.131.144.61.");
            return 1;
        }

        try {
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $this->error("Database connection check failed: " . $e->getMessage());
            return 1;
        }

        $isDryRun = (bool) $this->option('dry-run');
        $isCleanup = (bool) $this->option('cleanup');

        $this->info("Environment: local | DB: {$host}:{$port}/{$dbName}");
        if ($isDryRun) {
            $this->warn("MODE: DRY RUN (Zero database mutations will be committed)");
        }

        if ($isCleanup) {
            return $this->handleCleanup($isDryRun);
        }

        return $this->handleSeed($isDryRun, $auditService);
    }

    /**
     * Safely seed synthetic dummy data.
     */
    protected function handleSeed(bool $isDryRun, AuditService $auditService): int
    {
        $this->info("Starting synthetic dummy data seeding...");

        $created = ['plans' => 0, 'users' => 0, 'domains' => 0, 'mailboxes' => 0, 'invoices' => 0, 'usage' => 0, 'incidents' => 0, 'audit_logs' => 0];
        $reused  = ['plans' => 0, 'users' => 0, 'domains' => 0, 'mailboxes' => 0, 'invoices' => 0, 'usage' => 0, 'incidents' => 0, 'audit_logs' => 0];

        // ─── A. Plans (Reuse canonical) ─────────────────────────────────────────
        $starterPlan    = Plan::where('slug', 'starter')->first();
        $businessPlan   = Plan::where('slug', 'business')->first();
        $enterprisePlan = Plan::where('slug', 'enterprise')->first();

        if (!$starterPlan || !$businessPlan || !$enterprisePlan) {
            $this->error("Required canonical plans (starter, business, enterprise) not found. Run PlanSeeder first.");
            return 1;
        }
        $reused['plans'] += 3;

        // ─── B. Tenant Accounts ──────────────────────────────────────────────────
        $tenantsData = [
            'active' => [
                'name'            => 'Audit Demo Active Tenant',
                'email'           => 'audit-demo-active@example.test',
                'password'        => Hash::make('DemoSecret123!'),
                'plan_id'         => $starterPlan->id,
                'status'          => 'active',
                'plan_expires_at' => Carbon::now()->addDays(30),
                'is_admin'        => false,
            ],
            'expired' => [
                'name'            => 'Audit Demo Expired Tenant',
                'email'           => 'audit-demo-expired@example.test',
                'password'        => Hash::make('DemoSecret123!'),
                'plan_id'         => $starterPlan->id,
                'status'          => 'active',
                'plan_expires_at' => Carbon::now()->subDays(5),
                'is_admin'        => false,
            ],
            'suspended' => [
                'name'            => 'Audit Demo Suspended Tenant',
                'email'           => 'audit-demo-suspended@example.test',
                'password'        => Hash::make('DemoSecret123!'),
                'plan_id'         => $starterPlan->id,
                'status'          => 'suspended',
                'plan_expires_at' => Carbon::now()->subDays(10),
                'is_admin'        => false,
            ],
            'noplan' => [
                'name'            => 'Audit Demo No Plan Tenant',
                'email'           => 'audit-demo-noplan@example.test',
                'password'        => Hash::make('DemoSecret123!'),
                'plan_id'         => null,
                'status'          => 'pending',
                'plan_expires_at' => null,
                'is_admin'        => false,
            ],
            'overquota' => [
                'name'            => 'Audit Demo Over Quota Tenant',
                'email'           => 'audit-demo-overquota@example.test',
                'password'        => Hash::make('DemoSecret123!'),
                'plan_id'         => $starterPlan->id,
                'status'          => 'active',
                'plan_expires_at' => Carbon::now()->addDays(30),
                'is_admin'        => false,
            ],
        ];

        $users = [];
        foreach ($tenantsData as $key => $data) {
            $existing = User::where('email', $data['email'])->first();
            if ($existing) {
                $reused['users']++;
                $users[$key] = $existing;
            } else {
                $created['users']++;
                if (!$isDryRun) {
                    $users[$key] = User::create($data);
                } else {
                    $users[$key] = new User($data);
                    $users[$key]->id = 9990 + $created['users'];
                }
            }
        }

        // ─── C. Domains ──────────────────────────────────────────────────────────
        $domainsData = [
            'active_domain' => [
                'user_id'       => $users['active']->id,
                'domain_name'   => 'audit-demo-active.example.test',
                'status'        => 'active',
                'mx_verified'   => true,
                'spf_verified'  => true,
                'dkim_verified' => true,
                'dmarc_verified'=> true,
                'activated_at'  => Carbon::now()->subDays(15),
            ],
            'pending_domain' => [
                'user_id'       => $users['active']->id,
                'domain_name'   => 'audit-demo-pending.example.test',
                'status'        => 'pending',
                'mx_verified'   => false,
                'spf_verified'  => false,
                'dkim_verified' => false,
                'dmarc_verified'=> false,
                'activated_at'  => null,
            ],
            'suspended_domain' => [
                'user_id'       => $users['suspended']->id,
                'domain_name'   => 'audit-demo-suspended.example.test',
                'status'        => 'suspended',
                'mx_verified'   => true,
                'spf_verified'  => true,
                'dkim_verified' => true,
                'dmarc_verified'=> false,
                'activated_at'  => Carbon::now()->subDays(60),
            ],
        ];

        $domains = [];
        foreach ($domainsData as $key => $data) {
            $existing = Domain::where('domain_name', $data['domain_name'])->first();
            if ($existing) {
                $reused['domains']++;
                $domains[$key] = $existing;
            } else {
                $created['domains']++;
                if (!$isDryRun) {
                    $domains[$key] = Domain::create($data);
                } else {
                    $domains[$key] = new Domain($data);
                    $domains[$key]->id = 9980 + $created['domains'];
                }
            }
        }

        // ─── D. Mailboxes ────────────────────────────────────────────────────────
        $activeDom = $domains['active_domain'];
        $dovecotPass = crypt('MailboxSecret123!', '$6$' . Str::random(16) . '$');

        $mailboxesData = [
            'sales' => [
                'domain_id'    => $activeDom->id,
                'local_part'   => 'sales',
                'email'        => 'sales@audit-demo-active.example.test',
                'password'     => $dovecotPass,
                'display_name' => 'Sales Department',
                'quota_mb'     => 1024,
                'is_active'    => true,
            ],
            'inactive' => [
                'domain_id'    => $activeDom->id,
                'local_part'   => 'inactive',
                'email'        => 'inactive@audit-demo-active.example.test',
                'password'     => $dovecotPass,
                'display_name' => 'Inactive Mailbox',
                'quota_mb'     => 1024,
                'is_active'    => false,
            ],
            'support' => [
                'domain_id'    => $activeDom->id,
                'local_part'   => 'support',
                'email'        => 'support@audit-demo-active.example.test',
                'password'     => $dovecotPass,
                'display_name' => 'Customer Support',
                'quota_mb'     => 512,
                'is_active'    => true,
            ],
            'bulk' => [
                'domain_id'    => $activeDom->id,
                'local_part'   => 'bulk',
                'email'        => 'bulk@audit-demo-active.example.test',
                'password'     => $dovecotPass,
                'display_name' => 'Bulk Mailer',
                'quota_mb'     => 1024,
                'is_active'    => true,
            ],
        ];

        $mailboxes = [];
        foreach ($mailboxesData as $key => $data) {
            $existing = Mailbox::where('email', $data['email'])->first();
            if ($existing) {
                $reused['mailboxes']++;
                $mailboxes[$key] = $existing;
            } else {
                $created['mailboxes']++;
                if (!$isDryRun) {
                    $mailboxes[$key] = Mailbox::create($data);
                } else {
                    $mailboxes[$key] = new Mailbox($data);
                    $mailboxes[$key]->id = 9970 + $created['mailboxes'];
                }
            }
        }

        // ─── E. Invoices ─────────────────────────────────────────────────────────
        $invoicesData = [
            'paid' => [
                'user_id'            => $users['active']->id,
                'plan_id'            => $starterPlan->id,
                'invoice_number'     => 'INV-AUDIT-DEMO-0001',
                'billing_cycle'      => 'monthly',
                'subtotal'           => 199.00,
                'tax'                => 0.00,
                'total'              => 199.00,
                'currency'           => 'BDT',
                'status'             => 'paid',
                'payment_gateway'    => 'sslcommerz',
                'transaction_id'     => 'TRAN-AUDIT-DEMO-PAID',
                'paid_at'            => Carbon::now()->subDays(1),
                'due_date'           => Carbon::now()->subDays(1),
                'period_start'       => Carbon::now()->subDays(1),
                'period_end'         => Carbon::now()->addDays(29),
            ],
            'pending' => [
                'user_id'            => $users['active']->id,
                'plan_id'            => $starterPlan->id,
                'invoice_number'     => 'INV-AUDIT-DEMO-0002',
                'billing_cycle'      => 'monthly',
                'subtotal'           => 199.00,
                'tax'                => 0.00,
                'total'              => 199.00,
                'currency'           => 'BDT',
                'status'             => 'pending',
                'payment_gateway'    => 'sslcommerz',
                'transaction_id'     => 'TRAN-AUDIT-DEMO-PENDING',
                'paid_at'            => null,
                'due_date'           => Carbon::now()->addDays(7),
                'period_start'       => Carbon::now()->addDays(29),
                'period_end'         => Carbon::now()->addDays(59),
            ],
            'failed' => [
                'user_id'            => $users['expired']->id,
                'plan_id'            => $starterPlan->id,
                'invoice_number'     => 'INV-AUDIT-DEMO-0003',
                'billing_cycle'      => 'monthly',
                'subtotal'           => 199.00,
                'tax'                => 0.00,
                'total'              => 199.00,
                'currency'           => 'BDT',
                'status'             => 'failed',
                'payment_gateway'    => 'sslcommerz',
                'transaction_id'     => 'TRAN-AUDIT-DEMO-FAILED',
                'paid_at'            => null,
                'due_date'           => Carbon::now()->subDays(6),
                'period_start'       => Carbon::now()->subDays(6),
                'period_end'         => Carbon::now()->addDays(24),
            ],
            'cancelled' => [
                'user_id'            => $users['expired']->id,
                'plan_id'            => $starterPlan->id,
                'invoice_number'     => 'INV-AUDIT-DEMO-0004',
                'billing_cycle'      => 'monthly',
                'subtotal'           => 199.00,
                'tax'                => 0.00,
                'total'              => 199.00,
                'currency'           => 'BDT',
                'status'             => 'cancelled',
                'payment_gateway'    => 'sslcommerz',
                'transaction_id'     => 'TRAN-AUDIT-DEMO-CANCELLED',
                'paid_at'            => null,
                'due_date'           => Carbon::now()->subDays(10),
                'period_start'       => Carbon::now()->subDays(10),
                'period_end'         => Carbon::now()->addDays(20),
            ],
        ];

        foreach ($invoicesData as $data) {
            $existing = Invoice::where('invoice_number', $data['invoice_number'])->first();
            if ($existing) {
                $reused['invoices']++;
            } else {
                $created['invoices']++;
                if (!$isDryRun) {
                    Invoice::create($data);
                }
            }
        }

        // ─── F. Tenant Outbound Usage ────────────────────────────────────────────
        $today = Carbon::now()->toDateString();
        $usageData = [
            [
                'user_id'         => $users['active']->id,
                'usage_date'      => $today,
                'recipient_count' => 50,
            ],
            [
                'user_id'         => $users['overquota']->id,
                'usage_date'      => $today,
                'recipient_count' => 550, // exceeds starter 500 limit
            ],
        ];

        foreach ($usageData as $u) {
            $existing = TenantOutboundUsage::where('user_id', $u['user_id'])
                ->where('usage_date', $u['usage_date'])
                ->first();
            if ($existing) {
                $reused['usage']++;
            } else {
                $created['usage']++;
                if (!$isDryRun) {
                    TenantOutboundUsage::create($u);
                }
            }
        }

        // ─── G. Abuse Incidents ──────────────────────────────────────────────────
        $admin = User::where('is_admin', true)->first();
        $incidentsData = [
            [
                'uuid'             => (string) Str::uuid(),
                'tenant_id'        => $users['overquota']->id,
                'tenant_email'     => $users['overquota']->email,
                'domain_id'        => $activeDom->id,
                'domain_name'      => $activeDom->domain_name,
                'mailbox_id'       => $mailboxes['bulk']->id ?? null,
                'mailbox_email'    => 'bulk@audit-demo-active.example.test',
                'incident_type'    => 'high_hard_bounce_rate',
                'severity'         => 'warning',
                'status'           => 'open',
                'detection_source' => 'mail_log_parser',
                'summary'          => 'Synthetic high bounce rate incident for audit demo',
                'threshold_value'  => 10.0,
                'observed_value'   => 18.5,
                'evidence'         => ['attempts' => 100, 'bounces' => 19],
                'idempotency_key'  => 'audit-demo-abuse-1',
                'occurred_at'      => Carbon::now()->subHours(2),
            ],
            [
                'uuid'             => (string) Str::uuid(),
                'tenant_id'        => $users['active']->id,
                'tenant_email'     => $users['active']->email,
                'domain_id'        => $activeDom->id,
                'domain_name'      => $activeDom->domain_name,
                'mailbox_id'       => $mailboxes['sales']->id ?? null,
                'mailbox_email'    => 'sales@audit-demo-active.example.test',
                'incident_type'    => 'consecutive_hard_bounces',
                'severity'         => 'critical',
                'status'           => 'resolved',
                'detection_source' => 'mail_log_parser',
                'summary'          => 'Synthetic resolved bounce streak for audit demo',
                'threshold_value'  => 15.0,
                'observed_value'   => 16.0,
                'evidence'         => ['consecutive_count' => 16],
                'idempotency_key'  => 'audit-demo-abuse-2',
                'occurred_at'      => Carbon::now()->subHours(6),
                'resolved_at'      => Carbon::now()->subHour(),
                'resolved_by'      => $admin?->id,
                'resolution_notes' => 'Synthetic resolution confirmed through audit seeder',
            ],
        ];

        foreach ($incidentsData as $inc) {
            $existing = AbuseIncident::where('idempotency_key', $inc['idempotency_key'])->first();
            if ($existing) {
                $reused['incidents']++;
            } else {
                $created['incidents']++;
                if (!$isDryRun) {
                    AbuseIncident::create($inc);
                }
            }
        }

        // ─── H. Audit Logs (Recorded via AuditService with secret scrubbing) ─────
        $auditEvents = [
            [
                'action'      => 'auth.login',
                'entity_type' => 'User',
                'entity_id'   => $users['active']->id,
                'before'      => null,
                'after'       => ['email' => $users['active']->email, 'status' => 'success'],
                'reason'      => '[AuditDemo] Synthetic successful login',
                'req_id'      => self::DEMO_REQUEST_ID_PREFIX . '1',
            ],
            [
                'action'      => 'auth.failed',
                'entity_type' => 'User',
                'entity_id'   => null,
                'before'      => null,
                'after'       => [
                    'attempted_email' => 'audit-demo-active@example.test',
                    'password'        => 'PasswordAttemptShouldBeScrubbed123!',
                    'status'          => 'invalid_credentials',
                ],
                'reason'      => '[AuditDemo] Synthetic failed login with credentials scrubbing check',
                'req_id'      => self::DEMO_REQUEST_ID_PREFIX . '2',
            ],
            [
                'action'      => 'security.authorization_denied',
                'entity_type' => 'User',
                'entity_id'   => $users['active']->id,
                'before'      => null,
                'after'       => ['endpoint' => '/api/admin/users', 'required_permission' => 'admin.users.read'],
                'reason'      => '[AuditDemo] Synthetic unauthorized admin perimeter access attempt',
                'req_id'      => self::DEMO_REQUEST_ID_PREFIX . '3',
            ],
            [
                'action'      => 'tenant.register',
                'entity_type' => 'User',
                'entity_id'   => $users['active']->id,
                'before'      => null,
                'after'       => ['email' => $users['active']->email, 'plan' => 'starter'],
                'reason'      => '[AuditDemo] Synthetic tenant self-registration',
                'req_id'      => self::DEMO_REQUEST_ID_PREFIX . '4',
            ],
            [
                'action'      => 'admin.users.suspend',
                'entity_type' => 'User',
                'entity_id'   => $users['suspended']->id,
                'before'      => ['status' => 'active'],
                'after'       => ['status' => 'suspended'],
                'reason'      => '[AuditDemo] Synthetic administrative tenant suspension',
                'req_id'      => self::DEMO_REQUEST_ID_PREFIX . '5',
            ],
            [
                'action'      => 'domain.create',
                'entity_type' => 'Domain',
                'entity_id'   => $activeDom->id,
                'before'      => null,
                'after'       => ['domain_name' => $activeDom->domain_name],
                'reason'      => '[AuditDemo] Synthetic domain registration',
                'req_id'      => self::DEMO_REQUEST_ID_PREFIX . '6',
            ],
            [
                'action'      => 'mailbox.create',
                'entity_type' => 'Mailbox',
                'entity_id'   => $mailboxes['sales']->id ?? 1,
                'before'      => null,
                'after'       => [
                    'email'         => 'sales@audit-demo-active.example.test',
                    'password'      => 'SecretPlainPasswordShouldBeRedacted!',
                    'password_hash' => '$6$rounds=5000$dummyhash',
                ],
                'reason'      => '[AuditDemo] Synthetic mailbox creation with password redaction check',
                'req_id'      => self::DEMO_REQUEST_ID_PREFIX . '7',
            ],
            [
                'action'      => 'billing.invoice_paid',
                'entity_type' => 'Invoice',
                'entity_id'   => 1,
                'before'      => ['status' => 'pending'],
                'after'       => ['status' => 'paid', 'transaction_id' => 'TRAN-AUDIT-DEMO-PAID'],
                'reason'      => '[AuditDemo] Synthetic invoice paid event',
                'req_id'      => self::DEMO_REQUEST_ID_PREFIX . '8',
            ],
            [
                'action'      => 'backup.verify',
                'entity_type' => 'backup',
                'entity_id'   => 'backup_demo.tar.gz.enc',
                'before'      => null,
                'after'       => ['verified' => true, 'cipher' => 'aes-256-gcm', 'authenticated' => true],
                'reason'      => '[AuditDemo] Synthetic backup verification success',
                'req_id'      => self::DEMO_REQUEST_ID_PREFIX . '9',
            ],
            [
                'action'      => 'admin.abuse_incident.resolve',
                'entity_type' => 'AbuseIncident',
                'entity_id'   => 2,
                'before'      => ['status' => 'open'],
                'after'       => ['status' => 'resolved'],
                'reason'      => '[AuditDemo] Synthetic abuse incident resolution',
                'req_id'      => self::DEMO_REQUEST_ID_PREFIX . '10',
            ],
        ];

        foreach ($auditEvents as $ev) {
            $existing = AuditLog::where('request_id', $ev['req_id'])->first();
            if ($existing) {
                $reused['audit_logs']++;
            } else {
                $created['audit_logs']++;
                if (!$isDryRun) {
                    $log = $auditService->record(
                        action: $ev['action'],
                        entityType: $ev['entity_type'],
                        entityId: $ev['entity_id'],
                        before: $ev['before'],
                        after: $ev['after'],
                        actor: $admin,
                        reason: $ev['reason']
                    );
                    if ($log) {
                        $log->request_id = $ev['req_id'];
                        $log->save();
                    }
                }
            }
        }

        // Summary table
        $this->newLine();
        $this->table(
            ['Entity', 'Created (New)', 'Reused (Existing)'],
            [
                ['Subscription Plans', $created['plans'], $reused['plans']],
                ['Tenant Users', $created['users'], $reused['users']],
                ['Domains', $created['domains'], $reused['domains']],
                ['Mailboxes', $created['mailboxes'], $reused['mailboxes']],
                ['Invoices', $created['invoices'], $reused['invoices']],
                ['Usage Records', $created['usage'], $reused['usage']],
                ['Abuse Incidents', $created['incidents'], $reused['incidents']],
                ['Audit Logs', $created['audit_logs'], $reused['audit_logs']],
            ]
        );

        $this->info("Synthetic dummy data seeding completed successfully.");
        return 0;
    }

    /**
     * Safely cleanup ONLY records created by this seeder.
     */
    protected function handleCleanup(bool $isDryRun): int
    {
        $this->warn("Starting safe cleanup of synthetic audit-demo records...");

        $targetUsers = User::whereIn('email', self::DEMO_USER_EMAILS)->get();
        $userIds = $targetUsers->pluck('id')->toArray();

        $targetMailboxes = Mailbox::whereIn('email', self::DEMO_MAILBOX_EMAILS)->get();
        $targetDomains   = Domain::whereIn('domain_name', self::DEMO_DOMAINS)->get();
        $targetInvoices  = Invoice::whereIn('invoice_number', self::DEMO_INVOICE_NUMBERS)->get();
        $targetIncidents = AbuseIncident::whereIn('idempotency_key', self::DEMO_ABUSE_IDEMPOTENCY_KEYS)->get();
        $targetUsage     = TenantOutboundUsage::whereIn('user_id', $userIds)->get();
        $targetAuditLogs = AuditLog::where('request_id', 'LIKE', self::DEMO_REQUEST_ID_PREFIX . '%')->get();

        $counts = [
            'mailboxes'   => $targetMailboxes->count(),
            'domains'     => $targetDomains->count(),
            'invoices'    => $targetInvoices->count(),
            'incidents'   => $targetIncidents->count(),
            'usage'       => $targetUsage->count(),
            'audit_logs'  => $targetAuditLogs->count(),
            'users'       => $targetUsers->count(),
        ];

        $this->table(
            ['Entity Target', 'Identified Records to Remove'],
            [
                ['Mailboxes (audit-demo emails)', $counts['mailboxes']],
                ['Domains (audit-demo domains)', $counts['domains']],
                ['Invoices (INV-AUDIT-DEMO-*)', $counts['invoices']],
                ['Abuse Incidents (audit-demo-abuse-*)', $counts['incidents']],
                ['Tenant Usage (audit-demo users)', $counts['usage']],
                ['Audit Logs (audit-demo-req-*)', $counts['audit_logs']],
                ['Users (audit-demo emails)', $counts['users']],
            ]
        );

        if ($isDryRun) {
            $this->warn("DRY RUN: Records identified above would be safely purged. No changes made.");
            return 0;
        }

        // Delete in safe relational sequence
        Mailbox::whereIn('email', self::DEMO_MAILBOX_EMAILS)->forceDelete();
        Domain::whereIn('domain_name', self::DEMO_DOMAINS)->forceDelete();
        Invoice::whereIn('invoice_number', self::DEMO_INVOICE_NUMBERS)->delete();
        AbuseIncident::whereIn('idempotency_key', self::DEMO_ABUSE_IDEMPOTENCY_KEYS)->delete();
        TenantOutboundUsage::whereIn('user_id', $userIds)->delete();
        AuditLog::where('request_id', 'LIKE', self::DEMO_REQUEST_ID_PREFIX . '%')->delete();
        User::whereIn('email', self::DEMO_USER_EMAILS)->forceDelete();

        $this->info("Safe cleanup completed successfully. Pre-existing records and production data preserved.");
        return 0;
    }
}
