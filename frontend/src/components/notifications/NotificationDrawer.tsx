'use client';

import { useState } from 'react';
import Link from 'next/link';
import {
  Bell,
  X,
  Check,
  CheckCheck,
  ExternalLink,
  ShieldAlert,
  Globe,
  Gauge,
  CreditCard,
  Mail,
  Info,
  ChevronRight,
  AlertTriangle,
} from 'lucide-react';
import {
  useNotifications,
  useUnreadCount,
  markNotificationAsRead,
  markAllNotificationsAsRead,
  dismissNotification,
} from '@/hooks/useNotifications';
import { TenantNotification, TenantNotificationCategory, TenantNotificationSeverity } from '@/types';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Spinner } from '@/components/ui/spinner';

export function getCategoryIcon(category: TenantNotificationCategory) {
  switch (category) {
    case 'domain_dns':
      return <Globe className="h-4 w-4 text-blue-500" />;
    case 'security_abuse':
      return <ShieldAlert className="h-4 w-4 text-red-500" />;
    case 'smtp_quota':
      return <Gauge className="h-4 w-4 text-amber-500" />;
    case 'billing_subscription':
      return <CreditCard className="h-4 w-4 text-emerald-500" />;
    case 'mailbox_rule':
      return <Mail className="h-4 w-4 text-indigo-500" />;
    default:
      return <Info className="h-4 w-4 text-gray-500" />;
  }
}

export function getSeverityBadge(severity: TenantNotificationSeverity) {
  switch (severity) {
    case 'critical':
      return (
        <span className="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800 border border-red-200">
          Critical
        </span>
      );
    case 'warning':
      return (
        <span className="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-800 border border-amber-200">
          Warning
        </span>
      );
    case 'success':
      return (
        <span className="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-emerald-100 text-emerald-800 border border-emerald-200">
          Resolved
        </span>
      );
    case 'info':
    default:
      return (
        <span className="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-blue-50 text-blue-700 border border-blue-200">
          Info
        </span>
      );
  }
}

export function formatTimeAgo(dateString: string): string {
  try {
    const date = new Date(dateString);
    const now = new Date();
    const diffMs = now.getTime() - date.getTime();
    const diffMins = Math.floor(diffMs / (1000 * 60));
    const diffHours = Math.floor(diffMins / 60);
    const diffDays = Math.floor(diffHours / 24);

    if (diffMins < 1) return 'Just now';
    if (diffMins < 60) return `${diffMins}m ago`;
    if (diffHours < 24) return `${diffHours}h ago`;
    if (diffDays < 7) return `${diffDays}d ago`;

    return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
  } catch {
    return dateString;
  }
}

