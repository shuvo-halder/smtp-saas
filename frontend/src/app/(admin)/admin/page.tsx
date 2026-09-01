'use client';

import { useTranslations } from 'next-intl';
import useSWR from 'swr';
import { api } from '@/lib/api';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Users, Server, Mail, DollarSign } from 'lucide-react';
import { Spinner } from '@/components/ui/spinner';
import { formatCurrency } from '@/lib/utils';

interface AdminStats {
  total_users: number;
  active_users: number;
  total_domains: number;
  active_domains: number;
  total_mailboxes: number;
  revenue_month: number;
  revenue_total: number;
  pending_invoices: number;
}

export default function AdminDashboardPage() {
  const t = useTranslations('Admin.dashboard');
  
  const fetcher = (url: string) => api.get(url).then(res => res.data);
  const { data, error, isLoading } = useSWR<{ stats: AdminStats }>('/api/admin/stats', fetcher);

  if (isLoading) return <div className="flex h-64 items-center justify-center"><Spinner /></div>;
  if (error || !data) return <div className="text-red-500">Failed to load admin stats.</div>;

  const stats = data.stats;

  return (
    <div className="space-y-6 max-w-6xl mx-auto">
      <h1 className="text-2xl font-bold text-gray-900">{t('title')}</h1>
      
      <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
        <Card>
          <CardHeader className="flex flex-row items-center justify-between pb-2">
            <CardTitle className="text-sm font-medium text-gray-500">{t('total_tenants')}</CardTitle>
            <Users className="h-4 w-4 text-indigo-600" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">{stats.total_users}</div>
            <p className="text-xs text-green-600 mt-1">{stats.active_users} active</p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between pb-2">
            <CardTitle className="text-sm font-medium text-gray-500">{t('active_mailboxes')}</CardTitle>
            <Mail className="h-4 w-4 text-indigo-600" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">{stats.total_mailboxes}</div>
            <p className="text-xs text-gray-500 mt-1">Across {stats.active_domains} active domains</p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between pb-2">
            <CardTitle className="text-sm font-medium text-gray-500">{t('monthly_revenue')}</CardTitle>
            <DollarSign className="h-4 w-4 text-indigo-600" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">{formatCurrency(stats.revenue_month)}</div>
            <p className="text-xs text-gray-500 mt-1">Lifetime: {formatCurrency(stats.revenue_total)}</p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between pb-2">
            <CardTitle className="text-sm font-medium text-gray-500">{t('system_health')}</CardTitle>
            <Server className="h-4 w-4 text-indigo-600" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold text-green-600">Operational</div>
            <p className="text-xs text-gray-500 mt-1">All services running smoothly</p>
          </CardContent>
        </Card>
      </div>
    </div>
  );
}
