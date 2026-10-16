<?php

namespace App\Services;

use App\Http\Resources\DeliveryLogResource;
use App\Models\AbuseIncident;
use App\Models\DeliveryLog;
use App\Models\Mailbox;
use App\Models\TenantOutboundUsage;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Throwable;

class TenantSmtpService
{
    public function __construct(
        protected ?AuditService $auditService = null
    ) {
        $this->auditService = $auditService ?? app(AuditService::class);
    }

    /**
     * Get server connection parameters and copyable code snippets.
     */
    public function getSettings(User $tenant): array
    {
        $host = Config::get('mail.smtp_host')
            ?: env('SMTP_SERVER_HOST')
            ?: (Config::get('mail.mailers.smtp.host') === '127.0.0.1' ? 'mail.mailsaas.com' : Config::get('mail.mailers.smtp.host'));

        $ports = [
            [
                'port'        => 587,
                'protocol'    => 'STARTTLS',
                'recommended' => true,
                'description' => 'Recommended standard submission port with opportunistic/mandatory TLS 1.2+ encryption upgrade.',
            ],
            [
                'port'        => 465,
                'protocol'    => 'SMTPS (SSL/TLS)',
                'recommended' => false,
                'description' => 'Implicit TLS encrypted submission wrapper.',
            ],
            [
                'port'        => 25,
                'protocol'    => 'SMTP / Plain',
                'recommended' => false,
                'description' => 'Standard relay port. Frequently blocked by residential and cloud ISPs for outbound client mail.',
            ],
        ];

        $firstMailbox = $tenant->mailboxes()->where('mailboxes.is_active', true)->first();
        $sampleEmail = $firstMailbox ? $firstMailbox->email : 'user@yourdomain.com';

        return [
            'hostname'            => $host,
            'ports'               => $ports,
            'authentication'      => 'Dovecot SASL (PLAIN, LOGIN)',
            'username_format'     => 'Full mailbox email address (e.g., ' . $sampleEmail . ')',
            'password_format'     => 'Mailbox password (generated or set in Mailboxes)',
            'tls_minimum_version' => 'TLS 1.2+',
            'code_snippets'       => [
                'nodejs' => $this->getNodeJsSnippet($host, $sampleEmail),
                'python' => $this->getPythonSnippet($host, $sampleEmail),
                'php'    => $this->getPhpSnippet($host, $sampleEmail),
                'env'    => $this->getEnvSnippet($host, $sampleEmail),
            ],
        ];
    }

    /**
     * Get tenant quota telemetry, current recipient counts, and history.
     */
    public function getUsage(User $tenant): array
    {
        $plan = $tenant->plan;
        $dailyLimit = $plan?->daily_outbound_recipients ?? -1;
        $mailboxDailyLimit = $plan?->mailbox_daily_outbound_recipients ?? -1;

        $today = Carbon::now('UTC')->format('Y-m-d');
        $tenantKey = "outbound:tenant:{$tenant->id}:recipients:daily:{$today}";

        $usedToday = 0;
        try {
            $val = Redis::get($tenantKey);
            $usedToday = $val !== null && $val !== false ? (int) $val : 0;
        } catch (Throwable $e) {
            Log::warning('TenantSmtpService: Failed to read live Redis usage', ['tenant_id' => $tenant->id, 'error' => $e->getMessage()]);
        }

        $remainingToday = null;
        $percentUsed = 0.0;
        if ($dailyLimit > 0) {
            $remainingToday = max(0, $dailyLimit - $usedToday);
            $percentUsed = round(min(100.0, ($usedToday / $dailyLimit) * 100), 1);
        } elseif ($dailyLimit === 0) {
            $remainingToday = 0;
            $percentUsed = 100.0;
        }

        // 14-day historical trend
        $historyStart = Carbon::now('UTC')->subDays(13)->format('Y-m-d');
        $historicalRecords = TenantOutboundUsage::where('user_id', $tenant->id)
            ->where('usage_date', '>=', $historyStart)
            ->orderBy('usage_date')
            ->pluck('recipient_count', 'usage_date')
            ->toArray();

        $history = [];
        for ($i = 13; $i >= 0; $i--) {
            $date = Carbon::now('UTC')->subDays($i)->format('Y-m-d');
            $count = $date === $today ? $usedToday : ($historicalRecords[$date] ?? 0);
            $history[] = [
                'date'            => $date,
                'recipient_count' => $count,
                'is_today'        => $date === $today,
            ];
        }

        // Mailboxes breakdown
        $mailboxes = $tenant->mailboxes()->with('domain')->get();
        $mailboxesBreakdown = [];
        foreach ($mailboxes as $mb) {
            $mbKey = "outbound:mailbox:{$mb->id}:recipients:daily:{$today}";
            $mbUsed = 0;
            try {
                $mbVal = Redis::get($mbKey);
                $mbUsed = $mbVal !== null && $mbVal !== false ? (int) $mbVal : 0;
            } catch (Throwable) {
                $mbUsed = 0;
            }

            $mailboxesBreakdown[] = [
                'id'                  => $mb->id,
                'email'               => $mb->email,
                'display_name'        => $mb->display_name,
                'is_active'           => $mb->is_active,
                'used_today'          => $mbUsed,
                'mailbox_daily_limit' => $mailboxDailyLimit,
            ];
        }

        return [
            'plan_name'            => $plan?->name ?? 'Free / Standard',
            'daily_limit'          => $dailyLimit,
            'is_unlimited'         => $dailyLimit === -1,
            'mailbox_daily_limit'  => $mailboxDailyLimit,
            'used_today'           => $usedToday,
            'remaining_today'      => $remainingToday,
            'percent_used'         => $percentUsed,
            'history'              => $history,
            'mailboxes_breakdown'  => $mailboxesBreakdown,
        ];
    }