export function NotificationBellDropdown() {
  const [isOpen, setIsOpen] = useState(false);
  const [filterStatus, setFilterStatus] = useState<'all' | 'unread'>('all');
  const [isMarkingAll, setIsMarkingAll] = useState(false);

  const { unreadCount, mutate: mutateCount } = useUnreadCount();
  const { notifications, isLoading, mutate: mutateList } = useNotifications({
    status: filterStatus === 'unread' ? 'unread' : undefined,
    per_page: 8,
  });

  const handleMarkAsRead = async (id: number) => {
    try {
      await markNotificationAsRead(id);
      mutateList();
      mutateCount();
    } catch (err) {
      console.error('Failed to mark notification as read:', err);
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
    <div className="relative">
      {/* Bell Trigger Button */}
      <button
        type="button"
        onClick={() => setIsOpen(!isOpen)}
        className="relative p-1.5 text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded-full focus:outline-none transition-colors"
        title="Notifications"
        aria-label="Notifications"
      >
        <Bell className="h-5 w-5" />
        {unreadCount > 0 && (
          <span className="absolute -top-0.5 -right-0.5 flex h-4 min-w-[16px] px-1 items-center justify-center rounded-full bg-red-600 text-[10px] font-bold text-white shadow-sm ring-2 ring-white animate-pulse">
            {unreadCount > 99 ? '99+' : unreadCount}
          </span>
        )}
      </button>

      {/* Popover / Drawer Container */}
      {isOpen && (
        <>
          {/* Backdrop on mobile */}
          <div
            className="fixed inset-0 z-40 bg-black/20 sm:hidden"
            onClick={() => setIsOpen(false)}
          />

          <div className="absolute right-0 mt-2 w-80 sm:w-96 rounded-xl bg-white shadow-2xl border border-gray-200 z-50 overflow-hidden animate-in fade-in slide-in-from-top-2 duration-150">
            {/* Header */}
            <div className="flex items-center justify-between px-4 py-3 bg-gray-50 border-b">
              <div className="flex items-center space-x-2">
                <span className="font-semibold text-gray-900 text-sm">Notifications</span>
                {unreadCount > 0 && (
                  <Badge variant="destructive" className="text-[10px] px-1.5 py-0 h-4">
                    {unreadCount} unread
                  </Badge>
                )}
              </div>
              <div className="flex items-center space-x-2">
                {unreadCount > 0 && (
                  <button
                    type="button"
                    onClick={handleMarkAllRead}
                    disabled={isMarkingAll}
                    className="text-xs text-indigo-600 hover:text-indigo-800 font-medium flex items-center space-x-1 disabled:opacity-50"
                  >
                    <CheckCheck className="h-3.5 w-3.5" />
                    <span>{isMarkingAll ? 'Marking...' : 'Mark all read'}</span>
                  </button>
                )}
                <button
                  type="button"
                  onClick={() => setIsOpen(false)}
                  className="text-gray-400 hover:text-gray-600 p-0.5 rounded"
                >
                  <X className="h-4 w-4" />
                </button>
              </div>
            </div>

            {/* Quick Filter Bar */}
            <div className="flex items-center px-4 py-2 bg-white border-b text-xs space-x-2">
              <button
                type="button"
                onClick={() => setFilterStatus('all')}
                className={`px-2.5 py-1 rounded-full font-medium transition-colors ${
                  filterStatus === 'all'
                    ? 'bg-indigo-50 text-indigo-700'
                    : 'text-gray-600 hover:bg-gray-100'
                }`}
              >
                All
              </button>
              <button
                type="button"
                onClick={() => setFilterStatus('unread')}
                className={`px-2.5 py-1 rounded-full font-medium transition-colors ${
                  filterStatus === 'unread'
                    ? 'bg-indigo-50 text-indigo-700'
                    : 'text-gray-600 hover:bg-gray-100'
                }`}
              >
                Unread only
              </button>
            </div>

            {/* Notifications List */}
            <div className="max-h-96 overflow-y-auto divide-y divide-gray-100">
              {isLoading ? (
                <div className="flex items-center justify-center py-8">
                  <Spinner className="h-5 w-5 text-indigo-600" />
                </div>
              ) : notifications.length === 0 ? (
                <div className="text-center py-10 px-4 text-gray-500">
                  <Bell className="h-8 w-8 mx-auto text-gray-300 mb-2" />
                  <p className="text-xs font-medium text-gray-700">No notifications</p>
                  <p className="text-[11px] text-gray-400 mt-0.5">
                    {filterStatus === 'unread'
                      ? 'You have read all recent alerts.'
                      : 'No alerts recorded for your account.'}
                  </p>
                </div>
              ) : (
                notifications.map((item: TenantNotification) => (
                  <div
                    key={item.id}
                    className={`p-3 hover:bg-gray-50 transition-colors relative flex items-start space-x-3 ${
                      !item.is_read ? 'bg-indigo-50/30' : ''
                    }`}
                  >
                    {/* Category Icon */}
                    <div className="mt-0.5 flex-shrink-0 p-1.5 bg-gray-50 rounded-lg border border-gray-100">
                      {getCategoryIcon(item.category)}
                    </div>

                    {/* Content */}
                    <div className="flex-1 min-w-0">
                      <div className="flex items-center justify-between space-x-1 mb-0.5">
                        <div className="flex items-center space-x-1.5 truncate">
                          {getSeverityBadge(item.severity)}
                          <span
                            className={`text-xs truncate ${
                              !item.is_read ? 'font-semibold text-gray-900' : 'font-medium text-gray-700'
                            }`}
                          >
                            {item.title}
                          </span>
                        </div>
                        <span className="text-[10px] text-gray-400 whitespace-nowrap">
                          {formatTimeAgo(item.created_at)}
                        </span>
                      </div>

                      <p className="text-xs text-gray-600 line-clamp-2 leading-relaxed">
                        {item.message}
                      </p>

                      <div className="mt-1.5 flex items-center space-x-3 text-xs">
                        {item.action_url && (
                          <Link
                            href={item.action_url}
                            onClick={() => setIsOpen(false)}
                            className="inline-flex items-center space-x-1 text-indigo-600 hover:text-indigo-800 font-medium text-[11px]"
                          >
                            <span>View details</span>
                            <ExternalLink className="h-3 w-3" />
                          </Link>
                        )}

                        {!item.is_read && (
                          <button
                            type="button"
                            onClick={() => handleMarkAsRead(item.id)}
                            className="inline-flex items-center space-x-0.5 text-gray-400 hover:text-gray-700 text-[11px]"
                          >
                            <Check className="h-3 w-3" />
                            <span>Mark read</span>
                          </button>
                        )}
                      </div>
                    </div>
                  </div>
                ))
              )}
            </div>

            {/* Footer */}
            <div className="p-2.5 bg-gray-50 border-t text-center">
              <Link
                href="/notifications"
                onClick={() => setIsOpen(false)}
                className="text-xs font-medium text-indigo-600 hover:text-indigo-800 inline-flex items-center space-x-1 py-1 px-3 rounded hover:bg-gray-100 transition-colors"
              >
                <span>View all notifications</span>
                <ChevronRight className="h-3.5 w-3.5" />
              </Link>
            </div>
          </div>
        </>
      )}
    </div>
  );
}
