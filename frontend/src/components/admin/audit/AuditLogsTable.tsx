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
  Clock
} from 'lucide-react';

export default function AuditLogsTable() {
  const t = useTranslations('Admin.audit_logs');
  const tCommon = useTranslations('Admin.common');

  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [actionFilter, setActionFilter] = useState('');
  const [entityFilter, setEntityFilter] = useState('');
  const [selectedLog, setSelectedLog] = useState<AuditLog | null>(null);

  // Build query string
  const queryParams = new URLSearchParams({
    page: page.toString(),
  });
  if (search.trim()) queryParams.append('search', search.trim());
  if (actionFilter) queryParams.append('action', actionFilter);
  if (entityFilter) queryParams.append('entity_type', entityFilter);

  const fetcher = (url: string) => api.get(url).then(res => res.data);
  const { data, error, isLoading } = useSWR<PaginatedResponse<AuditLog>>(
    `/api/admin/audit-logs?${queryParams.toString()}`,
    fetcher
  );

  const formatUtcDate = (dateStr: string) => {
    try {
      const d = new Date(dateStr);
      if (isNaN(d.getTime())) return dateStr;
      return d.toISOString().replace('T', ' ').slice(0, 19) + ' UTC';
    } catch {
      return dateStr;
    }
  };

  const getActionBadge = (action: string) => {
    if (action.includes('toggle')) {
      return <Badge variant="warning">{action}</Badge>;
    }
    if (action.includes('password')) {
      return <Badge variant="destructive">{action}</Badge>;
    }
    if (action.includes('bounce') || action.includes('reset')) {
      return <Badge variant="secondary">{action}</Badge>;
    }
    return <Badge variant="outline">{action}</Badge>;
  };

  return (
    <div className="space-y-4">
      {/* Controls: Search and Filters */}
      <div className="flex flex-col sm:flex-row gap-3 items-stretch sm:items-center justify-between">
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

        <div className="flex gap-2">
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
            <option value="admin.smtp.mailbox.toggle">admin.smtp.mailbox.toggle</option>
            <option value="admin.smtp.mailbox.reset_password">admin.smtp.mailbox.reset_password</option>
            <option value="admin.smtp.mailbox.reset_consecutive_bounces">admin.smtp.mailbox.reset_consecutive_bounces</option>
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
            <option value="tenant">Tenant</option>
            <option value="domain">Domain</option>
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
                  <th className="px-5 py-3.5 whitespace-nowrap">{t('table.entity')}</th>
                  <th className="px-5 py-3.5 whitespace-nowrap">{t('table.reason')}</th>
                  <th className="px-5 py-3.5 whitespace-nowrap">{t('table.ip')}</th>
                  <th className="px-5 py-3.5 text-right whitespace-nowrap">{t('table.actions')}</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200">
                {isLoading ? (
                  <tr>
                    <td colSpan={7} className="px-6 py-12 text-center">
                      <div className="flex justify-center items-center space-x-2 text-gray-500">
                        <Spinner />
                        <span>{tCommon('loading')}</span>
                      </div>
                    </td>
                  </tr>
                ) : error ? (
                  <tr>
                    <td colSpan={7} className="px-6 py-12 text-center text-red-500">
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
                    <td colSpan={7} className="px-6 py-12 text-center text-gray-500">
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
