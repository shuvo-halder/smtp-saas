<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Reconciliation\TenantUsageReconciliationService;
use Illuminate\Console\Command;

class TenantReconcileUsageCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenant:reconcile-usage
                            {--tenant= : Specific tenant ID or email to reconcile}
                            {--date= : UTC date in YYYY-MM-DD format (defaults to today)}
                            {--detailed : Display detailed tenant and mailbox telemetry}
                            {--json : Output results as raw JSON}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Read-only reconciliation of tenant outbound usage, quotas, subscriptions, and billing state.';

    public function handle(TenantUsageReconciliationService $service): int
    {
        $tenantInput = $this->option('tenant');
        $date = $this->option('date');
        $detailed = (bool) $this->option('detailed');
        $asJson = (bool) $this->option('json');

        $tenantId = null;
        if ($tenantInput !== null) {
            if (is_numeric($tenantInput)) {
                $tenantId = (int) $tenantInput;
            } else {
                $user = User::where('email', $tenantInput)->first();
                if (!$user) {
                    $this->error("Tenant with email '{$tenantInput}' not found.");
                    return 1;
                }
                $tenantId = $user->id;
            }
        }

        if ($date !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $this->error("Invalid date format '{$date}'. Expected YYYY-MM-DD.");
            return 1;
        }

        $report = $service->reconcile($tenantId, $date);

        if ($asJson) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return 0;
        }

        $this->info("=== Tenant Usage, Quota & Billing Reconciliation ===");
        $this->line("Checked At:     " . $report['checked_at']);
        $this->line("Target Date:    " . $report['reconciliation_date']);
        $this->line("Redis Live:     " . ($report['telemetry_available'] ? 'YES' : 'NO (Graceful degradation)'));
        $this->newLine();

        $summary = $report['summary'];
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Tenants Checked', $summary['total_tenants_checked']],
                ['Clean Tenants', $summary['clean_tenants_count']],
                ['Tenants with Discrepancies', $summary['tenants_with_discrepancies']],
                ['Over-Quota Tenants', $summary['over_quota_tenants_count']],
                ['Subscription Issues', $summary['subscription_issues_count']],
                ['Billing / Invoice Issues', $summary['billing_issues_count']],
                ['Total Discrepancies', $summary['total_discrepancies_count']],
                ['Overall Status', $summary['is_healthy'] ? 'HEALTHY' : 'ATTENTION REQUIRED'],
            ]
        );

        if (!empty($report['discrepancies'])) {
            $this->newLine();
            $this->warn("=== Discrepancy Items ({$summary['total_discrepancies_count']}) ===");

            $discrepancyRows = [];
            foreach ($report['discrepancies'] as $d) {
                $discrepancyRows[] = [
                    $d['tenant_id'],
                    $d['tenant_email'],
                    strtoupper($d['severity']),
                    $d['code'],
                    $d['message'],
                ];
            }

            $this->table(
                ['Tenant ID', 'Email', 'Severity', 'Code', 'Summary'],
                $discrepancyRows
            );
        } else {
            $this->newLine();
            $this->info("No discrepancies detected. All tenant quotas, usage, subscriptions, and billing states are consistent.");
        }

        if ($detailed && !empty($report['tenants'])) {
            $this->newLine();
            $this->info("=== Detailed Tenant Status ===");

            $tenantRows = [];
            foreach ($report['tenants'] as $t) {
                $tenantRows[] = [
                    $t['tenant_id'],
                    $t['email'],
                    $t['status'],
                    $t['subscription_state'],
                    $t['daily_quota'] === -1 ? 'Unlimited' : $t['daily_quota'],
                    $t['effective_usage'],
                    $t['remaining_daily_quota'] === -1 ? 'Unlimited' : ($t['remaining_daily_quota'] ?? 'N/A'),
                    $t['is_over_quota'] ? 'YES' : 'NO',
                    $t['issues_count'],
                ];
            }

            $this->table(
                ['ID', 'Email', 'Status', 'Sub State', 'Quota', 'Used Today', 'Remaining', 'Over Quota', 'Issues'],
                $tenantRows
            );
        }

        return 0;
    }
}