    /**
     * List authorized SMTP identities (mailboxes).
     */
    public function getCredentials(User $tenant): array
    {
        $mailboxes = $tenant->mailboxes()
            ->with('domain')
            ->whereHas('domain', function ($query) {
                $query->where('status', 'active');
            })
            ->latest('id')
            ->get();

        return $mailboxes->map(function (Mailbox $mb) {
            return [
                'id'            => $mb->id,
                'domain_id'     => $mb->domain_id,
                'domain_name'   => $mb->domain?->domain_name,
                'email'         => $mb->email,
                'display_name'  => $mb->display_name,
                'is_active'     => (bool) $mb->is_active,
                'last_login_at' => $mb->last_login_at?->toIso8601String(),
                'created_at'    => $mb->created_at?->toIso8601String(),
            ];
        })->toArray();
    }

    /**
     * Rotate mailbox password securely with a one-time plaintext response.
     */
    public function rotatePassword(User $tenant, Mailbox $mailbox, ?Request $request = null): array
    {
        // Ownership verification
        if ((int) ($mailbox->domain?->user_id ?? 0) !== (int) $tenant->id) {
            abort(403, 'Unauthorized access to this mailbox.');
        }

        // Generate 16-character secure random password with mixed cases and numbers
        $plainPassword = Str::password(16, true, true, false, false);

        // Dovecot SHA512-CRYPT format ($6$salt$)
        $salt = Str::random(16);
        $hashed = crypt($plainPassword, '$6$' . $salt . '$');

        $mailbox->update([
            'password' => $hashed,
        ]);

        $this->auditService->record(
            action: 'smtp.credentials.rotate_password',
            entityType: 'Mailbox',
            entityId: $mailbox->id,
            before: null,
            after: [
                'email'            => $mailbox->email,
                'password_rotated' => true,
                'outcome'          => 'success',
            ],
            actor: $tenant,
            reason: 'Tenant rotated SMTP credentials password',
            request: $request
        );

        return [
            'message'            => 'Mailbox password rotated successfully.',
            'email'              => $mailbox->email,
            'generated_password' => $plainPassword,
            'warning'            => 'This password is displayed strictly once. Copy and store it securely.',
        ];
    }

