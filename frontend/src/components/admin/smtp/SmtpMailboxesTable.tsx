'use client';

import { useState } from 'react';
import { useTranslations } from 'next-intl';
import useSWR from 'swr';
import Link from 'next/link';
import api from '@/lib/api';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Spinner } from '@/components/ui/spinner';
import { PaginatedResponse, SmtpMailbox } from '@/types';
import { 
  Search, 
  RotateCcw, 
  Key, 
  Power, 
  Copy, 
  Check, 
  AlertTriangle, 
  ExternalLink,
  ShieldAlert,
  Info
} from 'lucide-react';

interface SmtpMailboxesTableProps {
  initialSearch?: string;
  onActionComplete?: () => void;
}

export default function SmtpMailboxesTable({ 
  initialSearch = '', 
  onActionComplete 
}: SmtpMailboxesTableProps) {
  const t = useTranslations('Admin.smtp.mailboxes');
  const tCommon = useTranslations('Admin.common');

  const [page, setPage] = useState(1);
  const [search, setSearch] = useState(initialSearch);
  const [statusFilter, setStatusFilter] = useState<'all' | 'active' | 'disabled'>('all');

  // Toggle Modal State
  const [toggleTarget, setToggleTarget] = useState<SmtpMailbox | null>(null);
  const [toggleReason, setToggleReason] = useState('');
  const [isToggling, setIsToggling] = useState(false);
  const [toggleError, setToggleError] = useState<string | null>(null);

  // Reset Bounces Modal State
  const [resetBounceTarget, setResetBounceTarget] = useState<SmtpMailbox | null>(null);
  const [resetBounceReason, setResetBounceReason] = useState('');
  const [isResettingBounces, setIsResettingBounces] = useState(false);
  const [resetBounceError, setResetBounceError] = useState<string | null>(null);

  // Reset Password Modal State
  const [passwordTarget, setPasswordTarget] = useState<SmtpMailbox | null>(null);
  const [customPassword, setCustomPassword] = useState('');
  const [passwordReason, setPasswordReason] = useState('');
  const [isResettingPassword, setIsResettingPassword] = useState(false);
  const [passwordError, setPasswordError] = useState<string | null>(null);
  const [generatedPassword, setGeneratedPassword] = useState<string | null>(null);
  const [copied, setCopied] = useState(false);

  // Build query string
  const queryParams = new URLSearchParams({
    page: page.toString(),
    search,
  });
  if (statusFilter === 'active') queryParams.append('is_active', 'true');
  if (statusFilter === 'disabled') queryParams.append('is_active', 'false');

  const fetcher = (url: string) => api.get(url).then(res => res.data);
  const { data, error, isLoading, mutate } = useSWR<PaginatedResponse<SmtpMailbox>>(
    `/api/admin/smtp/mailboxes?${queryParams.toString()}`,
    fetcher
  );

  // Handlers
  const handleToggleConfirm = async () => {
    if (!toggleTarget) return;
    setIsToggling(true);
    setToggleError(null);

    try {
      await api.post(`/api/admin/smtp/mailboxes/${toggleTarget.id}/toggle`, {
        reason: toggleReason.trim() || undefined,
      });
      setToggleTarget(null);
      setToggleReason('');
      mutate();
      if (onActionComplete) onActionComplete();
    } catch (err: any) {
      setToggleError(err.response?.data?.message || 'Failed to toggle mailbox status.');
    } finally {
      setIsToggling(false);
    }
  };

  const handleResetBouncesConfirm = async () => {
    if (!resetBounceTarget) return;
    setIsResettingBounces(true);
    setResetBounceError(null);

    try {
      await api.post(`/api/admin/smtp/mailboxes/${resetBounceTarget.id}/reset-bounces`, {
        reason: resetBounceReason.trim() || undefined,
      });
      setResetBounceTarget(null);
      setResetBounceReason('');
      mutate();
      if (onActionComplete) onActionComplete();
    } catch (err: any) {
      setResetBounceError(err.response?.data?.message || 'Failed to reset consecutive bounces.');
    } finally {
      setIsResettingBounces(false);
    }
  };

  const handleResetPasswordSubmit = async () => {
    if (!passwordTarget) return;
    setIsResettingPassword(true);
    setPasswordError(null);

    try {
      const payload: { password?: string; reason?: string } = {};
      if (customPassword.trim()) payload.password = customPassword.trim();
      if (passwordReason.trim()) payload.reason = passwordReason.trim();

      const res = await api.post(`/api/admin/smtp/mailboxes/${passwordTarget.id}/reset-password`, payload);
      setGeneratedPassword(res.data.temporary_password);
      mutate();
      if (onActionComplete) onActionComplete();
    } catch (err: any) {
      setPasswordError(err.response?.data?.message || 'Failed to reset mailbox password.');
    } finally {
      setIsResettingPassword(false);
    }
  };

  const copyToClipboard = (text: string) => {
    navigator.clipboard.writeText(text);
    setCopied(true);
    setTimeout(() => setCopied(false), 2000);
  };

  const closePasswordModal = () => {
    setPasswordTarget(null);
    setCustomPassword('');
    setPasswordReason('');
    setGeneratedPassword(null);
    setPasswordError(null);
    setCopied(false);
  };

  return (
    <div className="space-y-4">
      {/* Header & Filter Controls */}
      <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
          <h2 className="text-lg font-bold text-gray-900">{t('title')}</h2>
          <p className="text-xs text-gray-500">Inspect outbound mailbox telemetry, reset failure streaks, and manage active status</p>
        </div>
        <div className="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 w-full sm:w-auto">
          {/* Status Filter */}
          <select
            value={statusFilter}
            onChange={(e) => {
              setStatusFilter(e.target.value as any);
              setPage(1);
            }}
            className="border border-gray-300 rounded-md px-3 py-2 text-sm bg-white focus:ring-2 focus:ring-indigo-500 focus:outline-none"
          >
            <option value="all">{tCommon('all')} Status</option>
            <option value="active">{t('status.active')}</option>
            <option value="disabled">{t('status.disabled')}</option>
          </select>

          {/* Search Input */}
          <div className="relative w-full sm:w-64">
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
      </div>

      {/* Table Card */}
      <Card>
        <CardContent className="p-0">
          <div className="overflow-x-auto">
            <table className="w-full text-sm text-left text-gray-500">
              <thead className="text-xs text-gray-700 uppercase bg-gray-50 border-b">
                <tr>
                  <th className="px-6 py-3">{t('table.email')}</th>
                  <th className="px-6 py-3">{t('table.domain')}</th>
                  <th className="px-6 py-3">{t('table.tenant')}</th>
                  <th className="px-6 py-3">{t('table.status')}</th>
                  <th className="px-6 py-3">{t('table.today_recipients')}</th>
                  <th className="px-6 py-3">{t('table.consecutive_bounces')}</th>
                  <th className="px-6 py-3 text-right">{t('table.actions')}</th>
                </tr>
              </thead>
              <tbody>
                {isLoading ? (
                  <tr>
                    <td colSpan={7} className="px-6 py-12 text-center">
                      <div className="flex justify-center"><Spinner /></div>
                    </td>
                  </tr>
                ) : error ? (
                  <tr>
                    <td colSpan={7} className="px-6 py-8 text-center text-red-500">
                      {tCommon('error')}
                    </td>
                  </tr>
                ) : data?.data && data.data.length > 0 ? (
                  data.data.map((mb) => (
                    <tr key={mb.id} className="bg-white border-b hover:bg-gray-50">
                      {/* Email */}
                      <td className="px-6 py-4">
                        <div className="font-semibold text-gray-900">{mb.email}</div>
                        {mb.display_name && (
                          <div className="text-xs text-gray-400">{mb.display_name}</div>
                        )}
                      </td>

                      {/* Domain */}
                      <td className="px-6 py-4">
                        <div className="text-gray-800">{mb.domain_name}</div>
                        <span className={`text-[10px] uppercase font-semibold ${
                          mb.parent_domain_status === 'active' ? 'text-green-600' : 'text-amber-600'
                        }`}>
                          {mb.parent_domain_status}
                        </span>
                      </td>

                      {/* Tenant */}
                      <td className="px-6 py-4">
                        {mb.tenant_id ? (
                          <Link
                            href={`/admin/tenants/${mb.tenant_id}`}
                            className="text-indigo-600 hover:underline flex items-center gap-1 group"
                          >
                            <span>{mb.tenant_name}</span>
                            <ExternalLink className="w-3 h-3 opacity-0 group-hover:opacity-100 transition-opacity" />
                          </Link>
                        ) : (
                          <span className="text-gray-400">Unassigned</span>
                        )}
                      </td>

                      {/* Status */}
                      <td className="px-6 py-4">
                        <Badge variant={mb.is_active ? 'success' : 'warning'}>
                          {mb.is_active ? t('status.active') : t('status.disabled')}
                        </Badge>
                      </td>

                      {/* Today's Recipients */}
                      <td className="px-6 py-4 font-mono">
                        {mb.telemetry_available ? (
                          <span className="text-gray-700">{mb.today_recipients ?? 0}</span>
                        ) : (
                          <span className="text-gray-400">N/A</span>
                        )}
                      </td>

                      {/* Consecutive Hard Bounces */}
                      <td className="px-6 py-4 font-mono">
                        {mb.telemetry_available ? (
                          mb.consecutive_hard_bounces && mb.consecutive_hard_bounces > 0 ? (
                            <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold bg-red-100 text-red-800">
                              <AlertTriangle className="w-3 h-3 mr-1" />
                              {mb.consecutive_hard_bounces}
                            </span>
                          ) : (
                            <span className="text-gray-600">0</span>
                          )
                        ) : (
                          <span className="text-gray-400">N/A</span>
                        )}
                      </td>

                      {/* Actions */}
                      <td className="px-6 py-4 text-right">
                        <div className="flex items-center justify-end space-x-2">
                          {/* Toggle Active / Disabled */}
                          <button
                            onClick={() => {
                              setToggleTarget(mb);
                              setToggleReason('');
                              setToggleError(null);
                            }}
                            title={mb.is_active ? t('actions.disable') : t('actions.enable')}
                            className={`p-1.5 rounded transition-colors ${
                              mb.is_active
                                ? 'text-amber-600 hover:bg-amber-50 hover:text-amber-800'
                                : 'text-green-600 hover:bg-green-50 hover:text-green-800'
                            }`}
                          >
                            <Power className="w-4 h-4" />
                          </button>

                          {/* Reset Bounces */}
                          <button
                            onClick={() => {
                              setResetBounceTarget(mb);
                              setResetBounceReason('');
                              setResetBounceError(null);
                            }}
                            title={t('actions.reset_bounces')}
                            disabled={!mb.telemetry_available}
                            className="p-1.5 text-blue-600 hover:bg-blue-50 hover:text-blue-800 rounded transition-colors disabled:opacity-40"
                          >
                            <RotateCcw className="w-4 h-4" />
                          </button>

                          {/* Reset Password */}
                          <button
                            onClick={() => {
                              setPasswordTarget(mb);
                              setCustomPassword('');
                              setPasswordReason('');
                              setGeneratedPassword(null);
                              setPasswordError(null);
                            }}
                            title={t('actions.reset_password')}
                            className="p-1.5 text-purple-600 hover:bg-purple-50 hover:text-purple-800 rounded transition-colors"
                          >
                            <Key className="w-4 h-4" />
                          </button>
                        </div>
                      </td>
                    </tr>
                  ))
                ) : (
                  <tr>
                    <td colSpan={7} className="px-6 py-12 text-center text-gray-400">
                      No mailboxes found matching criteria.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </CardContent>
      </Card>

      {/* Pagination */}
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

      {/* MODAL: Toggle Mailbox Status */}
      {toggleTarget && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
          <div className="bg-white rounded-lg max-w-md w-full p-6 space-y-4 shadow-xl">
            <div className="flex items-center space-x-2">
              <Power className={`w-5 h-5 ${toggleTarget.is_active ? 'text-amber-600' : 'text-green-600'}`} />
              <h3 className="text-lg font-bold text-gray-900">{t('modals.toggle_title')}</h3>
            </div>

            <p className="text-sm text-gray-600">
              Target: <span className="font-semibold text-gray-800">{toggleTarget.email}</span>
            </p>

            {toggleTarget.is_active ? (
              <p className="text-sm text-amber-700 bg-amber-50 p-3 rounded-md border border-amber-200">
                {t('modals.disable_confirm')}
              </p>
            ) : (
              <div>
                {!toggleTarget.can_be_enabled ? (
                  <div className="text-sm text-red-700 bg-red-50 p-3 rounded-md border border-red-200 space-y-2">
                    <div className="flex items-center space-x-1.5 font-semibold">
                      <ShieldAlert className="w-4 h-4" />
                      <span>Cannot Enable Mailbox: Parent Constraints Inactive</span>
                    </div>
                    <ul className="text-xs list-disc list-inside space-y-0.5 text-red-600">
                      <li>Parent Domain Status: <span className="font-semibold">{toggleTarget.parent_domain_status}</span> (must be active)</li>
                      <li>Parent Tenant Status: <span className="font-semibold">{toggleTarget.parent_tenant_status}</span> (must be active)</li>
                      <li>Tenant Subscription: <span className="font-semibold">{toggleTarget.parent_subscription_active ? 'Active' : 'Inactive'}</span> (must be active)</li>
                    </ul>
                  </div>
                ) : (
                  <p className="text-sm text-green-700 bg-green-50 p-3 rounded-md border border-green-200">
                    {t('modals.enable_confirm')}
                  </p>
                )}
              </div>
            )}

            {/* Optional Reason */}
            <div>
              <label className="block text-xs font-medium text-gray-700 mb-1">
                {t('modals.reason_label')}
              </label>
              <input
                type="text"
                value={toggleReason}
                onChange={(e) => setToggleReason(e.target.value)}
                placeholder="e.g. Account security audit or temporary hold"
                className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none"
              />
            </div>

            {toggleError && (
              <div className="text-xs text-red-600 bg-red-50 p-2 rounded border border-red-200">
                {toggleError}
              </div>
            )}

            <div className="flex justify-end space-x-3 pt-2">
              <button
                type="button"
                onClick={() => setToggleTarget(null)}
                disabled={isToggling}
                className="px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 rounded-md"
              >
                {t('modals.cancel')}
              </button>
              <button
                type="button"
                onClick={handleToggleConfirm}
                disabled={isToggling || (!toggleTarget.is_active && !toggleTarget.can_be_enabled)}
                className={`px-4 py-2 text-sm font-medium text-white rounded-md shadow-sm transition-colors disabled:opacity-50 ${
                  toggleTarget.is_active
                    ? 'bg-amber-600 hover:bg-amber-700'
                    : 'bg-green-600 hover:bg-green-700'
                }`}
              >
                {isToggling ? 'Processing...' : t('modals.confirm')}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* MODAL: Reset Consecutive Hard Bounces */}
      {resetBounceTarget && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
          <div className="bg-white rounded-lg max-w-md w-full p-6 space-y-4 shadow-xl">
            <div className="flex items-center space-x-2">
              <RotateCcw className="w-5 h-5 text-blue-600" />
              <h3 className="text-lg font-bold text-gray-900">{t('modals.reset_bounces_title')}</h3>
            </div>

            <p className="text-sm text-gray-600">
              Target: <span className="font-semibold text-gray-800">{resetBounceTarget.email}</span>
            </p>

            <div className="text-xs text-blue-700 bg-blue-50 p-3 rounded-md border border-blue-200">
              {t('modals.reset_bounces_confirm')}
            </div>

            <div>
              <label className="block text-xs font-medium text-gray-700 mb-1">
                {t('modals.reason_label')}
              </label>
              <input
                type="text"
                value={resetBounceReason}
                onChange={(e) => setResetBounceReason(e.target.value)}
                placeholder="e.g. Recipient issue resolved by tenant"
                className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none"
              />
            </div>

            {resetBounceError && (
              <div className="text-xs text-red-600 bg-red-50 p-2 rounded border border-red-200">
                {resetBounceError}
              </div>
            )}

            <div className="flex justify-end space-x-3 pt-2">
              <button
                type="button"
                onClick={() => setResetBounceTarget(null)}
                disabled={isResettingBounces}
                className="px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 rounded-md"
              >
                {t('modals.cancel')}
              </button>
              <button
                type="button"
                onClick={handleResetBouncesConfirm}
                disabled={isResettingBounces}
                className="px-4 py-2 text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-md shadow-sm transition-colors disabled:opacity-50"
              >
                {isResettingBounces ? 'Resetting...' : t('modals.confirm')}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* MODAL: Reset Password */}
      {passwordTarget && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
          <div className="bg-white rounded-lg max-w-md w-full p-6 space-y-4 shadow-xl">
            <div className="flex items-center space-x-2">
              <Key className="w-5 h-5 text-purple-600" />
              <h3 className="text-lg font-bold text-gray-900">{t('modals.reset_password_title')}</h3>
            </div>

            <p className="text-sm text-gray-600">
              Target: <span className="font-semibold text-gray-800">{passwordTarget.email}</span>
            </p>

            {!generatedPassword ? (
              <>
                <p className="text-xs text-gray-500">
                  {t('modals.reset_password_desc')}
                </p>

                <div>
                  <label className="block text-xs font-medium text-gray-700 mb-1">
                    {t('modals.custom_password_label')}
                  </label>
                  <input
                    type="text"
                    value={customPassword}
                    onChange={(e) => setCustomPassword(e.target.value)}
                    placeholder="Auto-generate secure 16-char password"
                    className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm font-mono focus:ring-2 focus:ring-indigo-500 focus:outline-none"
                  />
                </div>

                <div>
                  <label className="block text-xs font-medium text-gray-700 mb-1">
                    {t('modals.reason_label')}
                  </label>
                  <input
                    type="text"
                    value={passwordReason}
                    onChange={(e) => setPasswordReason(e.target.value)}
                    placeholder="e.g. Tenant requested admin password reset"
                    className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none"
                  />
                </div>

                {passwordError && (
                  <div className="text-xs text-red-600 bg-red-50 p-2 rounded border border-red-200">
                    {passwordError}
                  </div>
                )}

                <div className="flex justify-end space-x-3 pt-2">
                  <button
                    type="button"
                    onClick={closePasswordModal}
                    disabled={isResettingPassword}
                    className="px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 rounded-md"
                  >
                    {t('modals.cancel')}
                  </button>
                  <button
                    type="button"
                    onClick={handleResetPasswordSubmit}
                    disabled={isResettingPassword}
                    className="px-4 py-2 text-sm font-medium text-white bg-purple-600 hover:bg-purple-700 rounded-md shadow-sm transition-colors disabled:opacity-50"
                  >
                    {isResettingPassword ? 'Generating...' : t('modals.confirm')}
                  </button>
                </div>
              </>
            ) : (
              /* One-time password display view */
              <div className="space-y-4 pt-2">
                <div className="p-4 bg-green-50 border border-green-200 rounded-lg">
                  <p className="text-xs font-semibold text-green-800 uppercase tracking-wider mb-1">
                    {t('modals.new_password_alert')}
                  </p>
                  <div className="flex items-center justify-between bg-white border border-green-300 rounded p-2.5 font-mono text-sm font-bold text-gray-900 select-all">
                    <span>{generatedPassword}</span>
                    <button
                      type="button"
                      onClick={() => copyToClipboard(generatedPassword)}
                      className="p-1 text-green-700 hover:text-green-900 transition-colors ml-2"
                      title="Copy to clipboard"
                    >
                      {copied ? <Check className="w-4 h-4 text-green-600" /> : <Copy className="w-4 h-4" />}
                    </button>
                  </div>
                  {copied && (
                    <p className="text-xs text-green-600 font-semibold mt-1">
                      {t('modals.password_copied')}
                    </p>
                  )}
                  <p className="text-[11px] text-amber-700 mt-2 flex items-center gap-1">
                    <Info className="w-3.5 h-3.5 shrink-0" />
                    <span>This password is stored securely as SHA512-CRYPT and will never be shown again.</span>
                  </p>
                </div>

                <div className="flex justify-end pt-2">
                  <button
                    type="button"
                    onClick={closePasswordModal}
                    className="px-4 py-2 text-sm font-medium text-white bg-gray-900 hover:bg-gray-800 rounded-md"
                  >
                    {t('modals.close')}
                  </button>
                </div>
              </div>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
