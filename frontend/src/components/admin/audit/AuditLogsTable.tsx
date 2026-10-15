'use client';

import { useState } from 'react';
import { useTranslations } from 'next-intl';
import useSWR from 'swr';
import api from '@/lib/api';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Spinner } from '@/components/ui/spinner';
import { PaginatedResponse, AuditLog } from '@/types';
import { 
  Search, 
  Eye, 
  X, 
  ChevronLeft, 
  ChevronRight, 
  FileText,
  Shield,
  ShieldAlert,
  CheckCircle2,
  XCircle,
  Clock,
  Lock
} from 'lucide-react';

export default function AuditLogsTable() {
  const t = useTranslations('Admin.audit_logs');
  const tCommon = useTranslations('Admin.common');

  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [actionFilter, setActionFilter] = useState('');
  const [entityFilter, setEntityFilter] = useState('');
  const [outcomeFilter, setOutcomeFilter] = useState('');
  const [selectedLog, setSelectedLog] = useState<AuditLog | null>(null);

  // Build query string
  const queryParams = new URLSearchParams({
    page: page.toString(),
  });
  if (search.trim()) queryParams.append('search', search.trim());
  if (actionFilter) queryParams.append('action', actionFilter);
  if (entityFilter) queryParams.append('entity_type', entityFilter);
  if (outcomeFilter) queryParams.append('outcome', outcomeFilter);

  const fetcher = (url: string) => api.get(url).then(res => res.data);
  const { data, error, isLoading } = useSWR<PaginatedResponse<AuditLog>>(
    `/api/admin/audit-logs?${queryParams.toString()}`,
    fetcher
  );

  const isForbidden = error?.response?.status === 403 || error?.status === 403;

  const formatUtcDate = (dateStr: string) => {
    try {
      const d = new Date(dateStr);
      if (isNaN(d.getTime())) return dateStr;
      return d.toISOString().replace('T', ' ').slice(0, 19) + ' UTC';
    } catch {
      return dateStr;
    }
  };

  const getOutcomeBadge = (outcome?: string, action?: string) => {
    const resolved = outcome || (action?.includes('denied') ? 'denial' : action?.includes('failed') ? 'failure' : 'success');
    if (resolved === 'denial') {
      return (
        <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-800 border border-amber-300">
          <ShieldAlert className="w-3 h-3 mr-1 text-amber-600" />
          {t('outcome_denial')}
        </span>
      );
    }
    if (resolved === 'failure') {
      return (
        <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800 border border-red-300">
          <XCircle className="w-3 h-3 mr-1 text-red-600" />
          {t('outcome_failure')}
        </span>
      );
    }
    return (
      <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-emerald-100 text-emerald-800 border border-emerald-300">
        <CheckCircle2 className="w-3 h-3 mr-1 text-emerald-600" />
        {t('outcome_success')}
      </span>
    );
  };

  const getActionBadge = (action: string) => {
    if (action.includes('denied') || action.includes('denial')) {
      return <Badge variant="destructive" className="bg-red-600 text-white">{action}</Badge>;
    }
    if (action.includes('failed') || action.includes('delete') || action.includes('demote')) {
      return <Badge variant="destructive">{action}</Badge>;
    }
    if (action.includes('toggle') || action.includes('suspend') || action.includes('password')) {
      return <Badge variant="warning">{action}</Badge>;
    }
    if (action.includes('login') || action.includes('logout')) {
      return <Badge variant="secondary" className="bg-blue-100 text-blue-800 hover:bg-blue-200 border-blue-200">{action}</Badge>;
    }
    if (action.includes('backup')) {
      return <Badge variant="outline" className="bg-purple-50 text-purple-700 border-purple-300">{action}</Badge>;
    }
    if (action.includes('create') || action.includes('register') || action.includes('activate') || action.includes('assign')) {
      return <Badge variant="outline" className="bg-emerald-50 text-emerald-700 border-emerald-300">{action}</Badge>;
    }
    return <Badge variant="outline">{action}</Badge>;
  };

  if (isForbidden) {
    return (
      <div className="rounded-lg border border-red-200 bg-red-50 p-6 text-center space-y-3">
        <div className="flex justify-center">
          <Lock className="h-10 w-10 text-red-600" />
        </div>
        <h3 className="text-base font-semibold text-red-900">{t('access_denied_title')}</h3>
        <p className="text-sm text-red-700 max-w-lg mx-auto">
          {t('access_denied_desc')}
        </p>
      </div>
    );
  }

  return (
    <div className="space-y-4">
      {/* Controls: Search and Filters */}
      <div className="flex flex-col lg:flex-row gap-3 items-stretch lg:items-center justify-between">
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

        <div className="flex flex-wrap sm:flex-nowrap gap-2">
          {/* Action Filter */}
          <select
            value={actionFilter}
            onChange={(e) => {
              setActionFilter(e.target.value);
              setPage(1);
            }}
            aria-label={t('filter_action')}
            className="border border-gray-300 rounded-md px-3 py-2 text-sm bg-white focus:ring-2 focus:ring-indigo-500 focus:outline-none"
          >
            <option value="">{t('filter_action')}</option>
            <optgroup label="Authentication & Security">
              <option value="auth.login">auth.login</option>
              <option value="auth.logout">auth.logout</option>
              <option value="auth.failed">auth.failed</option>
              <option value="security.authorization_denied">security.authorization_denied</option>
            </optgroup>
            <optgroup label="Mailboxes">
              <option value="mailbox.create">mailbox.create</option>
              <option value="mailbox.change_password">mailbox.change_password</option>
              <option value="mailbox.toggle">mailbox.toggle</option>
              <option value="mailbox.delete">mailbox.delete</option>
              <option value="admin.smtp.mailbox.toggle">admin.smtp.mailbox.toggle</option>
              <option value="admin.smtp.mailbox.reset_password">admin.smtp.mailbox.reset_password</option>
              <option value="admin.smtp.mailbox.reset_consecutive_bounces">admin.smtp.mailbox.reset_consecutive_bounces</option>
            </optgroup>
            <optgroup label="Domains & Tenants">
              <option value="domain.create">domain.create</option>
              <option value="domain.delete">domain.delete</option>
              <option value="tenant.register">tenant.register</option>
              <option value="admin.users.suspend">admin.users.suspend</option>
              <option value="admin.users.activate">admin.users.activate</option>
            </optgroup>
            <optgroup label="Plans">
              <option value="plan.create">plan.create</option>
              <option value="plan.update">plan.update</option>
              <option value="plan.delete">plan.delete</option>
            </optgroup>
            <optgroup label="Admin Governance">
              <option value="admin.roles.assign">admin.roles.assign</option>
              <option value="admin.roles.demote">admin.roles.demote</option>
              <option value="admin.emergency_recovery">admin.emergency_recovery</option>
            </optgroup>
            <optgroup label="Backup & DR">
              <option value="backup.database.create">backup.database.create</option>
              <option value="backup.vmail.create">backup.vmail.create</option>
              <option value="backup.verify">backup.verify</option>
              <option value="backup.prune">backup.prune</option>
            </optgroup>
            <optgroup label="Abuse Incidents">
              <option value="admin.abuse_incident.resolve">admin.abuse_incident.resolve</option>
              <option value="admin.abuse_incident.dismiss">admin.abuse_incident.dismiss</option>
            </optgroup>
          </select>

          {/* Entity Type Filter */}
          <select
            value={entityFilter}
            onChange={(e) => {
              setEntityFilter(e.target.value);
              setPage(1);
            }}
            aria-label={t('filter_entity')}
            className="border border-gray-300 rounded-md px-3 py-2 text-sm bg-white focus:ring-2 focus:ring-indigo-500 focus:outline-none"
          >
            <option value="">{t('filter_entity')}</option>
            <option value="mailbox">Mailbox</option>
            <option value="domain">Domain</option>
            <option value="user">User / Tenant</option>
            <option value="plan">Plan</option>
            <option value="backup">Backup</option>
            <option value="AbuseIncident">Abuse Incident</option>
          </select>

          {/* Outcome Filter */}
          <select
            value={outcomeFilter}
            onChange={(e) => {
              setOutcomeFilter(e.target.value);
              setPage(1);
            }}
            aria-label={t('filter_outcome')}
            className="border border-gray-300 rounded-md px-3 py-2 text-sm bg-white focus:ring-2 focus:ring-indigo-500 focus:outline-none"
          >
            <option value="">{t('filter_outcome')}</option>
            <option value="success">{t('outcome_success')}</option>
            <option value="failure">{t('outcome_failure')}</option>
            <option value="denial">{t('outcome_denial')}</option>
          </select>
        </div>
      </div>

      {/* Main Table */}
      <Card>
        <CardContent className="p-0">
          <div className="overflow-x-auto">
            <table className="w-full text-sm text-left text-gray-500">
              <thead className="text-xs text-gray-700 uppercase bg-gray-50 border-b">
                <tr>
                  <th className="px-5 py-3.5 whitespace-nowrap">{t('table.date')}</th>
                  <th className="px-5 py-3.5 whitespace-nowrap">{t('table.actor')}</th>
                  <th className="px-5 py-3.5 whitespace-nowrap">{t('table.action')}</th>
                  <th className="px-5 py-3.5 whitespace-nowrap">{t('table.outcome')}</th>
                  <th className="px-5 py-3.5 whitespace-nowrap">{t('table.entity')}</th>
                  <th className="px-5 py-3.5 whitespace-nowrap">{t('table.reason')}</th>
                  <th className="px-5 py-3.5 whitespace-nowrap">{t('table.ip')}</th>
                  <th className="px-5 py-3.5 text-right whitespace-nowrap">{t('table.actions')}</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200">
                {isLoading ? (
                  <tr>
                    <td colSpan={8} className="px-6 py-12 text-center">
                      <div className="flex justify-center items-center space-x-2 text-gray-500">
                        <Spinner />
                        <span>{tCommon('loading')}</span>
                      </div>
                    </td>
                  </tr>
                ) : error ? (
                  <tr>
                    <td colSpan={8} className="px-6 py-12 text-center text-red-500">
                      {tCommon('error')}
                    </td>
                  </tr>
                ) : data?.data && data.data.length > 0 ? (
                  data.data.map((log) => (
                    <tr key={log.id} className="hover:bg-gray-50/80 transition-colors">
                      <td className="px-5 py-3.5 whitespace-nowrap font-mono text-xs text-gray-700">
                        {formatUtcDate(log.created_at)}
                      </td>
                      <td className="px-5 py-3.5 whitespace-nowrap">
                        <div className="font-medium text-gray-900">{log.actor_name}</div>
                        <div className="text-xs text-gray-500">{log.actor_email}</div>
                      </td>
                      <td className="px-5 py-3.5 whitespace-nowrap">
                        {getActionBadge(log.action)}
                      </td>
                      <td className="px-5 py-3.5 whitespace-nowrap">
                        {getOutcomeBadge(log.outcome, log.action)}
                      </td>
                      <td className="px-5 py-3.5 whitespace-nowrap">
                        <span className="font-mono text-xs text-gray-700 font-semibold">
                          {log.entity_type || '—'}
                        </span>
                        {log.entity_id && (
                          <span className="ml-1 text-xs text-gray-500">#{log.entity_id}</span>
                        )}
                      </td>
                      <td className="px-5 py-3.5 text-xs text-gray-600 max-w-xs truncate" title={log.reason || ''}>
                        {log.reason ? log.reason : <span className="text-gray-400 italic">—</span>}
                      </td>
                      <td className="px-5 py-3.5 whitespace-nowrap font-mono text-xs text-gray-500">
                        {log.ip_address || '—'}
                      </td>
                      <td className="px-5 py-3.5 text-right whitespace-nowrap">
                        <button
                          onClick={() => setSelectedLog(log)}
                          className="inline-flex items-center px-2.5 py-1.5 border border-gray-300 shadow-sm text-xs font-medium rounded text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                          title="View Details"
                        >
                          <Eye className="w-3.5 h-3.5 mr-1 text-gray-500" />
                          View
                        </button>
                      </td>
                    </tr>
                  ))
                ) : (
                  <tr>
                    <td colSpan={8} className="px-6 py-12 text-center text-gray-500">
                      <FileText className="mx-auto h-8 w-8 text-gray-400 mb-2" />
                      <p>{t('no_logs')}</p>
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
        <div className="flex justify-between items-center bg-white px-4 py-3 rounded-lg border border-gray-200">
          <button
            disabled={page <= 1}
            onClick={() => setPage(p => Math.max(p - 1, 1))}
            className="inline-flex items-center px-3 py-1.5 text-xs font-medium text-gray-700 bg-gray-50 hover:bg-gray-100 rounded border border-gray-300 disabled:opacity-40 disabled:cursor-not-allowed"
          >
            <ChevronLeft className="w-4 h-4 mr-1" />
            Previous
          </button>
          <span className="text-xs text-gray-600 font-medium">
            Page {data.meta.current_page} of {data.meta.last_page} ({data.meta.total} records)
          </span>
          <button
            disabled={page >= data.meta.last_page}
            onClick={() => setPage(p => p + 1)}
            className="inline-flex items-center px-3 py-1.5 text-xs font-medium text-gray-700 bg-gray-50 hover:bg-gray-100 rounded border border-gray-300 disabled:opacity-40 disabled:cursor-not-allowed"
          >
            Next
            <ChevronRight className="w-4 h-4 ml-1" />
          </button>
        </div>
      )}

      {/* Detail Inspection Modal */}
      {selectedLog && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
          <div className="bg-white rounded-lg max-w-2xl w-full p-6 space-y-4 shadow-xl max-h-[90vh] overflow-y-auto">
            <div className="flex justify-between items-center border-b pb-3">
              <div className="flex items-center space-x-2">
                <Shield className="w-5 h-5 text-indigo-600" />
                <h3 className="text-lg font-bold text-gray-900">{t('modal.title')}</h3>
                <span className="text-xs text-gray-500 font-mono">#{selectedLog.id}</span>
              </div>
              <button
                onClick={() => setSelectedLog(null)}
                className="text-gray-400 hover:text-gray-600 p-1"
                aria-label="Close"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
              <div className="bg-gray-50 p-3 rounded border">
                <span className="text-gray-500 block mb-0.5">{t('modal.action')}</span>
                <span className="font-mono font-bold text-gray-900">{selectedLog.action}</span>
              </div>
              <div className="bg-gray-50 p-3 rounded border">
                <span className="text-gray-500 block mb-0.5">{t('modal.outcome')}</span>
                <div className="mt-1">
                  {getOutcomeBadge(selectedLog.outcome, selectedLog.action)}
                </div>
              </div>
              <div className="bg-gray-50 p-3 rounded border">
                <span className="text-gray-500 block mb-0.5">{t('modal.target')}</span>
                <span className="font-mono font-bold text-gray-900">
                  {selectedLog.entity_type || '—'} {selectedLog.entity_id ? `(#${selectedLog.entity_id})` : ''}
                </span>
              </div>
              <div className="bg-gray-50 p-3 rounded border">
                <span className="text-gray-500 block mb-0.5">{t('modal.actor')}</span>
                <span className="font-medium text-gray-900">{selectedLog.actor_name}</span>
                <span className="text-gray-500 block">{selectedLog.actor_email}</span>
                {selectedLog.actor_user_id && (
                  <span className="text-gray-400 font-mono text-[10px]">User ID: {selectedLog.actor_user_id}</span>
                )}
              </div>
              <div className="bg-gray-50 p-3 rounded border">
                <span className="text-gray-500 block mb-0.5">{t('modal.timestamp')}</span>
                <span className="font-mono text-gray-900 flex items-center mt-1">
                  <Clock className="w-3.5 h-3.5 mr-1 text-gray-400" />
                  {formatUtcDate(selectedLog.created_at)}
                </span>
              </div>
              <div className="bg-gray-50 p-3 rounded border md:col-span-2">
                <span className="text-gray-500 block mb-0.5">{t('modal.ip_agent')}</span>
                <div className="font-mono text-gray-900">{selectedLog.ip_address || '—'}</div>
                <div className="text-gray-600 text-[11px] truncate mt-0.5" title={selectedLog.user_agent || ''}>
                  {selectedLog.user_agent || '—'}
                </div>
              </div>
              <div className="bg-gray-50 p-3 rounded border md:col-span-2">
                <span className="text-gray-500 block mb-0.5">{t('modal.request_id')}</span>
                <span className="font-mono text-gray-700 text-[11px]">
                  {selectedLog.request_id || '—'}
                </span>
              </div>
              <div className="bg-gray-50 p-3 rounded border md:col-span-2">
                <span className="text-gray-500 block mb-0.5">{t('modal.reason')}</span>
                <p className="text-gray-800 text-xs italic">
                  {selectedLog.reason || 'No specific operational reason provided.'}
                </p>
              </div>
            </div>

            {/* State Snapshots */}
            <div className="space-y-3 pt-2">
              <h4 className="text-xs font-bold uppercase tracking-wider text-gray-700">
                {t('modal.state_diff')}
              </h4>

              {selectedLog.before_state || selectedLog.after_state ? (
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div>
                    <span className="text-xs font-medium text-gray-600 block mb-1">
                      {t('modal.before')}
                    </span>
                    <pre className="bg-gray-900 text-gray-100 p-3 rounded text-[11px] font-mono overflow-x-auto max-h-48">
                      {selectedLog.before_state
                        ? JSON.stringify(selectedLog.before_state, null, 2)
                        : '// null'}
                    </pre>
                  </div>
                  <div>
                    <span className="text-xs font-medium text-gray-600 block mb-1">
                      {t('modal.after')}
                    </span>
                    <pre className="bg-gray-900 text-gray-100 p-3 rounded text-[11px] font-mono overflow-x-auto max-h-48">
                      {selectedLog.after_state
                        ? JSON.stringify(selectedLog.after_state, null, 2)
                        : '// null'}
                    </pre>
                  </div>
                </div>
              ) : (
                <div className="text-xs text-gray-500 bg-gray-50 p-3 rounded border italic">
                  {t('modal.no_state')}
                </div>
              )}
            </div>

            <div className="flex justify-end pt-3 border-t">
              <button
                type="button"
                onClick={() => setSelectedLog(null)}
                className="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-md transition-colors"
              >
                {t('modal.close')}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
