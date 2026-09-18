'use client';

import { useState } from 'react';
import { useTranslations } from 'next-intl';
import useSWR from 'swr';
import Link from 'next/link';
import api from '@/lib/api';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Spinner } from '@/components/ui/spinner';
import { PaginatedResponse, SmtpTenant } from '@/types';
import { Search, AlertTriangle, ExternalLink } from 'lucide-react';

export default function SmtpTenantsTable() {
  const t = useTranslations('Admin.smtp.tenants');
  const tCommon = useTranslations('Admin.common');

  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');

  const fetcher = (url: string) => api.get(url).then(res => res.data);
  const { data, error, isLoading } = useSWR<PaginatedResponse<SmtpTenant>>(
    `/api/admin/smtp/tenants?page=${page}&search=${encodeURIComponent(search)}`,
    fetcher
  );

  const getAbuseBadge = (status: SmtpTenant['abuse_status']) => {
    switch (status) {
      case 'healthy':
        return <Badge variant="success">{t('status.healthy')}</Badge>;
      case 'warning':
        return <Badge variant="warning">{t('status.warning')}</Badge>;
      case 'critical':
        return <Badge variant="destructive">{t('status.critical')}</Badge>;
      case 'unknown':
      default:
        return <Badge variant="secondary">{t('status.unknown')}</Badge>;
    }
  };

  const getBounceRateClass = (rate: number | null) => {
    if (rate === null) return 'text-gray-400';
    if (rate >= 10) return 'text-red-600 font-bold';
    if (rate >= 5) return 'text-yellow-600 font-semibold';
    return 'text-green-600';
  };

  return (
    <div className="space-y-4">
      {/* Search Header */}
      <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
          <h2 className="text-lg font-bold text-gray-900">{t('title')}</h2>
          <p className="text-xs text-gray-500">Live outbound quota consumption and bounce attribution per tenant</p>
        </div>
        <div className="relative w-full sm:w-72">
          <Search className="w-4 h-4 text-gray-400 absolute left-3 top-2.5" />
          <input
            type="text"
            placeholder={t('search_placeholder')}
            className="border border-gray-300 rounded-md pl-9 pr-4 py-2 text-sm w-full focus:ring-2 focus:ring-indigo-500 focus:outline-none"
            value={search}
            onChange={(e) => {
              setSearch(e.target.value);
              setPage(1);
            }}
          />
        </div>
      </div>

      {/* Table Card */}
      <Card>
        <CardContent className="p-0">
          <div className="overflow-x-auto">
            <table className="w-full text-sm text-left text-gray-500">
              <thead className="text-xs text-gray-700 uppercase bg-gray-50 border-b">
                <tr>
                  <th className="px-6 py-3">{t('table.tenant')}</th>
                  <th className="px-6 py-3">{t('table.plan')}</th>
                  <th className="px-6 py-3">{t('table.daily_quota')}</th>
                  <th className="px-6 py-3">{t('table.today_usage')}</th>
                  <th className="px-6 py-3">{t('table.hard_bounces')}</th>
                  <th className="px-6 py-3">{t('table.soft_bounces')}</th>
                  <th className="px-6 py-3">{t('table.bounce_rate')}</th>
                  <th className="px-6 py-3">{t('table.abuse_status')}</th>
                </tr>
              </thead>
              <tbody>
                {isLoading ? (
                  <tr>
                    <td colSpan={8} className="px-6 py-12 text-center">
                      <div className="flex justify-center"><Spinner /></div>
                    </td>
                  </tr>
                ) : error ? (
                  <tr>
                    <td colSpan={8} className="px-6 py-8 text-center text-red-500">
                      {tCommon('error')}
                    </td>
                  </tr>
                ) : data?.data && data.data.length > 0 ? (
                  data.data.map((tenant) => {
                    const usagePercent = tenant.daily_quota > 0 && tenant.today_recipients !== null
                      ? Math.min(Math.round((tenant.today_recipients / tenant.daily_quota) * 100), 100)
                      : 0;

                    return (
                      <tr key={tenant.id} className="bg-white border-b hover:bg-gray-50">
                        {/* Tenant */}
                        <td className="px-6 py-4">
                          <Link 
                            href={`/admin/tenants/${tenant.id}`}
                            className="font-medium text-gray-900 hover:text-indigo-600 flex items-center gap-1.5 group"
                          >
                            <span>{tenant.name}</span>
                            <ExternalLink className="w-3.5 h-3.5 opacity-0 group-hover:opacity-100 transition-opacity text-indigo-600" />
                          </Link>
                          <div className="text-xs text-gray-400">{tenant.email}</div>
                        </td>

                        {/* Plan */}
                        <td className="px-6 py-4">
                          <div className="font-medium text-gray-800">{tenant.plan_name}</div>
                          <span className={`text-[10px] font-semibold uppercase tracking-wider ${
                            tenant.is_subscription_active ? 'text-green-600' : 'text-amber-600'
                          }`}>
                            {tenant.is_subscription_active ? 'Active' : 'Inactive'}
                          </span>
                        </td>

                        {/* Daily Quota */}
                        <td className="px-6 py-4 font-mono text-gray-700">
                          {tenant.daily_quota === -1 ? 'Unlimited' : tenant.daily_quota.toLocaleString()}
                        </td>

                        {/* Today's Usage */}
                        <td className="px-6 py-4">
                          {tenant.telemetry_available ? (
                            <div className="w-36 space-y-1">
                              <div className="flex justify-between text-xs font-mono">
                                <span>{tenant.today_recipients ?? 0}</span>
                                <span className="text-gray-400">
                                  {tenant.daily_quota === -1 ? '∞' : tenant.daily_quota.toLocaleString()}
                                </span>
                              </div>
                              {tenant.daily_quota > 0 && (
                                <div className="w-full bg-gray-200 rounded-full h-1.5 overflow-hidden">
                                  <div
                                    className={`h-1.5 rounded-full transition-all ${
                                      usagePercent >= 90 
                                        ? 'bg-red-500' 
                                        : usagePercent >= 75 
                                        ? 'bg-yellow-500' 
                                        : 'bg-indigo-500'
                                    }`}
                                    style={{ width: `${usagePercent}%` }}
                                  />
                                </div>
                              )}
                            </div>
                          ) : (
                            <span className="text-gray-400 font-mono">N/A</span>
                          )}
                        </td>

                        {/* Hard Bounces */}
                        <td className="px-6 py-4 font-mono">
                          {tenant.telemetry_available ? (
                            <span className={tenant.today_hard_bounces && tenant.today_hard_bounces > 0 ? 'text-red-600 font-bold' : 'text-gray-600'}>
                              {tenant.today_hard_bounces ?? 0}
                            </span>
                          ) : (
                            <span className="text-gray-400">N/A</span>
                          )}
                        </td>

                        {/* Soft Bounces */}
                        <td className="px-6 py-4 font-mono">
                          {tenant.telemetry_available ? (
                            <span className={tenant.today_soft_bounces && tenant.today_soft_bounces > 0 ? 'text-amber-600 font-semibold' : 'text-gray-600'}>
                              {tenant.today_soft_bounces ?? 0}
                            </span>
                          ) : (
                            <span className="text-gray-400">N/A</span>
                          )}
                        </td>

                        {/* Bounce Rate */}
                        <td className="px-6 py-4 font-mono">
                          {tenant.telemetry_available && tenant.bounce_rate !== null ? (
                            <span className={getBounceRateClass(tenant.bounce_rate)}>
                              {tenant.bounce_rate}%
                            </span>
                          ) : (
                            <span className="text-gray-400">N/A</span>
                          )}
                        </td>

                        {/* Abuse Status */}
                        <td className="px-6 py-4">
                          <div className="space-y-1">
                            {getAbuseBadge(tenant.abuse_status)}
                            {tenant.active_alerts && tenant.active_alerts.length > 0 && (
                              <div className="flex items-center text-[11px] text-red-600 mt-1">
                                <AlertTriangle className="w-3 h-3 mr-1 shrink-0" />
                                <span>{tenant.active_alerts.length} alert{tenant.active_alerts.length > 1 ? 's' : ''}</span>
                              </div>
                            )}
                          </div>
                        </td>
                      </tr>
                    );
                  })
                ) : (
                  <tr>
                    <td colSpan={8} className="px-6 py-12 text-center text-gray-400">
                      No tenants found matching criteria.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </CardContent>
      </Card>

      {/* Pagination Controls */}
      {data && data.meta && data.meta.last_page > 1 && (
        <div className="flex justify-between items-center bg-white p-4 rounded-md shadow-sm border">
          <button
            disabled={page === 1}
            onClick={() => setPage(p => Math.max(p - 1, 1))}
            className="px-4 py-2 text-sm bg-gray-100 rounded disabled:opacity-50 hover:bg-gray-200 transition-colors"
          >
            Previous
          </button>
          <span className="text-sm text-gray-600">
            Page {page} of {data.meta.last_page}
          </span>
          <button
            disabled={page === data.meta.last_page}
            onClick={() => setPage(p => p + 1)}
            className="px-4 py-2 text-sm bg-gray-100 rounded disabled:opacity-50 hover:bg-gray-200 transition-colors"
          >
            Next
          </button>
        </div>
      )}
    </div>
  );
}
