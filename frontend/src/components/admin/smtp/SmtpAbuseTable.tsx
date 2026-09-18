'use client';

import { useTranslations } from 'next-intl';
import useSWR from 'swr';
import Link from 'next/link';
import api from '@/lib/api';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Spinner } from '@/components/ui/spinner';
import { AbuseResponse, AbuseWarning } from '@/types';
import { 
  ShieldAlert, 
  CheckCircle2, 
  AlertCircle, 
  ExternalLink, 
  ArrowRight,
  RefreshCw
} from 'lucide-react';

interface SmtpAbuseTableProps {
  onSelectMailbox?: (email: string) => void;
  onRefresh?: () => void;
}

export default function SmtpAbuseTable({ onSelectMailbox, onRefresh }: SmtpAbuseTableProps) {
  const t = useTranslations('Admin.smtp.abuse');
  const tSmtp = useTranslations('Admin.smtp');
  const tCommon = useTranslations('Admin.common');

  const fetcher = (url: string) => api.get(url).then(res => res.data);
  const { data, error, isLoading, mutate } = useSWR<AbuseResponse>(
    '/api/admin/smtp/abuse',
    fetcher
  );

  const getAlertBadge = (type: string) => {
    switch (type) {
      case 'high_bounce_rate':
        return <Badge variant="destructive">High Bounce Rate</Badge>;
      case 'consecutive_hard_bounces':
        return <Badge variant="destructive">Consecutive Bounces</Badge>;
      case 'daily_hard_bounce_spike':
        return <Badge variant="warning">Daily Spike</Badge>;
      default:
        return <Badge variant="secondary">{type.replace(/_/g, ' ')}</Badge>;
    }
  };

  const handleRefresh = () => {
    mutate();
    if (onRefresh) onRefresh();
  };

  return (
    <div className="space-y-4">
      {/* Header */}
      <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
          <h2 className="text-lg font-bold text-gray-900">{t('title')}</h2>
          <p className="text-xs text-gray-500">{t('subtitle')}</p>
        </div>
        <button
          onClick={handleRefresh}
          disabled={isLoading}
          className="flex items-center px-3 py-1.5 text-xs font-medium text-gray-700 bg-white hover:bg-gray-50 border rounded-md transition-colors disabled:opacity-50"
        >
          <RefreshCw className={`w-3.5 h-3.5 mr-1.5 ${isLoading ? 'animate-spin' : ''}`} />
          Refresh Warnings
        </button>
      </div>

      {/* Telemetry Offline Warning */}
      {data && !data.telemetry_available && (
        <div className="p-4 bg-amber-50 border border-amber-200 rounded-lg flex items-start space-x-3 text-amber-800">
          <AlertCircle className="w-5 h-5 text-amber-600 shrink-0 mt-0.5" />
          <div className="text-sm">
            <p className="font-semibold">{tSmtp('telemetry_unavailable')}</p>
            <p className="mt-0.5">{tSmtp('telemetry_warning')}</p>
          </div>
        </div>
      )}

      {/* Content State */}
      {isLoading ? (
        <Card>
          <CardContent className="py-12 flex justify-center">
            <Spinner />
          </CardContent>
        </Card>
      ) : error ? (
        <Card>
          <CardContent className="py-8 text-center text-red-500">
            {tCommon('error')}
          </CardContent>
        </Card>
      ) : data?.warnings && data.warnings.length > 0 ? (
        /* Active Warnings Table */
        <Card>
          <CardContent className="p-0">
            <div className="overflow-x-auto">
              <table className="w-full text-sm text-left text-gray-500">
                <thead className="text-xs text-gray-700 uppercase bg-gray-50 border-b">
                  <tr>
                    <th className="px-6 py-3">{t('table.type')}</th>
                    <th className="px-6 py-3">{t('table.target')}</th>
                    <th className="px-6 py-3">{t('table.alert_type')}</th>
                    <th className="px-6 py-3">{t('table.current_vs_limit')}</th>
                    <th className="px-6 py-3">{t('table.details')}</th>
                    <th className="px-6 py-3 text-right">{t('table.actions')}</th>
                  </tr>
                </thead>
                <tbody>
                  {data.warnings.map((warn, index) => (
                    <tr key={`${warn.entity_type}-${warn.entity_id}-${warn.alert_type}-${index}`} className="bg-white border-b hover:bg-gray-50">
                      {/* Entity Type */}
                      <td className="px-6 py-4">
                        <Badge variant={warn.entity_type === 'tenant' ? 'default' : 'secondary'}>
                          {warn.entity_type.toUpperCase()}
                        </Badge>
                      </td>

                      {/* Target Identifier */}
                      <td className="px-6 py-4">
                        <div className="font-semibold text-gray-900">{warn.identifier}</div>
                        <div className="text-xs text-gray-400">{warn.name}</div>
                      </td>

                      {/* Alert Type */}
                      <td className="px-6 py-4">
                        {getAlertBadge(warn.alert_type)}
                      </td>

                      {/* Current vs Threshold */}
                      <td className="px-6 py-4 font-mono font-medium text-red-600">
                        {warn.current_value} / {warn.threshold}
                      </td>

                      {/* Details / Message */}
                      <td className="px-6 py-4 text-xs text-gray-600 max-w-xs">
                        {warn.message}
                      </td>

                      {/* Actions */}
                      <td className="px-6 py-4 text-right">
                        {warn.entity_type === 'mailbox' ? (
                          <button
                            onClick={() => onSelectMailbox && onSelectMailbox(warn.identifier)}
                            className="inline-flex items-center text-xs font-semibold text-indigo-600 hover:text-indigo-800 bg-indigo-50 hover:bg-indigo-100 px-3 py-1.5 rounded-md transition-colors"
                          >
                            <span>Inspect</span>
                            <ArrowRight className="w-3.5 h-3.5 ml-1" />
                          </button>
                        ) : (
                          <Link
                            href={`/admin/tenants/${warn.entity_id}`}
                            className="inline-flex items-center text-xs font-semibold text-indigo-600 hover:text-indigo-800 bg-indigo-50 hover:bg-indigo-100 px-3 py-1.5 rounded-md transition-colors"
                          >
                            <span>Tenant</span>
                            <ExternalLink className="w-3.5 h-3.5 ml-1" />
                          </Link>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </CardContent>
        </Card>
      ) : (
        /* Clean / No Warnings State */
        <Card className="border-green-200 bg-green-50/50">
          <CardContent className="py-12 flex flex-col items-center justify-center text-center space-y-3">
            <div className="p-3 bg-green-100 text-green-600 rounded-full">
              <CheckCircle2 className="w-8 h-8" />
            </div>
            <div>
              <h3 className="text-base font-bold text-gray-900">Cluster Deliverability Healthy</h3>
              <p className="text-xs text-gray-600 max-w-md mt-1">
                {t('no_warnings')}
              </p>
            </div>
          </CardContent>
        </Card>
      )}
    </div>
  );
}
