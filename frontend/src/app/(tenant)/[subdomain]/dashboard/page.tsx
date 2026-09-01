'use client';

import { useTranslations } from 'next-intl';
import useSWR from 'swr';
import { api } from '@/lib/api';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { Globe, Mail, HardDrive, CheckCircle2 } from 'lucide-react';
import { DashboardStats } from '@/types';

export default function TenantDashboardPage() {
  const t = useTranslations('Tenant.dashboard');
  
  const fetcher = (url: string) => api.get(url).then(res => res.data);
  const { data: stats, error, isLoading } = useSWR<DashboardStats>('/api/dashboard', fetcher);

  if (isLoading) return <div className="flex h-64 items-center justify-center"><Spinner /></div>;
  if (error || !stats) return <div className="text-red-500">Error loading dashboard</div>;

  const plan = stats.user.plan;
  const maxDomains = plan?.max_domains === -1 ? 'Unlimited' : (plan?.max_domains ?? 0);
  const maxMailboxes = plan?.max_mailboxes_per_domain === -1 ? 'Unlimited' : (plan?.max_mailboxes_per_domain ?? 0);

  return (
    <div className="space-y-6">
      <h1 className="text-2xl font-bold text-gray-900 tracking-tight">{t('title')}</h1>
      
      <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
        <Card className="shadow-sm">
          <CardHeader className="flex flex-row items-center justify-between pb-2">
            <CardTitle className="text-sm font-medium text-gray-500">{t('domains_connected')}</CardTitle>
            <Globe className="h-4 w-4 text-indigo-600" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold text-gray-900">{stats.domains_count}</div>
            <p className="text-xs text-gray-500 mt-1">
              Limit: {maxDomains}
            </p>
          </CardContent>
        </Card>

        <Card className="shadow-sm">
          <CardHeader className="flex flex-row items-center justify-between pb-2">
            <CardTitle className="text-sm font-medium text-gray-500">{t('active_mailboxes')}</CardTitle>
            <Mail className="h-4 w-4 text-indigo-600" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold text-gray-900">{stats.mailboxes_count}</div>
            <p className="text-xs text-gray-500 mt-1">
              Limit: {maxMailboxes} per domain
            </p>
          </CardContent>
        </Card>

        <Card className="shadow-sm">
          <CardHeader className="flex flex-row items-center justify-between pb-2">
            <CardTitle className="text-sm font-medium text-gray-500">{t('storage_used')}</CardTitle>
            <HardDrive className="h-4 w-4 text-indigo-600" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold text-gray-900">
               {/* Placeholder until storage stats are added to API */}
               0 GB
            </div>
            <p className="text-xs text-gray-500 mt-1">
              {plan?.storage_mb_per_mailbox ? `${(plan.storage_mb_per_mailbox / 1024).toFixed(1)} GB per mailbox` : 'N/A'}
            </p>
          </CardContent>
        </Card>

        <Card className="shadow-sm border-indigo-100 bg-indigo-50/30">
          <CardHeader className="flex flex-row items-center justify-between pb-2">
            <CardTitle className="text-sm font-medium text-indigo-700">{t('plan_status')}</CardTitle>
            <CheckCircle2 className="h-4 w-4 text-indigo-600" />
          </CardHeader>
          <CardContent>
            <div className="text-xl font-bold text-indigo-900">
               {plan?.name ?? 'Free Tier'}
            </div>
            <p className="text-xs text-indigo-600 mt-1">
              {stats.user.status === 'active' ? 'Active Subscription' : 'Pending/Suspended'}
            </p>
          </CardContent>
        </Card>
      </div>
      
      {/* Recent Activity / Quick Actions could go here */}
    </div>
  );
}
