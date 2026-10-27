'use client';

import { useState, useEffect } from 'react';
import Link from 'next/link';
import {
  Bell,
  Check,
  CheckCheck,
  Archive,
  ExternalLink,
  Filter,
  RefreshCw,
  ChevronLeft,
  ChevronRight,
  ShieldAlert,
  Globe,
  Gauge,
  CreditCard,
  Mail,
  Info,
  AlertCircle,
  Inbox,
  RotateCcw,
  Sliders,
  ShieldCheck,
  Lock,
} from 'lucide-react';
import {
  useNotifications,
  useUnreadCount,
  useNotificationCounts,
  useNotificationPreferences,
  updateNotificationPreferences,
  markNotificationAsRead,
  markAllNotificationsAsRead,
  dismissNotification,
  restoreNotification,
} from '@/hooks/useNotifications';
import {
  getCategoryIcon,
  getSeverityBadge,
  formatTimeAgo,
} from '@/components/notifications/NotificationDrawer';
import {
  TenantNotification,
  TenantNotificationCategory,
  TenantNotificationSeverity,
  NotificationPreferences,
} from '@/types';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Spinner } from '@/components/ui/spinner';
import { Card } from '@/components/ui/card';

export default function NotificationsPage() {
  const [activeTab, setActiveTab] = useState<'alerts' | 'preferences'>('alerts');
  const [page, setPage] = useState(1);
  const [status, setStatus] = useState<'all' | 'unread' | 'read' | 'dismissed'>('all');
  const [category, setCategory] = useState<string>('all');
  const [severity, setSeverity] = useState<string>('all');
  const [isMarkingAll, setIsMarkingAll] = useState(false);
  const [actionInProgress, setActionInProgress] = useState<number | null>(null);

  const { unreadCount, mutate: mutateCount } = useUnreadCount();
  const { counts, mutate: mutateCounts } = useNotificationCounts();
  const { notifications, meta, isLoading, error, mutate: mutateList } = useNotifications({
    page,
    per_page: 15,
    status: status === 'all' ? undefined : status,
    category: category === 'all' ? undefined : (category as TenantNotificationCategory),
    severity: severity === 'all' ? undefined : (severity as TenantNotificationSeverity),
  });

  const handleMarkAsRead = async (id: number) => {
    setActionInProgress(id);
    try {
      await markNotificationAsRead(id);
      mutateList();
      mutateCount();
      mutateCounts();
    } catch (err) {
      console.error('Failed to mark notification as read:', err);
    } finally {
      setActionInProgress(null);
    }
  };

  const handleDismiss = async (id: number) => {
    setActionInProgress(id);
    try {
      await dismissNotification(id);
      mutateList();
      mutateCount();
      mutateCounts();
    } catch (err) {
      console.error('Failed to dismiss notification:', err);
    } finally {
      setActionInProgress(null);
    }
  };

  const handleRestore = async (id: number) => {
    setActionInProgress(id);
    try {
      await restoreNotification(id);
      mutateList();
      mutateCount();
      mutateCounts();
    } catch (err) {
      console.error('Failed to restore notification:', err);
    } finally {
      setActionInProgress(null);
    }
  };

  const handleMarkAllRead = async () => {
    setIsMarkingAll(true);
    try {
      await markAllNotificationsAsRead();
      mutateList();
      mutateCount();
      mutateCounts();
    } catch (err) {
      console.error('Failed to mark all as read:', err);
    } finally {
      setIsMarkingAll(false);
    }
  };

  return (
    <div className="space-y-6">
      {/* Top Header */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <div className="flex items-center space-x-3">
            <h1 className="text-2xl font-bold text-gray-900">Notification Center</h1>
            {unreadCount > 0 && (
              <Badge variant="destructive" className="text-xs px-2 py-0.5 font-semibold">
                {unreadCount} Unread
              </Badge>
            )}
          </div>
          <p className="mt-1 text-sm text-gray-500">
            Real-time security alerts, DNS drift warnings, SMTP quota usage, and notification preferences.
          </p>
        </div>

        {/* Tab & Action Controls */}
        <div className="flex items-center space-x-2">
          {/* Main View Switcher */}
          <div className="flex items-center p-1 bg-gray-100 rounded-lg border">
            <button
              type="button"
              onClick={() => setActiveTab('alerts')}
              className={`px-3 py-1.5 rounded-md text-xs font-semibold transition-colors flex items-center space-x-1.5 ${
                activeTab === 'alerts'
                  ? 'bg-white text-gray-900 shadow-xs'
                  : 'text-gray-600 hover:text-gray-900'
              }`}
            >
              <Bell className="h-3.5 w-3.5" />
              <span>Alerts & Activity</span>
            </button>
            <button
              type="button"
              onClick={() => setActiveTab('preferences')}
              className={`px-3 py-1.5 rounded-md text-xs font-semibold transition-colors flex items-center space-x-1.5 ${
                activeTab === 'preferences'
                  ? 'bg-white text-indigo-700 shadow-xs'
                  : 'text-gray-600 hover:text-gray-900'
              }`}
            >
              <Sliders className="h-3.5 w-3.5" />
              <span>Preferences</span>
            </button>
          </div>

          {activeTab === 'alerts' && (
            <>
              <Button
                variant="outline"
                size="sm"
                onClick={() => {
                  mutateList();
                  mutateCount();
                  mutateCounts();
                }}
                disabled={isLoading}
                className="text-xs"
              >
                <RefreshCw className={`h-3.5 w-3.5 mr-1.5 ${isLoading ? 'animate-spin' : ''}`} />
                Refresh
              </Button>

              {unreadCount > 0 && (
                <Button
                  variant="default"
                  size="sm"
                  onClick={handleMarkAllRead}
                  disabled={isMarkingAll || isLoading}
                  className="bg-indigo-600 hover:bg-indigo-700 text-xs"
                >
                  <CheckCheck className="h-3.5 w-3.5 mr-1.5" />
                  {isMarkingAll ? 'Marking...' : 'Mark all read'}
                </Button>
              )}
            </>
          )}
        </div>
      </div>

      {activeTab === 'preferences' ? (
        <NotificationPreferencesCard />
      ) : (
        <>
          {/* Filter and Search Bar */}
          <Card className="p-4 bg-white border shadow-xs">
            <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
              {/* Status Tabs */}
              <div className="flex flex-wrap items-center gap-1 p-1 bg-gray-100 rounded-lg">
                <button
                  type="button"
                  onClick={() => {
                    setStatus('all');
                    setPage(1);
                  }}
                  className={`px-3 py-1.5 rounded-md text-xs font-medium transition-colors ${
                    status === 'all'
                      ? 'bg-white text-gray-900 shadow-xs'
                      : 'text-gray-600 hover:text-gray-900'
                  }`}
                >
                  All Active {counts ? `(${counts.total_active})` : ''}
                </button>
                <button
                  type="button"
                  onClick={() => {
                    setStatus('unread');
                    setPage(1);
                  }}
                  className={`px-3 py-1.5 rounded-md text-xs font-medium transition-colors flex items-center space-x-1.5 ${
                    status === 'unread'
                      ? 'bg-white text-indigo-700 shadow-xs'
                      : 'text-gray-600 hover:text-gray-900'
                  }`}
                >
                  <span>Unread</span>
                  {unreadCount > 0 && (
                    <Badge variant="destructive" className="h-4 px-1.5 text-[10px] font-bold">
                      {unreadCount}
                    </Badge>
                  )}
                </button>
                <button
                  type="button"
                  onClick={() => {
                    setStatus('read');
                    setPage(1);
                  }}
                  className={`px-3 py-1.5 rounded-md text-xs font-medium transition-colors ${
                    status === 'read'
                      ? 'bg-white text-gray-900 shadow-xs'
                      : 'text-gray-600 hover:text-gray-900'
                  }`}
                >
                  Read {counts ? `(${counts.read})` : ''}
                </button>
                <button
                  type="button"
                  onClick={() => {
                    setStatus('dismissed');
                    setPage(1);
                  }}
                  className={`px-3 py-1.5 rounded-md text-xs font-medium transition-colors flex items-center space-x-1.5 ${
                    status === 'dismissed'
                      ? 'bg-white text-gray-900 shadow-xs'
                      : 'text-gray-600 hover:text-gray-900'
                  }`}
                >
                  <Archive className="h-3 w-3 text-gray-400" />
                  <span>Dismissed {counts ? `(${counts.dismissed})` : ''}</span>
                </button>
              </div>

              {/* Category & Severity Selects */}
              <div className="flex flex-wrap items-center gap-3">
                <div className="flex items-center space-x-2">
                  <label htmlFor="filter-category" className="text-xs text-gray-500 font-medium">
                    Category:
                  </label>
                  <select
                    id="filter-category"
                    value={category}
                    onChange={(e) => {
                      setCategory(e.target.value);
                      setPage(1);
                    }}
                    className="text-xs border rounded-lg px-2.5 py-1.5 bg-white text-gray-700 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                  >
                    <option value="all">All Categories</option>
                    <option value="domain_dns">Domain & DNS</option>
                    <option value="security_abuse">Security & Abuse</option>
                    <option value="smtp_quota">SMTP Quota</option>
                    <option value="billing_subscription">Billing & Plans</option>
                    <option value="mailbox_rule">Mailbox Rules</option>
                    <option value="system">System Updates</option>
                  </select>
                </div>

                <div className="flex items-center space-x-2">
                  <label htmlFor="filter-severity" className="text-xs text-gray-500 font-medium">
                    Severity:
                  </label>
                  <select
                    id="filter-severity"
                    value={severity}
                    onChange={(e) => {
                      setSeverity(e.target.value);
                      setPage(1);
                    }}
                    className="text-xs border rounded-lg px-2.5 py-1.5 bg-white text-gray-700 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                  >
                    <option value="all">All Severities</option>
                    <option value="critical">Critical</option>
                    <option value="warning">Warning</option>
                    <option value="info">Info</option>
                    <option value="success">Success / Resolved</option>
                  </select>
                </div>
              </div>
            </div>
          </Card>

          {/* Main Notification Stream */}
          <Card className="bg-white border shadow-xs overflow-hidden">
            {isLoading ? (
              <div className="flex flex-col items-center justify-center py-20">
                <Spinner className="h-8 w-8 text-indigo-600 mb-3" />
                <p className="text-sm text-gray-500">Loading notifications...</p>
              </div>
            ) : error ? (
              <div className="p-8 text-center">
                <AlertCircle className="h-8 w-8 text-red-500 mx-auto mb-2" />
                <p className="text-sm font-semibold text-gray-900">Failed to load notifications</p>
                <p className="text-xs text-gray-500 mt-1">
                  An error occurred while fetching your alert ledger.
                </p>
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => mutateList()}
                  className="mt-4 text-xs"
                >
                  Try Again
                </Button>
              </div>
            ) : notifications.length === 0 ? (
              <div className="text-center py-16 px-4">
                <div className="h-12 w-12 rounded-full bg-gray-100 flex items-center justify-center mx-auto mb-3">
                  <Inbox className="h-6 w-6 text-gray-400" />
                </div>
                <h3 className="text-sm font-semibold text-gray-900">No notifications found</h3>
                <p className="text-xs text-gray-500 mt-1 max-w-sm mx-auto">
                  {status === 'unread'
                    ? 'You have caught up with all your alerts! No unread notifications.'
                    : status === 'dismissed'
                    ? 'No dismissed notifications archived.'
                    : 'There are no notifications matching the selected filters.'}
                </p>
              </div>
            ) : (
              <div className="divide-y divide-gray-100">
                {notifications.map((item: TenantNotification) => {
                  const isActioning = actionInProgress === item.id;
                  return (
                    <div
                      key={item.id}
                      className={`p-4 sm:p-5 transition-colors relative flex flex-col sm:flex-row sm:items-start justify-between gap-4 ${
                        !item.is_read
                          ? 'bg-indigo-50/20 hover:bg-indigo-50/40 border-l-4 border-l-indigo-600'
                          : 'hover:bg-gray-50/70 border-l-4 border-l-transparent'
                      }`}
                    >
                      <div className="flex items-start space-x-3.5">
                        {/* Category Icon */}
                        <div className="mt-0.5 flex-shrink-0 p-2 bg-white rounded-lg border border-gray-200 shadow-xs">
                          {getCategoryIcon(item.category)}
                        </div>

                        {/* Content Details */}
                        <div className="space-y-1">
                          <div className="flex flex-wrap items-center gap-2">
                            {getSeverityBadge(item.severity)}
                            <h4
                              className={`text-sm ${
                                !item.is_read
                                  ? 'font-bold text-gray-900'
                                  : 'font-medium text-gray-800'
                              }`}
                            >
                              {item.title}
                            </h4>
                            <span className="text-xs text-gray-400">
                              • {formatTimeAgo(item.created_at)}
                            </span>
                            {item.is_dismissed && (
                              <Badge variant="outline" className="text-[10px] text-gray-400 border-dashed">
                                Dismissed
                              </Badge>
                            )}
                          </div>

                          <p className="text-xs sm:text-sm text-gray-600 leading-relaxed max-w-3xl">
                            {item.message}
                          </p>

                          {/* Action Links & Metadata */}
                          {item.action_url && (
                            <div className="pt-1.5">
                              <Link
                                href={item.action_url}
                                className="inline-flex items-center space-x-1 text-xs font-semibold text-indigo-600 hover:text-indigo-800 transition-colors"
                              >
                                <span>Open target page</span>
                                <ExternalLink className="h-3 w-3" />
                              </Link>
                            </div>
                          )}
                        </div>
                      </div>

                      {/* Actions Column */}
                      <div className="flex sm:flex-col items-center sm:items-end justify-between sm:justify-start gap-2 flex-shrink-0 self-end sm:self-auto border-t sm:border-t-0 pt-2 sm:pt-0 w-full sm:w-auto">
                        {item.is_dismissed ? (
                          <Button
                            variant="outline"
                            size="sm"
                            onClick={() => handleRestore(item.id)}
                            disabled={isActioning}
                            className="h-7 text-xs px-2.5 text-indigo-600 hover:text-indigo-800 hover:bg-indigo-50"
                            title="Restore notification to active list"
                          >
                            <RotateCcw className="h-3 w-3 mr-1" />
                            Restore
                          </Button>
                        ) : (
                          <>
                            {!item.is_read ? (
                              <Button
                                variant="outline"
                                size="sm"
                                onClick={() => handleMarkAsRead(item.id)}
                                disabled={isActioning}
                                className="h-7 text-xs px-2.5 text-gray-700 hover:bg-gray-100"
                                title="Mark as read"
                              >
                                <Check className="h-3 w-3 mr-1 text-gray-500" />
                                Mark read
                              </Button>
                            ) : (
                              <span className="text-[11px] text-gray-400 flex items-center">
                                <Check className="h-3 w-3 mr-0.5 text-emerald-500" />
                                Read
                              </span>
                            )}

                            <Button
                              variant="ghost"
                              size="sm"
                              onClick={() => handleDismiss(item.id)}
                              disabled={isActioning}
                              className="h-7 text-xs px-2 text-gray-400 hover:text-red-600 hover:bg-red-50"
                              title="Dismiss notification"
                            >
                              <Archive className="h-3 w-3 mr-1" />
                              Dismiss
                            </Button>
                          </>
                        )}
                      </div>
                    </div>
                  );
                })}
              </div>
            )}

            {/* Pagination Footer */}
            {meta && meta.last_page > 1 && (
              <div className="flex items-center justify-between px-6 py-3.5 bg-gray-50 border-t text-xs text-gray-500">
                <div>
                  Showing <span className="font-medium text-gray-900">{meta.from ?? 0}</span> to{' '}
                  <span className="font-medium text-gray-900">{meta.to ?? 0}</span> of{' '}
                  <span className="font-medium text-gray-900">{meta.total}</span> notifications
                </div>

                <div className="flex items-center space-x-2">
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={() => setPage((p) => Math.max(1, p - 1))}
                    disabled={page <= 1}
                    className="h-7 text-xs px-2"
                  >
                    <ChevronLeft className="h-3.5 w-3.5 mr-1" />
                    Previous
                  </Button>
                  <span className="text-xs font-medium text-gray-700 px-1">
                    Page {meta.current_page} of {meta.last_page}
                  </span>
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={() => setPage((p) => Math.min(meta.last_page, p + 1))}
                    disabled={page >= meta.last_page}
                    className="h-7 text-xs px-2"
                  >
                    Next
                    <ChevronRight className="h-3.5 w-3.5 ml-1" />
                  </Button>
                </div>
              </div>
            )}
          </Card>
        </>
      )}
    </div>
  );
}