    /**
     * Query tenant delivery activity logs with bounded pagination.
     */
    public function getActivityLogs(User $tenant, Request $request)
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 50);

        $query = DeliveryLog::forTenant($tenant->id)
            ->byStatus($request->query('status'))
            ->search($request->query('search'));

        if ($request->filled('mailbox_id')) {
            $query->where('mailbox_id', (int) $request->query('mailbox_id'));
        }

        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');
        if (!empty($dateFrom) || !empty($dateTo)) {
            $query->dateBetween(
                $dateFrom ? Carbon::parse($dateFrom)->startOfDay() : null,
                $dateTo ? Carbon::parse($dateTo)->endOfDay() : null
            );
        }

        $logs = $query->latest('occurred_at')->paginate($perPage);

        return DeliveryLogResource::collection($logs);
    }

    /**
     * Run account-level configuration diagnostics (Zero SSRF risk).
     */
    public function getDiagnostics(User $tenant): array
    {
        $checks = [];

        // 1. Subscription Check
        $subActive = $tenant->isSubscriptionActive();
        $checks[] = [
            'key'         => 'subscription',
            'title'       => 'Subscription Status',
            'status'      => $subActive ? 'pass' : 'fail',
            'summary'     => $subActive ? 'Subscription is active' : 'Subscription is suspended or expired',
            'remediation' => $subActive ? null : 'Renew or upgrade your billing plan in the Billing section to resume SMTP sending.',
        ];

        // 2. Verified Domains Check
        $activeDomains = $tenant->domains()->where('status', 'active')->count();
        $checks[] = [
            'key'         => 'domains',
            'title'       => 'Verified Sender Domains',
            'status'      => $activeDomains > 0 ? 'pass' : 'fail',
            'summary'     => $activeDomains > 0
                ? "{$activeDomains} verified domain(s) active"
                : 'No verified domain found',
            'remediation' => $activeDomains > 0 ? null : 'Add a domain and verify its DNS records (SPF, DKIM, MX) under Domains.',
        ];

        // 3. Active Mailboxes Check
        $activeMailboxes = $tenant->mailboxes()->where('mailboxes.is_active', true)->count();
        $checks[] = [
            'key'         => 'mailboxes',
            'title'       => 'Active SMTP Sender Identities',
            'status'      => $activeMailboxes > 0 ? 'pass' : 'fail',
            'summary'     => $activeMailboxes > 0
                ? "{$activeMailboxes} active mailbox(es) available for authentication"
                : 'No active mailboxes available',
            'remediation' => $activeMailboxes > 0 ? null : 'Create at least one mailbox under a verified domain to use as SMTP credentials.',
        ];

        // 4. Quota Headroom Check
        $dailyLimit = $tenant->plan?->daily_outbound_recipients ?? -1;
        $today = Carbon::now('UTC')->format('Y-m-d');
        $usedToday = 0;
        try {
            $val = Redis::get("outbound:tenant:{$tenant->id}:recipients:daily:{$today}");
            $usedToday = $val !== null && $val !== false ? (int) $val : 0;
        } catch (Throwable) {
            $usedToday = 0;
        }

        $quotaStatus = 'pass';
        $quotaSummary = 'Outbound recipient quota has available capacity';
        $quotaRemediation = null;
        if ($dailyLimit > 0 && $usedToday >= $dailyLimit) {
            $quotaStatus = 'fail';
            $quotaSummary = "Daily outbound quota exhausted ({$usedToday}/{$dailyLimit} recipients used today)";
            $quotaRemediation = 'Daily quota resets at 00:00 UTC, or you can upgrade your subscription plan for higher capacity.';
        } elseif ($dailyLimit > 0 && ($usedToday / $dailyLimit) >= 0.85) {
            $quotaStatus = 'warning';
            $quotaSummary = "Daily quota near exhaustion ({$usedToday}/{$dailyLimit} recipients used today)";
        }

        $checks[] = [
            'key'         => 'quota',
            'title'       => 'Daily Outbound Quota',
            'status'      => $quotaStatus,
            'summary'     => $quotaSummary,
            'remediation' => $quotaRemediation,
        ];

        // 5. Deliverability & Bounce Health
        $activeIncidents = AbuseIncident::where('tenant_id', $tenant->id)
            ->where('status', 'open')
            ->count();

        $deliverabilityStatus = $activeIncidents === 0 ? 'pass' : 'warning';
        $deliverabilitySummary = $activeIncidents === 0
            ? 'No deliverability or bounce rate warnings'
            : "{$activeIncidents} active deliverability warning(s) flagged on account";
        $deliverabilityRemediation = $activeIncidents === 0 ? null : 'Review your recipient lists to prune invalid addresses and reduce bounce rates.';

        $checks[] = [
            'key'         => 'deliverability',
            'title'       => 'Deliverability & Reputation',
            'status'      => $deliverabilityStatus,
            'summary'     => $deliverabilitySummary,
            'remediation' => $deliverabilityRemediation,
        ];

        // Determine overall status
        $hasFail = collect($checks)->contains('status', 'fail');
        $hasWarning = collect($checks)->contains('status', 'warning');
        $overallStatus = $hasFail ? 'error' : ($hasWarning ? 'warning' : 'healthy');

        return [
            'status' => $overallStatus,
            'checks' => $checks,
            'common_errors' => [
                [
                    'code'        => '535 5.7.8 Error: authentication failed',
                    'cause'       => 'Incorrect username or password. The username must be the full email address.',
                    'remediation' => 'Verify your credentials in SMTP Access > Credentials or rotate your password.',
                ],
                [
                    'code'        => '554 5.7.1 Outbound SMTP recipient quota exceeded',
                    'cause'       => 'Tenant daily recipient quota or mailbox daily limit has been exceeded.',
                    'remediation' => 'Wait until 00:00 UTC for the daily counter reset or upgrade your subscription plan.',
                ],
                [
                    'code'        => '451 4.4.1 / 452 4.2.2 Deferred message',
                    'cause'       => 'Destination mail server is temporarily busy, greylisting, or recipient mailbox is full.',
                    'remediation' => 'Postfix will automatically retry delivery up to the server queue lifetime.',
                ],
                [
                    'code'        => '550 5.1.1 User unknown / Host not found',
                    'cause'       => 'The recipient email address or domain does not exist (Hard Bounce).',
                    'remediation' => 'Immediately prune non-existent email addresses from your mailing list.',
                ],
            ],
        ];
    }

    protected function getNodeJsSnippet(string $host, string $sampleEmail): string
    {
        return <<<NODE
const nodemailer = require("nodemailer");

const transporter = nodemailer.createTransport({
  host: "{$host}",
  port: 587,
  secure: false, // true for 465, false for 587 (STARTTLS)
  auth: {
    user: "{$sampleEmail}",
    pass: process.env.SMTP_PASSWORD,
  },
});

async function main() {
  const info = await transporter.sendMail({
    from: '"Support" <{$sampleEmail}>',
    to: "recipient@example.com",
    subject: "Hello via EmailSaaS SMTP",
    text: "This is a test message sent via EmailSaaS.",
  });
  console.log("Message sent: %s", info.messageId);
}
main().catch(console.error);
NODE;
    }

    protected function getPythonSnippet(string $host, string $sampleEmail): string
    {
        return <<<PYTHON
import smtplib
from email.message import EmailMessage

msg = EmailMessage()
msg.set_content("This is a test message sent via EmailSaaS.")
msg["Subject"] = "Hello via EmailSaaS SMTP"
msg["From"] = "{$sampleEmail}"
msg["To"] = "recipient@example.com"

# Connect via Port 587 with STARTTLS
with smtplib.SMTP("{$host}", 587) as server:
    server.starttls()
    server.login("{$sampleEmail}", "YOUR_SMTP_PASSWORD")
    server.send_message(msg)
    print("Email sent successfully!")
PYTHON;
    }

    protected function getPhpSnippet(string $host, string $sampleEmail): string
    {
        return <<<PHP
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

\$mail = new PHPMailer(true);
\$mail->isSMTP();
\$mail->Host       = '{$host}';
\$mail->SMTPAuth   = true;
\$mail->Username   = '{$sampleEmail}';
\$mail->Password   = getenv('SMTP_PASSWORD');
\$mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
\$mail->Port       = 587;

\$mail->setFrom('{$sampleEmail}', 'Mailer');
\$mail->addAddress('recipient@example.com');
\$mail->Subject = 'Hello via EmailSaaS SMTP';
\$mail->Body    = 'This is a test message sent via EmailSaaS.';

\$mail->send();
PHP;
    }

    protected function getEnvSnippet(string $host, string $sampleEmail): string
    {
        return <<<ENV
MAIL_MAILER=smtp
MAIL_HOST={$host}
MAIL_PORT=587
MAIL_USERNAME={$sampleEmail}
MAIL_PASSWORD=YOUR_SMTP_PASSWORD
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS={$sampleEmail}
MAIL_FROM_NAME="\${APP_NAME}"
ENV;
    }
}
