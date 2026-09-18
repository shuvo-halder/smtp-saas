'use client';

import { useTranslations } from 'next-intl';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { SmtpOverview as SmtpOverviewType } from '@/types';
import { 
  Send, 
  AlertTriangle, 
  RotateCcw, 
  Percent, 
  Layers, 
  ShieldAlert, 
  Users, 
  Mail, 
  Info, 
  CheckCircle2, 
  RefreshCw, 
  AlertCircle 
} from 'lucide-react';

interface SmtpOverviewProps {
  overview?: SmtpOverviewType;
  isLoading: boolean;
  onRefresh?: () => void;
}

export default function SmtpOverview({ overview, isLoading, onRefresh }: SmtpOverviewProps) {
  const t = useTranslations('Admin.smtp');

  const telemetryAvailable = overview?.telemetry_available ?? false;

  const getBounceRateColor = (rate: number | null | undefined) => {
    if (rate === null || rate === undefined) return 'text-gray-500';
    if (rate >= 10) return 'text-red-600';
    if (rate >= 5) return 'text-yellow-600';
    return 'text-green-600';
  };

  return (
    <div className="space-y-6">
      {/* Telemetry Status Bar */}
      <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center bg-white p-4 rounded-lg border shadow-sm gap-4">
        <div className="flex items-center space-x-3">
          <span className="text-sm font-medium text-gray-700">Cluster Status:</span>
          {telemetryAvailable ? (
            <div className="flex items-center space-x-2">
              <span className="relative flex h-2.5 w-2.5">
                <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
                <span className="relative inline-flex rounded-full h-2.5 w-2.5 bg-green-500"></span>
              </span>
              <Badge variant="success">{t('telemetry_live')}</Badge>
            </div>
          ) : (
            <div className="flex items-center space-x-2">
              <span className="relative inline-flex rounded-full h-2.5 w-2.5 bg-red-500"></span>
              <Badge variant="destructive">{t('telemetry_unavailable')}</Badge>
            </div>
          )}
          {overview?.checked_at && (
            <span className="text-xs text-gray-400">
              Checked: {new Date(overview.checked_at).toLocaleTimeString()}
            </span>
          )}
        </div>

        {onRefresh && (
          <button
            onClick={onRefresh}
            disabled={isLoading}
            className="flex items-center px-3 py-1.5 text-xs font-medium text-gray-700 bg-gray-50 hover:bg-gray-100 border rounded-md transition-colors disabled:opacity-50"
          >
            <RefreshCw className={`w-3.5 h-3.5 mr-1.5 ${isLoading ? 'animate-spin' : ''}`} />
            Refresh
          </button>
        )}
      </div>

      {/* Telemetry Warning Alert when Redis is offline */}
      {!telemetryAvailable && !isLoading && (
        <div className="p-4 bg-amber-50 border border-amber-200 rounded-lg flex items-start space-x-3 text-amber-800">
          <AlertCircle className="w-5 h-5 text-amber-600 shrink-0 mt-0.5" />
          <div className="text-sm">
            <p className="font-semibold">{t('telemetry_unavailable')}</p>
            <p className="mt-0.5">{t('telemetry_warning')}</p>
          </div>
        </div>
      )}

      {/* Overview Stat Cards Grid */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        {/* Outbound Attempts */}
        <Card>
          <CardContent className="p-5 flex items-center justify-between">
            <div>
              <p className="text-xs font-medium uppercase tracking-wider text-gray-500">
                {t('overview.outbound_attempts')}
              </p>
              <h3 className="text-2xl font-bold text-gray-900 mt-1">
                {telemetryAvailable 
                  ? (overview?.cluster_recipients_today?.toLocaleString() ?? 0) 
                  : 'N/A'}
              </h3>
              <p className="text-xs text-gray-400 mt-1">Accepted at submission</p>
            </div>
            <div className="p-3 bg-blue-50 text-blue-600 rounded-xl">
              <Send className="w-5 h-5" />
            </div>
          </CardContent>
        </Card>

        {/* Hard Bounces */}
        <Card>
          <CardContent className="p-5 flex items-center justify-between">
            <div>
              <p className="text-xs font-medium uppercase tracking-wider text-gray-500">
                {t('overview.hard_bounces')}
              </p>
              <h3 className="text-2xl font-bold text-red-600 mt-1">
                {telemetryAvailable 
                  ? (overview?.cluster_hard_bounces_today?.toLocaleString() ?? 0) 
                  : 'N/A'}
              </h3>
              <p className="text-xs text-gray-400 mt-1">5xx permanent failures</p>
            </div>
            <div className="p-3 bg-red-50 text-red-600 rounded-xl">
              <AlertTriangle className="w-5 h-5" />
            </div>
          </CardContent>
        </Card>

        {/* Soft Bounces */}
        <Card>
          <CardContent className="p-5 flex items-center justify-between">
            <div>
              <p className="text-xs font-medium uppercase tracking-wider text-gray-500">
                {t('overview.soft_bounces')}
              </p>
              <h3 className="text-2xl font-bold text-amber-600 mt-1">
                {telemetryAvailable 
                  ? (overview?.cluster_soft_bounces_today?.toLocaleString() ?? 0) 
                  : 'N/A'}
              </h3>
              <p className="text-xs text-gray-400 mt-1">4xx temporary deferrals</p>
            </div>
            <div className="p-3 bg-amber-50 text-amber-600 rounded-xl">
              <RotateCcw className="w-5 h-5" />
            </div>
          </CardContent>
        </Card>

        {/* Hard Bounce Rate */}
        <Card>
          <CardContent className="p-5 flex items-center justify-between">
            <div>
              <p className="text-xs font-medium uppercase tracking-wider text-gray-500">
                {t('overview.bounce_rate')}
              </p>
              <h3 className={`text-2xl font-bold mt-1 ${getBounceRateColor(overview?.cluster_bounce_rate)}`}>
                {telemetryAvailable && overview?.cluster_bounce_rate !== null && overview?.cluster_bounce_rate !== undefined
                  ? `${overview.cluster_bounce_rate}%`
                  : 'N/A'}
              </h3>
              <p className="text-xs text-gray-400 mt-1">Threshold limit: &lt; 10%</p>
            </div>
            <div className="p-3 bg-indigo-50 text-indigo-600 rounded-xl">
              <Percent className="w-5 h-5" />
            </div>
          </CardContent>
        </Card>

        {/* Mail Queue Size */}
        <Card>
          <CardContent className="p-5 flex items-center justify-between">
            <div>
              <p className="text-xs font-medium uppercase tracking-wider text-gray-500">
                {t('overview.queue_size')}
              </p>
              <h3 className="text-2xl font-bold text-gray-900 mt-1">
                {overview?.mail_queue_size !== null && overview?.mail_queue_size !== undefined 
                  ? overview.mail_queue_size.toLocaleString() 
                  : 'N/A'}
              </h3>
              <p className="text-xs text-gray-400 mt-1">Postfix active/deferred</p>
            </div>
            <div className="p-3 bg-purple-50 text-purple-600 rounded-xl">
              <Layers className="w-5 h-5" />
            </div>
          </CardContent>
        </Card>

        {/* Active Abuse Alerts */}
        <Card>
          <CardContent className="p-5 flex items-center justify-between">
            <div>
              <p className="text-xs font-medium uppercase tracking-wider text-gray-500">
                {t('overview.active_warnings')}
              </p>
              <h3 className="text-2xl font-bold mt-1">
                {telemetryAvailable 
                  ? (
                    overview?.active_abuse_warnings_count && overview.active_abuse_warnings_count > 0 ? (
                      <span className="text-red-600">{overview.active_abuse_warnings_count}</span>
                    ) : (
                      <span className="text-green-600">0</span>
                    )
                  ) 
                  : 'N/A'}
              </h3>
              <p className="text-xs text-gray-400 mt-1">Breaches flagged today</p>
            </div>
            <div className={`p-3 rounded-xl ${
              overview?.active_abuse_warnings_count && overview.active_abuse_warnings_count > 0
                ? 'bg-red-50 text-red-600'
                : 'bg-green-50 text-green-600'
            }`}>
              <ShieldAlert className="w-5 h-5" />
            </div>
          </CardContent>
        </Card>

        {/* Total Tenants */}
        <Card>
          <CardContent className="p-5 flex items-center justify-between">
            <div>
              <p className="text-xs font-medium uppercase tracking-wider text-gray-500">
                {t('overview.total_tenants')}
              </p>
              <h3 className="text-2xl font-bold text-gray-900 mt-1">
                {overview?.total_tenants?.toLocaleString() ?? 0}
              </h3>
              <p className="text-xs text-gray-400 mt-1">Provisioned accounts</p>
            </div>
            <div className="p-3 bg-gray-50 text-gray-600 rounded-xl">
              <Users className="w-5 h-5" />
            </div>
          </CardContent>
        </Card>

        {/* Total Mailboxes */}
        <Card>
          <CardContent className="p-5 flex items-center justify-between">
            <div>
              <p className="text-xs font-medium uppercase tracking-wider text-gray-500">
                {t('overview.total_mailboxes')}
              </p>
              <h3 className="text-2xl font-bold text-gray-900 mt-1">
                {overview?.total_mailboxes?.toLocaleString() ?? 0}
              </h3>
              <p className="text-xs text-gray-400 mt-1">Across all domains</p>
            </div>
            <div className="p-3 bg-gray-50 text-gray-600 rounded-xl">
              <Mail className="w-5 h-5" />
            </div>
          </CardContent>
        </Card>
      </div>

      {/* Denominator Callout Note */}
      <div className="p-4 bg-gray-50 border border-gray-200 rounded-lg flex items-start space-x-3 text-gray-600">
        <Info className="w-5 h-5 text-gray-400 shrink-0 mt-0.5" />
        <div className="text-xs leading-relaxed">
          <p className="font-semibold text-gray-700">Deliverability Metrics Note</p>
          <p className="mt-0.5">{t('overview.denominator_note')}</p>
        </div>
      </div>
    </div>
  );
}
