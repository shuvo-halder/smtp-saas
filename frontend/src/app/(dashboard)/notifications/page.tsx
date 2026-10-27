'use client';

import { useState } from 'react';
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
} from 'lucide-react';
import {
  useNotifications,
  useUnreadCount,
  markNotificationAsRead,
  markAllNotificationsAsRead,
  dismissNotification,
} from '@/hooks/useNotifications';
import {
  getCategoryIcon,
  getSeverityBadge,
  formatTimeAgo,
} from '@/components/notifications/NotificationDrawer';
import { TenantNotification, TenantNotificationCategory, TenantNotificationSeverity } from '@/types';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Spinner } from '@/components/ui/spinner';
import { Card } from '@/components/ui/card';

export default function NotificationsPage() {
  const [page, setPage] = useState(1);
  const [status, setStatus] = useState<'all' | 'unread' | 'read'>('all');
  const [category, setCategory] = useState<string>('all');
  const [severity, setSeverity] = useState<string>('all');
  const [isMarkingAll, setIsMarkingAll] = useState(false);
  const [actionInProgress, setActionInProgress] = useState<number | null>(null);

  const { unreadCount, mutate: mutateCount } = useUnreadCount();
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
    } catch (err) {
      console.error('Failed to dismiss notification:', err);
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
            Real-time security alerts, DNS drift warnings, SMTP quota usage, and service updates.
          </p>
        </div>

        <div className="flex items-center space-x-3">
          <Button
            variant="outline"
            size="sm"
            onClick={() => {
              mutateList();
              mutateCount();
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
              {isMarkingAll ? 'Marking...' : 'Mark all as read'}
            </Button>
          )}
        </div>
      </div>

      {/* Filter and Search Bar */}
      <Card className="p-4 bg-white border shadow-sm">
        <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
          {/* Status Tabs */}
          <div className="flex items-center space-x-1 p-1 bg-gray-100 rounded-lg self-start">
            <button
              type="button"
              onClick={() => {
                setStatus('all');
                setPage(1);
              }}
              className={`px-3 py-1.5 rounded-md text-xs font-medium transition-colors ${
                status === 'all'
                  ? 'bg-white text-gray-900 shadow-sm'
                  : 'text-gray-600 hover:text-gray-900'
              }`}
            >
              All Notifications
            </button>
            <button
              type="button"
              onClick={() => {
                setStatus('unread');
                setPage(1);
              }}
              className={`px-3 py-1.5 rounded-md text-xs font-medium transition-colors flex items-center space-x-1.5 ${
                status === 'unread'
                  ? 'bg-white text-indigo-700 shadow-sm'
                  : 'text-gray-600 hover:text-gray-900'
              }`}
            >
              <span>Unread</span>
              {unreadCount > 0 && (
                <span className="h-2 w-2 rounded-full bg-red-600 animate-pulse" />
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
                  ? 'bg-white text-gray-900 shadow-sm'
                  : 'text-gray-600 hover:text-gray-900'
              }`}
            >
              Read
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
      <Card className="bg-white border shadow-sm overflow-hidden">
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
    </div>
  );
}
