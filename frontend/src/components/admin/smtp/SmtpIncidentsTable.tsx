'use client';

import { useState } from 'react';
import { useTranslations } from 'next-intl';
import useSWR from 'swr';
import api from '@/lib/api';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Spinner } from '@/components/ui/spinner';
import { 
  PaginatedResponse, 
  AbuseIncident, 
  IncidentSeverity, 
  IncidentStatus 
} from '@/types';
import { 
  Search, 
  Eye, 
  CheckCircle, 
  XCircle, 
  X, 
  ChevronLeft, 
  ChevronRight, 
  ShieldAlert, 
  AlertTriangle, 
  Clock, 
  User, 
  Globe, 
  Mail, 
  RefreshCw, 
  Filter, 
  Copy, 
  Check, 
  AlertCircle,
  FileText
} from 'lucide-react';

interface SmtpIncidentsTableProps {
  onActionComplete?: () => void;
}

export default function SmtpIncidentsTable({ onActionComplete }: SmtpIncidentsTableProps) {
  const t = useTranslations('Admin.smtp.incidents');
  const tCommon = useTranslations('Admin.common');

  // Filter States
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [severityFilter, setSeverityFilter] = useState('');
  const [typeFilter, setTypeFilter] = useState('');
  const [sourceFilter, setSourceFilter] = useState('');
  const [dateFrom, setDateFrom] = useState('');
  const [dateTo, setDateTo] = useState('');

  // Modal States
  const [selectedIncident, setSelectedIncident] = useState<AbuseIncident | null>(null);
  const [actionIncident, setActionIncident] = useState<AbuseIncident | null>(null);
  const [actionStatus, setActionStatus] = useState<'resolved' | 'dismissed'>('resolved');
  const [actionNotes, setActionNotes] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);
  const [actionSuccess, setActionSuccess] = useState<string | null>(null);
  const [evidenceCopied, setEvidenceCopied] = useState(false);

  // Construct query string
  const queryParams = new URLSearchParams({
    page: page.toString(),
    per_page: '15',
  });
  if (search.trim()) queryParams.append('search', search.trim());
  if (statusFilter) queryParams.append('status', statusFilter);
  if (severityFilter) queryParams.append('severity', severityFilter);
  if (typeFilter) queryParams.append('incident_type', typeFilter);
  if (sourceFilter) queryParams.append('detection_source', sourceFilter);
  if (dateFrom) queryParams.append('date_from', dateFrom);
  if (dateTo) queryParams.append('date_to', dateTo);

  const fetcher = (url: string) => api.get(url).then(res => res.data);
  const { data, error, isLoading, mutate } = useSWR<PaginatedResponse<AbuseIncident>>(
    `/api/admin/smtp/incidents?${queryParams.toString()}`,
    fetcher
  );

  const incidents = data?.data || [];
  const meta = data?.meta;

  // Derive counts from currently loaded dataset
  const openCountInView = incidents.filter(i => i.status === 'open').length;
  const criticalCountInView = incidents.filter(i => i.severity === 'critical').length;
  const highCountInView = incidents.filter(i => i.severity === 'high').length;
  const resolvedCountInView = incidents.filter(i => i.status === 'resolved').length;

  const resetFilters = () => {
    setSearch('');
    setStatusFilter('');
    setSeverityFilter('');
    setTypeFilter('');
    setSourceFilter('');
    setDateFrom('');
    setDateTo('');
    setPage(1);
  };

  const formatUtcDate = (dateStr: string | null) => {
    if (!dateStr) return 'N/A';
    try {
      const d = new Date(dateStr);
      if (isNaN(d.getTime())) return dateStr;
      return d.toISOString().replace('T', ' ').slice(0, 19) + ' UTC';
    } catch {
      return dateStr;
    }
  };

  const getSeverityBadge = (severity: IncidentSeverity) => {
    switch (severity) {
      case 'critical':
        return (
          <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-red-100 text-red-800 border border-red-300">
            <AlertCircle className="w-3 h-3 mr-1 text-red-600" />
            {t('severity.critical')}
          </span>
        );
      case 'high':
        return (
          <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-100 text-amber-800 border border-amber-300">
            <AlertTriangle className="w-3 h-3 mr-1 text-amber-600" />
            {t('severity.high')}
          </span>
        );
      case 'medium':
        return (
          <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800 border border-yellow-300">
            {t('severity.medium')}
          </span>
        );
      case 'low':
      default:
        return (
          <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800 border border-blue-200">
            {t('severity.low')}
          </span>
        );
    }
  };

  const getStatusBadge = (status: IncidentStatus) => {
    switch (status) {
      case 'open':
        return (
          <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-indigo-100 text-indigo-800 border border-indigo-200">
            <span className="h-1.5 w-1.5 rounded-full bg-indigo-600 mr-1.5 animate-pulse" />
            {t('status.open')}
          </span>
        );
      case 'resolved':
        return (
          <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-green-100 text-green-800 border border-green-200">
            <Check className="w-3 h-3 mr-1 text-green-600" />
            {t('status.resolved')}
          </span>
        );
      case 'dismissed':
        return (
          <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-700 border border-gray-200">
            {t('status.dismissed')}
          </span>
        );
      default:
        return <Badge variant="outline">{status}</Badge>;
    }
  };

  const getHumanType = (type: string) => {
    switch (type) {
      case 'bounce_rate_spike':
        return t('types.bounce_rate_spike');
      case 'daily_hard_bounce_threshold':
        return t('types.daily_hard_bounce_threshold');
      case 'consecutive_hard_bounces':
        return t('types.consecutive_hard_bounces');
      case 'outbound_quota_breach':
        return t('types.outbound_quota_breach');
      case 'rate_limit_spike':
        return t('types.rate_limit_spike');
      case 'suspicious_volume_spike':
        return t('types.suspicious_volume_spike');
      default:
        return type.replace(/_/g, ' ');
    }
  };

  const getHumanSource = (source: string) => {
    switch (source) {
      case 'log_parser':
        return t('sources.log_parser');
      case 'policy_daemon':
        return t('sources.policy_daemon');
      case 'system':
        return t('sources.system');
      default:
        return source;
    }
  };

  // Submit Resolve / Dismiss Action
  const handleActionSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!actionIncident) return;

    setIsSubmitting(true);
    setActionError(null);
    setActionSuccess(null);

    try {
      const payload = {
        status: actionStatus,
        notes: actionNotes.trim() ? actionNotes.trim() : undefined,
      };

      await api.post(`/api/admin/smtp/incidents/${actionIncident.id}/resolve`, payload);

      setActionSuccess(actionStatus === 'dismissed' ? t('resolve_modal.dismiss_success') : t('resolve_modal.resolve_success'));
      mutate();
      if (onActionComplete) onActionComplete();

      setTimeout(() => {
        setActionIncident(null);
        setActionNotes('');
        setActionSuccess(null);
      }, 1000);
    } catch (err: any) {
      if (err.response?.status === 403) {
        setActionError(t('resolve_modal.permission_denied'));
      } else {
        setActionError(err.response?.data?.message || tCommon('error'));
      }
    } finally {
      setIsSubmitting(false);
    }
  };

  const copyEvidence = (evidenceObj: Record<string, any>) => {
    navigator.clipboard.writeText(JSON.stringify(evidenceObj, null, 2));
    setEvidenceCopied(true);
    setTimeout(() => setEvidenceCopied(false), 2000);
  };

  return (
    <div className="space-y-6">
      {/* Summary Cards */}
      <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4">
        <Card className="border-indigo-100 bg-indigo-50/30">
          <CardContent className="p-4">
            <p className="text-xs font-medium uppercase tracking-wider text-indigo-700">
              {t('cards.open_view')}
            </p>
            <h3 className="text-2xl font-bold text-indigo-900 mt-1">
              {isLoading ? '-' : openCountInView}
            </h3>
            <p className="text-xs text-gray-500 mt-1">Requiring action</p>
          </CardContent>
        </Card>

        <Card className="border-red-100 bg-red-50/30">
          <CardContent className="p-4">
            <p className="text-xs font-medium uppercase tracking-wider text-red-700">
              {t('cards.critical_view')}
            </p>
            <h3 className="text-2xl font-bold text-red-900 mt-1">
              {isLoading ? '-' : criticalCountInView}
            </h3>
            <p className="text-xs text-gray-500 mt-1">Critical severity</p>
          </CardContent>
        </Card>

        <Card className="border-amber-100 bg-amber-50/30">
          <CardContent className="p-4">
            <p className="text-xs font-medium uppercase tracking-wider text-amber-700">
              {t('cards.high_view')}
            </p>
            <h3 className="text-2xl font-bold text-amber-900 mt-1">
              {isLoading ? '-' : highCountInView}
            </h3>
            <p className="text-xs text-gray-500 mt-1">High threshold breach</p>
          </CardContent>
        </Card>

        <Card className="border-green-100 bg-green-50/30">
          <CardContent className="p-4">
            <p className="text-xs font-medium uppercase tracking-wider text-green-700">
              {t('cards.resolved_view')}
            </p>
            <h3 className="text-2xl font-bold text-green-900 mt-1">
              {isLoading ? '-' : resolvedCountInView}
            </h3>
            <p className="text-xs text-gray-500 mt-1">Resolved in view</p>
          </CardContent>
        </Card>

        <Card className="border-gray-200 bg-gray-50/40 col-span-2 sm:col-span-1">
          <CardContent className="p-4">
            <p className="text-xs font-medium uppercase tracking-wider text-gray-600">
              {t('cards.total_view')}
            </p>
            <h3 className="text-2xl font-bold text-gray-900 mt-1">
              {isLoading ? '-' : (meta?.total ?? incidents.length)}
            </h3>
            <p className="text-xs text-gray-500 mt-1">Total in ledger</p>
          </CardContent>
        </Card>
      </div>

      {/* Filter and Search Controls */}
      <div className="bg-white p-4 rounded-lg border shadow-sm space-y-4">
        <div className="flex flex-col lg:flex-row gap-3 items-stretch lg:items-center justify-between">
          {/* Search */}
          <div className="relative flex-1">
            <Search className="absolute left-3 top-2.5 h-4 w-4 text-gray-400" />
            <input
              type="text"
              placeholder={t('search_placeholder')}
              value={search}
              onChange={(e) => {
                setSearch(e.target.value);
                setPage(1);
              }}
              className="w-full pl-9 pr-4 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none bg-white"
            />
          </div>

          <div className="flex flex-wrap items-center gap-2">
            {/* Status Filter */}
            <select
              value={statusFilter}
              onChange={(e) => {
                setStatusFilter(e.target.value);
                setPage(1);
              }}
              aria-label={t('filter_status')}
              className="border border-gray-300 rounded-md px-3 py-2 text-sm bg-white focus:ring-2 focus:ring-indigo-500 focus:outline-none"
            >
              <option value="">{t('filter_status')}</option>
              <option value="open">{t('status.open')}</option>
              <option value="resolved">{t('status.resolved')}</option>
              <option value="dismissed">{t('status.dismissed')}</option>
            </select>

            {/* Severity Filter */}
            <select
              value={severityFilter}
              onChange={(e) => {
                setSeverityFilter(e.target.value);
                setPage(1);
              }}
              aria-label={t('filter_severity')}
              className="border border-gray-300 rounded-md px-3 py-2 text-sm bg-white focus:ring-2 focus:ring-indigo-500 focus:outline-none"
            >
              <option value="">{t('filter_severity')}</option>
              <option value="critical">{t('severity.critical')}</option>
              <option value="high">{t('severity.high')}</option>
              <option value="medium">{t('severity.medium')}</option>
              <option value="low">{t('severity.low')}</option>
            </select>

            {/* Type Filter */}
            <select
              value={typeFilter}
              onChange={(e) => {
                setTypeFilter(e.target.value);
                setPage(1);
              }}
              aria-label={t('filter_type')}
              className="border border-gray-300 rounded-md px-3 py-2 text-sm bg-white focus:ring-2 focus:ring-indigo-500 focus:outline-none"
            >
              <option value="">{t('filter_type')}</option>
              <option value="bounce_rate_spike">{t('types.bounce_rate_spike')}</option>
              <option value="daily_hard_bounce_threshold">{t('types.daily_hard_bounce_threshold')}</option>
              <option value="consecutive_hard_bounces">{t('types.consecutive_hard_bounces')}</option>
              <option value="outbound_quota_breach">{t('types.outbound_quota_breach')}</option>
              <option value="rate_limit_spike">{t('types.rate_limit_spike')}</option>
              <option value="suspicious_volume_spike">{t('types.suspicious_volume_spike')}</option>
            </select>

            {/* Source Filter */}
            <select
              value={sourceFilter}
              onChange={(e) => {
                setSourceFilter(e.target.value);
                setPage(1);
              }}
              aria-label={t('filter_source')}
              className="border border-gray-300 rounded-md px-3 py-2 text-sm bg-white focus:ring-2 focus:ring-indigo-500 focus:outline-none"
            >
              <option value="">{t('filter_source')}</option>
              <option value="log_parser">{t('sources.log_parser')}</option>
              <option value="policy_daemon">{t('sources.policy_daemon')}</option>
              <option value="system">{t('sources.system')}</option>
            </select>
          </div>
        </div>

        {/* Date Filters & Reset Button */}
        <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 pt-2 border-t text-sm">
          <div className="flex flex-wrap items-center gap-2">
            <span className="text-xs text-gray-500 flex items-center">
              <Clock className="w-3.5 h-3.5 mr-1" /> Date range:
            </span>
            <input
              type="date"
              value={dateFrom}
              onChange={(e) => {
                setDateFrom(e.target.value);
                setPage(1);
              }}
              aria-label={t('date_from')}
              className="border border-gray-300 rounded-md px-2.5 py-1 text-xs bg-white focus:ring-2 focus:ring-indigo-500 focus:outline-none"
            />
            <span className="text-gray-400 text-xs">to</span>
            <input
              type="date"
              value={dateTo}
              onChange={(e) => {
                setDateTo(e.target.value);
                setPage(1);
              }}
              aria-label={t('date_to')}
              className="border border-gray-300 rounded-md px-2.5 py-1 text-xs bg-white focus:ring-2 focus:ring-indigo-500 focus:outline-none"
            />
          </div>

          <div className="flex items-center gap-2">
            <button
              onClick={resetFilters}
              className="px-3 py-1 text-xs text-gray-600 hover:text-gray-900 border border-gray-300 rounded-md hover:bg-gray-50 transition-colors"
            >
              {t('reset_filters')}
            </button>
            <button
              onClick={() => mutate()}
              disabled={isLoading}
              className="flex items-center px-3 py-1 text-xs font-medium text-gray-700 bg-gray-50 hover:bg-gray-100 border rounded-md transition-colors disabled:opacity-50"
            >
              <RefreshCw className={`w-3.5 h-3.5 mr-1.5 ${isLoading ? 'animate-spin' : ''}`} />
              {t('refresh')}
            </button>
          </div>
        </div>
      </div>

      {/* Main Incident Table */}
      <Card>
        <CardContent className="p-0">
          <div className="overflow-x-auto">
            <table className="w-full text-sm text-left text-gray-500">
              <thead className="text-xs text-gray-700 uppercase bg-gray-50 border-b">
                <tr>
                  <th className="px-5 py-3.5 whitespace-nowrap">{t('table.severity')}</th>
                  <th className="px-5 py-3.5 whitespace-nowrap">{t('table.type')}</th>
                  <th className="px-5 py-3.5 whitespace-nowrap">{t('table.source')}</th>
                  <th className="px-5 py-3.5 whitespace-nowrap">{t('table.tenant')}</th>
                  <th className="px-5 py-3.5 whitespace-nowrap">{t('table.domain')}</th>
                  <th className="px-5 py-3.5 whitespace-nowrap">{t('table.mailbox')}</th>
                  <th className="px-5 py-3.5 whitespace-nowrap">{t('table.occurred')}</th>
                  <th className="px-5 py-3.5 whitespace-nowrap">{t('table.status')}</th>
                  <th className="px-5 py-3.5 text-right whitespace-nowrap">{t('table.actions')}</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200">
                {isLoading ? (
                  <tr>
                    <td colSpan={9} className="px-6 py-12 text-center">
                      <div className="flex justify-center items-center space-x-2 text-gray-500">
                        <Spinner />
                        <span>{tCommon('loading')}</span>
                      </div>
                    </td>
                  </tr>
                ) : error ? (
                  <tr>
                    <td colSpan={9} className="px-6 py-12 text-center text-red-500">
                      <div className="flex flex-col items-center justify-center space-y-2">
                        <AlertCircle className="w-6 h-6 text-red-500" />
                        <span>{tCommon('error')}</span>
                        <button
                          onClick={() => mutate()}
                          className="text-xs text-indigo-600 hover:underline font-medium"
                        >
                          Retry
                        </button>
                      </div>
                    </td>
                  </tr>
                ) : incidents.length === 0 ? (
                  <tr>
                    <td colSpan={9} className="px-6 py-12 text-center text-gray-500">
                      <div className="flex flex-col items-center justify-center space-y-2">
                        <CheckCircle className="w-8 h-8 text-green-500" />
                        <p className="font-medium text-gray-700">{t('no_incidents')}</p>
                      </div>
                    </td>
                  </tr>
                ) : (
                  incidents.map((incident) => (
                    <tr 
                      key={incident.id} 
                      className="hover:bg-gray-50 transition-colors"
                    >
                      <td className="px-5 py-3.5 whitespace-nowrap">
                        {getSeverityBadge(incident.severity)}
                      </td>
                      <td className="px-5 py-3.5 whitespace-nowrap font-medium text-gray-900">
                        {getHumanType(incident.incident_type)}
                      </td>
                      <td className="px-5 py-3.5 whitespace-nowrap text-xs text-gray-600">
                        <span className="font-mono bg-gray-100 px-2 py-0.5 rounded text-gray-700">
                          {getHumanSource(incident.detection_source)}
                        </span>
                      </td>
                      <td className="px-5 py-3.5 whitespace-nowrap text-sm text-gray-700">
                        {incident.tenant_email || (incident.tenant_id ? `Tenant #${incident.tenant_id}` : '-')}
                      </td>
                      <td className="px-5 py-3.5 whitespace-nowrap text-sm text-gray-700">
                        {incident.domain_name || '-'}
                      </td>
                      <td className="px-5 py-3.5 whitespace-nowrap text-sm text-gray-700 font-mono text-xs">
                        {incident.mailbox_email || '-'}
                      </td>
                      <td className="px-5 py-3.5 whitespace-nowrap text-xs text-gray-500">
                        {formatUtcDate(incident.occurred_at)}
                      </td>
                      <td className="px-5 py-3.5 whitespace-nowrap">
                        {getStatusBadge(incident.status)}
                      </td>
                      <td className="px-5 py-3.5 whitespace-nowrap text-right space-x-2">
                        <button
                          onClick={() => setSelectedIncident(incident)}
                          title={t('actions.view')}
                          className="inline-flex items-center p-1.5 text-indigo-600 hover:text-indigo-900 hover:bg-indigo-50 rounded transition-colors"
                        >
                          <Eye className="w-4 h-4 mr-1" />
                          <span className="text-xs font-medium">{t('actions.view')}</span>
                        </button>

                        {incident.status === 'open' && (
                          <button
                            onClick={() => {
                              setActionIncident(incident);
                              setActionStatus('resolved');
                              setActionNotes('');
                              setActionError(null);
                            }}
                            title={t('actions.resolve')}
                            className="inline-flex items-center p-1.5 text-green-600 hover:text-green-900 hover:bg-green-50 rounded transition-colors"
                          >
                            <Check className="w-4 h-4 mr-1" />
                            <span className="text-xs font-medium">{t('actions.resolve')}</span>
                          </button>
                        )}
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>

          {/* Pagination Controls */}
          {meta && meta.last_page > 1 && (
            <div className="flex items-center justify-between px-6 py-3 border-t bg-gray-50/50">
              <div className="text-xs text-gray-500">
                Showing page <span className="font-semibold text-gray-700">{meta.current_page}</span> of{' '}
                <span className="font-semibold text-gray-700">{meta.last_page}</span> (
                <span className="font-semibold text-gray-700">{meta.total}</span> incidents)
              </div>
              <div className="flex items-center space-x-2">
                <button
                  onClick={() => setPage((p) => Math.max(1, p - 1))}
                  disabled={page <= 1 || isLoading}
                  className="px-2.5 py-1 text-xs font-medium text-gray-700 bg-white border border-gray-300 rounded hover:bg-gray-50 disabled:opacity-40 transition-colors flex items-center"
                >
                  <ChevronLeft className="w-3.5 h-3.5 mr-1" /> Previous
                </button>
                <button
                  onClick={() => setPage((p) => Math.min(meta.last_page, p + 1))}
                  disabled={page >= meta.last_page || isLoading}
                  className="px-2.5 py-1 text-xs font-medium text-gray-700 bg-white border border-gray-300 rounded hover:bg-gray-50 disabled:opacity-40 transition-colors flex items-center"
                >
                  Next <ChevronRight className="w-3.5 h-3.5 ml-1" />
                </button>
              </div>
            </div>
          )}
        </CardContent>
      </Card>

      {/* Incident Detail Modal */}
      {selectedIncident && (
        <div 
          className="fixed inset-0 z-50 overflow-y-auto bg-black/60 flex items-center justify-center p-4 backdrop-blur-sm"
          role="dialog"
          aria-modal="true"
          aria-labelledby="incident-detail-title"
        >
          <div className="bg-white rounded-xl shadow-2xl max-w-2xl w-full overflow-hidden border">
            {/* Modal Header */}
            <div className="px-6 py-4 bg-gray-50 border-b flex justify-between items-center">
              <div className="flex items-center space-x-3">
                <div className="p-2 bg-indigo-50 text-indigo-700 rounded-lg">
                  <ShieldAlert className="w-5 h-5" />
                </div>
                <div>
                  <h3 id="incident-detail-title" className="text-lg font-bold text-gray-900">
                    {t('detail_modal.title')}
                  </h3>
                  <p className="text-xs font-mono text-gray-500">
                    UUID: {selectedIncident.uuid}
                  </p>
                </div>
              </div>
              <button
                onClick={() => setSelectedIncident(null)}
                className="text-gray-400 hover:text-gray-600 p-1.5 rounded-lg hover:bg-gray-100 transition-colors"
                aria-label="Close dialog"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            {/* Modal Body */}
            <div className="p-6 space-y-6 max-h-[75vh] overflow-y-auto text-sm">
              {/* Status and Severity Badges */}
              <div className="flex flex-wrap items-center gap-3 pb-4 border-b">
                <div className="flex items-center gap-2">
                  <span className="text-xs text-gray-500 font-medium">Status:</span>
                  {getStatusBadge(selectedIncident.status)}
                </div>
                <div className="flex items-center gap-2">
                  <span className="text-xs text-gray-500 font-medium">Severity:</span>
                  {getSeverityBadge(selectedIncident.severity)}
                </div>
                <div className="flex items-center gap-2">
                  <span className="text-xs text-gray-500 font-medium">Source:</span>
                  <span className="font-mono text-xs bg-gray-100 px-2 py-0.5 rounded text-gray-700">
                    {getHumanSource(selectedIncident.detection_source)}
                  </span>
                </div>
              </div>

              {/* Summary and Metric Breach */}
              <div className="bg-amber-50/50 border border-amber-200/70 p-4 rounded-lg space-y-2">
                <h4 className="text-xs font-bold uppercase tracking-wider text-amber-900">
                  {t('detail_modal.summary')}
                </h4>
                <p className="text-sm text-gray-800 font-medium">{selectedIncident.summary}</p>

                {(selectedIncident.threshold_value !== null || selectedIncident.observed_value !== null) && (
                  <div className="grid grid-cols-2 gap-4 pt-2 mt-2 border-t border-amber-200/50 text-xs">
                    <div>
                      <span className="text-gray-500 font-medium">{t('detail_modal.threshold')}:</span>
                      <p className="font-mono text-gray-800 font-bold mt-0.5">
                        {selectedIncident.threshold_value ?? 'N/A'}
                      </p>
                    </div>
                    <div>
                      <span className="text-gray-500 font-medium">{t('detail_modal.observed')}:</span>
                      <p className="font-mono text-red-600 font-bold mt-0.5">
                        {selectedIncident.observed_value ?? 'N/A'}
                      </p>
                    </div>
                  </div>
                )}
              </div>

              {/* Affected Entities (Immutable Snapshot) */}
              <div className="space-y-3">
                <h4 className="text-xs font-bold uppercase tracking-wider text-gray-500">
                  {t('detail_modal.entities')}
                </h4>
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 bg-gray-50 p-3.5 rounded-lg border">
                  <div>
                    <span className="text-xs text-gray-500 flex items-center">
                      <User className="w-3.5 h-3.5 mr-1" /> {t('detail_modal.tenant')}
                    </span>
                    <p className="font-semibold text-gray-900 text-xs mt-1 truncate">
                      {selectedIncident.tenant_email || (selectedIncident.tenant_id ? `#${selectedIncident.tenant_id}` : 'None')}
                    </p>
                  </div>
                  <div>
                    <span className="text-xs text-gray-500 flex items-center">
                      <Globe className="w-3.5 h-3.5 mr-1" /> {t('detail_modal.domain')}
                    </span>
                    <p className="font-semibold text-gray-900 text-xs mt-1 truncate">
                      {selectedIncident.domain_name || 'None'}
                    </p>
                  </div>
                  <div>
                    <span className="text-xs text-gray-500 flex items-center">
                      <Mail className="w-3.5 h-3.5 mr-1" /> {t('detail_modal.mailbox')}
                    </span>
                    <p className="font-semibold text-gray-900 text-xs mt-1 truncate font-mono">
                      {selectedIncident.mailbox_email || 'None'}
                    </p>
                  </div>
                </div>
              </div>

              {/* Timestamps & Idempotency Key */}
              <div className="grid grid-cols-2 gap-4 text-xs bg-gray-50 p-3.5 rounded-lg border">
                <div>
                  <span className="text-gray-500 font-medium">{t('detail_modal.occurred_at')}:</span>
                  <p className="text-gray-900 font-mono mt-0.5">
                    {formatUtcDate(selectedIncident.occurred_at)}
                  </p>
                </div>
                <div>
                  <span className="text-gray-500 font-medium">{t('detail_modal.detected_at')}:</span>
                  <p className="text-gray-900 font-mono mt-0.5">
                    {formatUtcDate(selectedIncident.created_at)}
                  </p>
                </div>
                <div className="col-span-2 pt-2 border-t">
                  <span className="text-gray-500 font-medium">{t('detail_modal.idempotency')}:</span>
                  <p className="font-mono text-gray-700 text-xs break-all mt-0.5">
                    {selectedIncident.idempotency_key}
                  </p>
                </div>
              </div>

              {/* Resolution Information if Resolved/Dismissed */}
              {selectedIncident.status !== 'open' && (
                <div className="bg-green-50/50 border border-green-200/70 p-4 rounded-lg space-y-2">
                  <h4 className="text-xs font-bold uppercase tracking-wider text-green-900">
                    {t('detail_modal.resolution')}
                  </h4>
                  <div className="grid grid-cols-2 gap-3 text-xs">
                    <div>
                      <span className="text-gray-500 font-medium">{t('detail_modal.resolved_at')}:</span>
                      <p className="text-gray-900 font-mono mt-0.5">
                        {formatUtcDate(selectedIncident.resolved_at)}
                      </p>
                    </div>
                    <div>
                      <span className="text-gray-500 font-medium">{t('detail_modal.resolved_by')}:</span>
                      <p className="text-gray-900 font-semibold mt-0.5">
                        {selectedIncident.resolver_name || (selectedIncident.resolved_by ? `Admin #${selectedIncident.resolved_by}` : 'System')}
                      </p>
                    </div>
                    {selectedIncident.resolution_notes && (
                      <div className="col-span-2 pt-2 border-t border-green-200/50">
                        <span className="text-gray-500 font-medium">{t('detail_modal.notes')}:</span>
                        <p className="text-gray-800 mt-1 whitespace-pre-wrap">
                          {selectedIncident.resolution_notes}
                        </p>
                      </div>
                    )}
                  </div>
                </div>
              )}

              {/* Sanitized Evidence Inspector */}
              <div className="space-y-2">
                <div className="flex justify-between items-center">
                  <h4 className="text-xs font-bold uppercase tracking-wider text-gray-500 flex items-center">
                    <FileText className="w-3.5 h-3.5 mr-1" />
                    {t('detail_modal.evidence')}
                  </h4>
                  {selectedIncident.evidence && Object.keys(selectedIncident.evidence).length > 0 && (
                    <button
                      onClick={() => copyEvidence(selectedIncident.evidence)}
                      className="text-xs text-gray-500 hover:text-gray-800 flex items-center p-1 rounded hover:bg-gray-100 transition-colors"
                    >
                      {evidenceCopied ? (
                        <>
                          <Check className="w-3.5 h-3.5 mr-1 text-green-600" />
                          <span className="text-green-600 font-medium">Copied</span>
                        </>
                      ) : (
                        <>
                          <Copy className="w-3.5 h-3.5 mr-1" />
                          <span>Copy JSON</span>
                        </>
                      )}
                    </button>
                  )}
                </div>

                {selectedIncident.evidence && Object.keys(selectedIncident.evidence).length > 0 ? (
                  <pre className="p-3 bg-gray-900 text-gray-100 rounded-lg text-xs font-mono overflow-x-auto max-h-48 whitespace-pre-wrap">
                    {JSON.stringify(selectedIncident.evidence, null, 2)}
                  </pre>
                ) : (
                  <p className="text-xs text-gray-400 italic bg-gray-50 p-3 rounded border">
                    {t('detail_modal.no_evidence')}
                  </p>
                )}
              </div>
            </div>

            {/* Modal Footer */}
            <div className="px-6 py-3.5 bg-gray-50 border-t flex justify-between items-center">
              <div>
                {selectedIncident.status === 'open' && (
                  <button
                    onClick={() => {
                      setActionIncident(selectedIncident);
                      setSelectedIncident(null);
                      setActionStatus('resolved');
                      setActionNotes('');
                      setActionError(null);
                    }}
                    className="px-3.5 py-1.5 bg-green-600 hover:bg-green-700 text-white rounded-md text-xs font-medium transition-colors flex items-center"
                  >
                    <Check className="w-3.5 h-3.5 mr-1.5" />
                    {t('actions.resolve')} / {t('actions.dismiss')}
                  </button>
                )}
              </div>
              <button
                onClick={() => setSelectedIncident(null)}
                className="px-4 py-2 bg-gray-200 hover:bg-gray-300 text-gray-800 rounded-md text-xs font-medium transition-colors"
              >
                {t('detail_modal.close')}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Resolve / Dismiss Action Modal */}
      {actionIncident && (
        <div 
          className="fixed inset-0 z-50 overflow-y-auto bg-black/60 flex items-center justify-center p-4 backdrop-blur-sm"
          role="dialog"
          aria-modal="true"
          aria-labelledby="incident-action-title"
        >
          <div className="bg-white rounded-xl shadow-2xl max-w-lg w-full overflow-hidden border">
            {/* Modal Header */}
            <div className="px-6 py-4 bg-gray-50 border-b flex justify-between items-center">
              <h3 id="incident-action-title" className="text-base font-bold text-gray-900">
                {actionStatus === 'dismissed' 
                  ? t('resolve_modal.dismiss_title') 
                  : t('resolve_modal.resolve_title')}
              </h3>
              <button
                onClick={() => setActionIncident(null)}
                disabled={isSubmitting}
                className="text-gray-400 hover:text-gray-600 p-1.5 rounded-lg hover:bg-gray-100 transition-colors disabled:opacity-40"
                aria-label="Close dialog"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            {/* Modal Body */}
            <form onSubmit={handleActionSubmit} className="p-6 space-y-4 text-sm">
              {/* Target Incident summary info */}
              <div className="p-3 bg-gray-50 rounded-lg border text-xs space-y-1">
                <p className="font-semibold text-gray-900">{getHumanType(actionIncident.incident_type)}</p>
                <p className="text-gray-600 truncate">{actionIncident.summary}</p>
                <p className="font-mono text-gray-500 text-[11px] pt-1 border-t">
                  {actionIncident.tenant_email || actionIncident.mailbox_email || `ID: #${actionIncident.id}`}
                </p>
              </div>

              {/* Action Selector Tabs */}
              <div className="grid grid-cols-2 gap-2 p-1 bg-gray-100 rounded-lg">
                <button
                  type="button"
                  onClick={() => setActionStatus('resolved')}
                  className={`py-2 text-xs font-semibold rounded-md transition-colors ${
                    actionStatus === 'resolved'
                      ? 'bg-white text-green-700 shadow-sm'
                      : 'text-gray-600 hover:text-gray-900'
                  }`}
                >
                  {t('resolve_modal.confirm_resolve')}
                </button>
                <button
                  type="button"
                  onClick={() => setActionStatus('dismissed')}
                  className={`py-2 text-xs font-semibold rounded-md transition-colors ${
                    actionStatus === 'dismissed'
                      ? 'bg-white text-gray-800 shadow-sm'
                      : 'text-gray-600 hover:text-gray-900'
                  }`}
                >
                  {t('resolve_modal.confirm_dismiss')}
                </button>
              </div>

              <p className="text-xs text-gray-500">
                {actionStatus === 'dismissed'
                  ? t('resolve_modal.dismiss_desc')
                  : t('resolve_modal.resolve_desc')}
              </p>

              {/* Notes Input */}
              <div className="space-y-1.5">
                <label 
                  htmlFor="resolution-notes" 
                  className="block text-xs font-medium text-gray-700"
                >
                  {t('resolve_modal.notes_label')}
                </label>
                <textarea
                  id="resolution-notes"
                  rows={3}
                  maxLength={1000}
                  value={actionNotes}
                  onChange={(e) => setActionNotes(e.target.value)}
                  placeholder={t('resolve_modal.notes_placeholder')}
                  className="w-full px-3 py-2 border border-gray-300 rounded-md text-xs focus:ring-2 focus:ring-indigo-500 focus:outline-none"
                />
                <span className="text-[11px] text-gray-400 block text-right">
                  {actionNotes.length} / 1000
                </span>
              </div>

              {/* Error Alert */}
              {actionError && (
                <div className="p-3 bg-red-50 border border-red-200 rounded-md text-xs text-red-700 flex items-start space-x-2">
                  <AlertCircle className="w-4 h-4 text-red-500 shrink-0 mt-0.5" />
                  <span>{actionError}</span>
                </div>
              )}

              {/* Success Alert */}
              {actionSuccess && (
                <div className="p-3 bg-green-50 border border-green-200 rounded-md text-xs text-green-700 flex items-center space-x-2">
                  <Check className="w-4 h-4 text-green-500 shrink-0" />
                  <span>{actionSuccess}</span>
                </div>
              )}

              {/* Modal Footer */}
              <div className="pt-3 border-t flex justify-end space-x-2">
                <button
                  type="button"
                  onClick={() => setActionIncident(null)}
                  disabled={isSubmitting}
                  className="px-4 py-2 border border-gray-300 rounded-md text-xs font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50 transition-colors"
                >
                  {t('resolve_modal.cancel')}
                </button>
                <button
                  type="submit"
                  disabled={isSubmitting}
                  className={`px-4 py-2 rounded-md text-xs font-bold text-white transition-colors flex items-center disabled:opacity-50 ${
                    actionStatus === 'dismissed'
                      ? 'bg-gray-800 hover:bg-gray-900'
                      : 'bg-green-600 hover:bg-green-700'
                  }`}
                >
                  {isSubmitting && <Spinner className="w-3.5 h-3.5 mr-1.5" />}
                  {isSubmitting 
                    ? t('resolve_modal.submitting')
                    : actionStatus === 'dismissed'
                      ? t('resolve_modal.confirm_dismiss')
                      : t('resolve_modal.confirm_resolve')}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