/**
 * Category Descriptions helper
 */
const CATEGORY_DESCRIPTIONS: Record<TenantNotificationCategory, string> = {
  security_abuse: 'Critical security incident warnings, abusive sending detections, and remediation guidance.',
  billing_subscription: 'Invoice payments, renewals, subscription expirations, and plan lifecycle notices.',
  domain_dns: 'DNS verification updates and status reports. (Critical DNS drift alerts always bypass mute).',
  smtp_quota: 'Outbound daily sending quota usage warnings. (Critical 100% exhaustion alerts always bypass mute).',
  mailbox_rule: 'Mailbox forwarding destination updates and vacation out-of-office auto-reply rule changes.',
  system: 'General maintenance notices, service operational health alerts, and infrastructure advisories.',
};

/**
 * Tenant Notification Preferences Panel Component
 */
function NotificationPreferencesCard() {
  const { preferences, immutableCategories, categoryLabels, criticalNotice, isLoading, error, mutate } =
    useNotificationPreferences();
  const [formState, setFormState] = useState<Partial<NotificationPreferences>>({});
  const [isSaving, setIsSaving] = useState(false);
  const [saveMessage, setSaveMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null);

  useEffect(() => {
    if (preferences) {
      setFormState(preferences);
    }
  }, [preferences]);

  const handleToggle = (cat: TenantNotificationCategory) => {
    if (immutableCategories.includes(cat)) {
      return;
    }
    setFormState((prev) => ({
      ...prev,
      [cat]: !prev[cat],
    }));
    setSaveMessage(null);
  };

  const handleSave = async () => {
    setIsSaving(true);
    setSaveMessage(null);
    try {
      await updateNotificationPreferences(formState);
      await mutate();
      setSaveMessage({ type: 'success', text: 'Notification preferences updated successfully.' });
    } catch (err: any) {
      setSaveMessage({
        type: 'error',
        text: err?.response?.data?.message || 'Failed to update preferences. Please try again.',
      });
    } finally {
      setIsSaving(false);
    }
  };

  if (isLoading) {
    return (
      <Card className="p-12 text-center bg-white border shadow-xs">
        <Spinner className="h-8 w-8 text-indigo-600 mx-auto mb-3" />
        <p className="text-sm text-gray-500">Loading notification preferences...</p>
      </Card>
    );
  }

  if (error || !preferences) {
    return (
      <Card className="p-8 text-center bg-white border shadow-xs">
        <AlertCircle className="h-8 w-8 text-red-500 mx-auto mb-2" />
        <p className="text-sm font-semibold text-gray-900">Failed to load preferences</p>
        <p className="text-xs text-gray-500 mt-1">Unable to load your in-app notification controls.</p>
        <Button variant="outline" size="sm" onClick={() => mutate()} className="mt-4 text-xs">
          Try Again
        </Button>
      </Card>
    );
  }

  const categoryKeys: TenantNotificationCategory[] = [
    'security_abuse',
    'billing_subscription',
    'domain_dns',
    'smtp_quota',
    'mailbox_rule',
    'system',
  ];

  return (
    <div className="space-y-6">
      {/* Informational Guidance Notice */}
      <div className="p-4 bg-indigo-50 border border-indigo-200 rounded-lg flex items-start space-x-3">
        <ShieldCheck className="h-5 w-5 text-indigo-600 mt-0.5 flex-shrink-0" />
        <div className="text-xs text-indigo-900 space-y-1">
          <p className="font-semibold">Security & Compliance Safeguards</p>
          <p className="text-indigo-700 leading-relaxed">
            {criticalNotice ||
              'Critical security incidents and billing expiration alerts are mandatory and cannot be muted. Furthermore, any alert with critical severity (such as domain DNS drift or 100% outbound quota exhaustion) automatically bypasses category mute filters.'}
          </p>
        </div>
      </div>

      {saveMessage && (
        <div
          className={`p-3 rounded-lg text-xs font-medium flex items-center space-x-2 ${
            saveMessage.type === 'success'
              ? 'bg-emerald-50 text-emerald-800 border border-emerald-200'
              : 'bg-red-50 text-red-800 border border-red-200'
          }`}
        >
          {saveMessage.type === 'success' ? (
            <Check className="h-4 w-4 text-emerald-600 flex-shrink-0" />
          ) : (
            <AlertCircle className="h-4 w-4 text-red-600 flex-shrink-0" />
          )}
          <span>{saveMessage.text}</span>
        </div>
      )}

      {/* Preferences List Card */}
      <Card className="bg-white border shadow-xs divide-y divide-gray-100 overflow-hidden">
        <div className="p-5 border-b bg-gray-50/70">
          <h3 className="text-sm font-bold text-gray-900">In-App Alert Categories</h3>
          <p className="text-xs text-gray-500 mt-0.5">
            Configure which category notifications are delivered to your in-app drawer and notification center.
          </p>
        </div>

        {categoryKeys.map((cat) => {
          const isImmutable = immutableCategories.includes(cat);
          const isEnabled = isImmutable ? true : !!formState[cat];
          const label = categoryLabels[cat] || cat;
          const description = CATEGORY_DESCRIPTIONS[cat] || '';

          return (
            <div
              key={cat}
              className={`p-5 flex items-start justify-between gap-4 transition-colors ${
                isImmutable ? 'bg-gray-50/40' : 'hover:bg-gray-50/70'
              }`}
            >
              <div className="flex items-start space-x-3.5">
                <div className="p-2 bg-white rounded-lg border border-gray-200 shadow-2xs mt-0.5 flex-shrink-0">
                  {getCategoryIcon(cat)}
                </div>
                <div className="space-y-1">
                  <div className="flex items-center space-x-2">
                    <span className="text-sm font-semibold text-gray-900">{label}</span>
                    {isImmutable && (
                      <Badge variant="outline" className="text-[10px] bg-amber-50 text-amber-700 border-amber-200 font-medium">
                        <Lock className="h-2.5 w-2.5 mr-1" />
                        Mandatory
                      </Badge>
                    )}
                  </div>
                  <p className="text-xs text-gray-500 max-w-2xl leading-relaxed">{description}</p>
                </div>
              </div>

              {/* Toggle Switch */}
              <div className="flex-shrink-0 pt-1">
                <button
                  type="button"
                  disabled={isImmutable || isSaving}
                  onClick={() => handleToggle(cat)}
                  className={`relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-hidden ${
                    isImmutable
                      ? 'bg-indigo-600 opacity-60 cursor-not-allowed'
                      : isEnabled
                      ? 'bg-indigo-600'
                      : 'bg-gray-200'
                  }`}
                  role="switch"
                  aria-checked={isEnabled}
                >
                  <span
                    className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow-sm ring-0 transition duration-200 ease-in-out ${
                      isEnabled ? 'translate-x-5' : 'translate-x-0'
                    }`}
                  />
                </button>
              </div>
            </div>
          );
        })}

        {/* Footer Actions */}
        <div className="p-5 bg-gray-50 flex items-center justify-between">
          <span className="text-xs text-gray-500">
            Changes apply immediately upon saving.
          </span>
          <Button
            variant="default"
            size="sm"
            onClick={handleSave}
            disabled={isSaving}
            className="bg-indigo-600 hover:bg-indigo-700 text-xs px-5"
          >
            {isSaving ? (
              <>
                <Spinner className="h-3.5 w-3.5 mr-1.5" />
                Saving...
              </>
            ) : (
              'Save Preferences'
            )}
          </Button>
        </div>
      </Card>
    </div>
  );
}
