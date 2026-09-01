'use client';

import { useDashboard } from '@/hooks/useDashboard';
import { StatsCard } from '@/components/dashboard/StatsCard';
import { QuickActions } from '@/components/dashboard/QuickActions';
import { Spinner } from '@/components/ui/spinner';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { formatDate } from '@/lib/utils';
import { Globe, Mail, Box, ShieldCheck, AlertCircle } from 'lucide-react';

export default function DashboardPage() {
  const { stats, isLoading, error } = useDashboard();

  if (isLoading) {
    return <div className="flex justify-center p-8"><Spinner className="h-8 w-8 text-primary" /></div>;
  }

  if (error || !stats) {
    return <div className="text-red-500 flex items-center gap-2"><AlertCircle className="h-5 w-5" /> Failed to load dashboard data.</div>;
  }

  const planStatusColor = stats.user.status === 'active' ? 'success' : stats.user.status === 'pending' ? 'warning' : 'destructive';

  return (
    <div className="space-y-6">
      <h1 className="text-2xl font-bold tracking-tight">Dashboard</h1>
      
      <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
        <StatsCard
          title="Total Domains"
          value={stats.domains_count}
          icon={Globe}
          description="Verified and active domains"
        />
        <StatsCard
          title="Total Mailboxes"
          value={stats.mailboxes_count}
          icon={Mail}
          description="Active email accounts"
        />
        <StatsCard
          title="Current Plan"
          value={stats.user.plan?.name || 'No Plan'}
          icon={Box}
          description={stats.user.plan ? `${stats.user.plan.max_domains_label}` : 'Upgrade to get started'}
        />
        <StatsCard
          title="Account Status"
          value={stats.user.status.charAt(0).toUpperCase() + stats.user.status.slice(1)}
          icon={ShieldCheck}
          description={stats.user.plan_expires_at ? `Expires ${formatDate(stats.user.plan_expires_at)}` : 'No expiry'}
        />
      </div>

      <QuickActions />

      <div className="grid gap-4 md:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle className="text-lg">Recent Domains</CardTitle>
          </CardHeader>
          <CardContent>
            {stats.recent_domains.length === 0 ? (
              <p className="text-sm text-muted-foreground text-center py-4">No domains added yet.</p>
            ) : (
              <div className="space-y-4">
                {stats.recent_domains.map((domain) => (
                  <div key={domain.id} className="flex items-center justify-between border-b pb-2 last:border-0 last:pb-0">
                    <div>
                      <p className="font-medium">{domain.domain_name}</p>
                      <p className="text-xs text-muted-foreground">{formatDate(domain.created_at)}</p>
                    </div>
                    <div className="flex items-center gap-4">
                      <span className="text-xs text-muted-foreground">{domain.mailboxes_count} mailboxes</span>
                      <Badge variant={domain.status === 'active' ? 'success' : domain.status === 'failed' ? 'destructive' : 'secondary'}>
                        {domain.status}
                      </Badge>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle className="text-lg">Recent Invoices</CardTitle>
          </CardHeader>
          <CardContent>
            {stats.recent_invoices.length === 0 ? (
              <p className="text-sm text-muted-foreground text-center py-4">No invoices yet.</p>
            ) : (
              <div className="space-y-4">
                {stats.recent_invoices.map((invoice) => (
                  <div key={invoice.id} className="flex items-center justify-between border-b pb-2 last:border-0 last:pb-0">
                    <div>
                      <p className="font-medium">{invoice.invoice_number}</p>
                      <p className="text-xs text-muted-foreground">{formatDate(invoice.created_at)}</p>
                    </div>
                    <div className="flex items-center gap-4">
                      <span className="font-medium">{invoice.formatted_total}</span>
                      <Badge variant={invoice.status === 'paid' ? 'success' : invoice.status === 'failed' ? 'destructive' : 'secondary'}>
                        {invoice.status}
                      </Badge>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </CardContent>
        </Card>
      </div>
    </div>
  );
}
