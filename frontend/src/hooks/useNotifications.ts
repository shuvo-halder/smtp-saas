import useSWR from 'swr';
import api from '@/lib/api';
import {
  NotificationListResponse,
  TenantNotification,
  TenantNotificationCategory,
  TenantNotificationSeverity,
  UnreadCountResponse,
  NotificationCountsResponse,
  NotificationPreferences,
  NotificationPreferencesResponse,
} from '@/types';

const fetcher = (url: string) => api.get(url).then((res) => res.data);

export interface UseNotificationsOptions {
  page?: number;
  per_page?: number;
  status?: 'unread' | 'read' | 'dismissed' | 'all';
  category?: TenantNotificationCategory | 'all';
  severity?: TenantNotificationSeverity | 'all';
}

export function useNotifications(options: UseNotificationsOptions = {}) {
  const { page = 1, per_page = 15, status, category, severity } = options;

  const queryParams = new URLSearchParams();
  queryParams.set('page', page.toString());
  queryParams.set('per_page', per_page.toString());
  if (status && status !== 'all') queryParams.set('status', status);
  if (category && category !== 'all') queryParams.set('category', category);
  if (severity && severity !== 'all') queryParams.set('severity', severity);

  const url = `/notifications?${queryParams.toString()}`;

  const { data, error, isLoading, mutate } = useSWR<NotificationListResponse>(
    url,
    fetcher,
    { keepPreviousData: true }
  );

  return {
    notifications: data?.data ?? [],
    meta: data?.meta,
    links: data?.links,
    isLoading,
    error,
    mutate,
  };
}

export function useUnreadCount() {
  const { data, error, isLoading, mutate } = useSWR<UnreadCountResponse>(
    '/notifications/unread-count',
    fetcher,
    { refreshInterval: 30000 }
  );

  return {
    unreadCount: data?.unread_count ?? 0,
    isLoading,
    error,
    mutate,
  };
}

export function useNotificationCounts() {
  const { data, error, isLoading, mutate } = useSWR<NotificationCountsResponse>(
    '/notifications/counts',
    fetcher,
    { refreshInterval: 30000 }
  );

  return {
    counts: data,
    isLoading,
    error,
    mutate,
  };
}

export function useNotificationPreferences() {
  const { data, error, isLoading, mutate } = useSWR<NotificationPreferencesResponse>(
    '/notifications/preferences',
    fetcher
  );

  return {
    preferences: data?.preferences,
    immutableCategories: data?.immutable_categories ?? [],
    categoryLabels: data?.category_labels ?? ({} as Record<TenantNotificationCategory, string>),
    criticalNotice: data?.critical_notice,
    isLoading,
    error,
    mutate,
  };
}

export async function updateNotificationPreferences(
  preferences: Partial<NotificationPreferences>
): Promise<NotificationPreferencesResponse> {
  const response = await api.put('/notifications/preferences', preferences);
  return response.data.data;
}

export async function markNotificationAsRead(id: number): Promise<TenantNotification> {
  const response = await api.post(`/notifications/${id}/read`);
  return response.data.notification;
}

export async function markAllNotificationsAsRead(): Promise<number> {
  const response = await api.post('/notifications/mark-all-read');
  return response.data.updated_count ?? 0;
}

export async function dismissNotification(id: number): Promise<void> {
  await api.post(`/notifications/${id}/dismiss`);
}

export async function restoreNotification(id: number): Promise<TenantNotification> {
  const response = await api.post(`/notifications/${id}/restore`);
  return response.data.notification;
}

