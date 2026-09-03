'use client';

import { useTranslations } from 'next-intl';
import useSWR from 'swr';
import api from '@/lib/api';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Users, Server, Mail, DollarSign, Globe, FileText, Database } from 'lucide-react';
import { Spinner } from '@/components/ui/spinner';
import { formatCurrency } from '@/lib/utils';
import Link from 'next/link';

interface AdminStats {
  total_users: number;
  active_users: number;
  total_domains: number;
  active_domains: number;
  total_mailboxes: number;
  revenue_month: number;
  revenue_total: number;
  pending_invoices: number;
  recent_users: any[];
  recent_invoices: any[];
}

interface ServerStats {
  mail_queue_size: number;
  disk_usage_percent: number;
  imap_connections: number;
}

export default function AdminDashboardPage() {
  const t = useTranslations('Admin.dashboard');
  
  const fetcher = (url: string) => api.get(url).then(res => res.data);
  const { data: stats, error: statsError, isLoading: statsLoading } = useSWR<AdminStats>('/api/admin/stats', fetcher);
  const { data: serverStats, error: serverError, isLoading: serverLoading } = useSWR<ServerStats>('/api/admin/server-stats', fetcher);

  if (statsLoading) return <div className="flex h-64 items-center justify-center"><Spinner /></div>;
  if (statsError || !stats) return <div className="text-red-500">Failed to load admin stats.</div>;

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
            <p className="text-xs text-green-600 mt-1">{stats.active_users} {t('active_tenants')}</p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between pb-2">
            <CardTitle className="text-sm font-medium text-gray-500">{t('total_domains')}</CardTitle>
            <Globe className="h-4 w-4 text-indigo-600" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">{stats.total_domains}</div>
            <p className="text-xs text-green-600 mt-1">{stats.active_domains} {t('active_domains')}</p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between pb-2">
            <CardTitle className="text-sm font-medium text-gray-500">{t('active_mailboxes')}</CardTitle>
            <Mail className="h-4 w-4 text-indigo-600" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">{stats.total_mailboxes}</div>
            <p className="text-xs text-gray-500 mt-1">Across all domains</p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between pb-2">
            <CardTitle className="text-sm font-medium text-gray-500">{t('monthly_revenue')}</CardTitle>
            <DollarSign className="h-4 w-4 text-indigo-600" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">{formatCurrency(stats.revenue_month)}</div>
            <p className="text-xs text-gray-500 mt-1">{t('total_revenue')}: {formatCurrency(stats.revenue_total)}</p>
          </CardContent>
        </Card>
      </div>

      <div className="grid gap-6 md:grid-cols-2">
        <Card>
          <CardHeader className="flex flex-row justify-between items-center">
            <CardTitle>{t('recent_signups')}</CardTitle>
            <Link href="/admin/tenants" className="text-sm text-indigo-600 hover:underline">{t('view_all')}</Link>
          </CardHeader>
          <CardContent className="p-0">
            <div className="divide-y">
              {stats.recent_users.map(u => (
                <div key={u.id} className="p-4 flex justify-between items-center hover:bg-gray-50">
                  <div>
                    <p className="font-medium text-sm text-gray-900">{u.name}</p>
                    <p className="text-xs text-gray-500">{u.email}</p>
                  </div>
                  <span className="text-xs text-gray-400">{new Date(u.created_at).toLocaleDateString()}</span>
                </div>
              ))}
              {stats.recent_users.length === 0 && <div className="p-4 text-sm text-gray-500">No signups yet.</div>}
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row justify-between items-center">
            <CardTitle>{t('server_stats')}</CardTitle>
            <Server className="w-5 h-5 text-gray-400" />
          </CardHeader>
          <CardContent>
            {serverLoading ? (
              <div className="flex justify-center p-4"><Spinner /></div>
            ) : serverError || !serverStats ? (
              <div className="text-red-500 text-sm">Failed to load server stats.</div>
            ) : (
              <div className="space-y-4">
                <div className="flex justify-between items-center">
                  <div className="flex items-center text-sm font-medium text-gray-700">
                    <Mail className="w-4 h-4 mr-2" /> {t('mail_queue')}
                  </div>
                  <span className="text-sm text-gray-900">{serverStats.mail_queue_size} messages</span>
                </div>
                <div className="flex justify-between items-center">
                  <div className="flex items-center text-sm font-medium text-gray-700">
                    <Database className="w-4 h-4 mr-2" /> {t('disk_usage')}
                  </div>
                  <span className="text-sm text-gray-900">{serverStats.disk_usage_percent}%</span>
                </div>
                <div className="flex justify-between items-center">
                  <div className="flex items-center text-sm font-medium text-gray-700">
                    <Users className="w-4 h-4 mr-2" /> {t('imap_connections')}
                  </div>
                  <span className="text-sm text-gray-900">{serverStats.imap_connections}</span>
                </div>
              </div>
            )}
          </CardContent>
        </Card>
      </div>

    </div>
  );
}
