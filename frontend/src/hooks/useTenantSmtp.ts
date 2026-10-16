import useSWR from 'swr';
import api from '@/lib/api';
import {
  SmtpSettings,
  TenantSmtpUsage,
  SmtpCredential,
  RotatePasswordResponse,
  DeliveryLogItem,
  SmtpDiagnosticsResponse,
  PaginatedResponse,
} from '@/types';

const fetcher = (url: string) => api.get(url).then((res) => res.data);

export interface ActivityLogFilters {
  page?: number;
  per_page?: number;
  status?: string;
  search?: string;
  mailbox_id?: number | string;
  date_from?: string;
  date_to?: string;
}

export function useSmtpSettings() {
  const { data, error, mutate, isLoading } = useSWR<SmtpSettings>(
    '/smtp/settings',
    fetcher
  );

  return {
    settings: data,
    isLoading,
    error,
    mutate,
  };
}

export function useSmtpUsage() {
  const { data, error, mutate, isLoading } = useSWR<TenantSmtpUsage>(
    '/smtp/usage',
    fetcher,
    { refreshInterval: 30000 } // Auto refresh every 30s
  );

  return {
    usage: data,
    isLoading,
    error,
    mutate,
  };
}

export function useSmtpCredentials() {
  const { data, error, mutate, isLoading } = useSWR<SmtpCredential[]>(
    '/smtp/credentials',
    fetcher
  );

  const rotatePassword = async (mailboxId: number): Promise<RotatePasswordResponse> => {
    const res = await api.post(`/smtp/credentials/${mailboxId}/rotate-password`);
    await mutate();
    return res.data;
  };

  return {
    credentials: data,
    isLoading,
    error,
    mutate,
    rotatePassword,
  };
}

export function useSmtpActivity(filters: ActivityLogFilters = {}) {
  const {
    page = 1,
    per_page = 15,
    status = '',
    search = '',
    mailbox_id = '',
    date_from = '',
    date_to = '',
  } = filters;

  const queryParams = new URLSearchParams();
  queryParams.set('page', String(page));
  if (per_page) queryParams.set('per_page', String(per_page));
  if (status && status !== 'all') queryParams.set('status', status);
  if (search) queryParams.set('search', search);
  if (mailbox_id) queryParams.set('mailbox_id', String(mailbox_id));
  if (date_from) queryParams.set('date_from', date_from);
  if (date_to) queryParams.set('date_to', date_to);

  const url = `/smtp/activity?${queryParams.toString()}`;

  const { data, error, mutate, isLoading } = useSWR<PaginatedResponse<DeliveryLogItem>>(
    url,
    fetcher,
    { refreshInterval: 15000 } // Auto-poll recent deliveries every 15s
  );

  return {
    logs: data,
    isLoading,
    error,
    mutate,
  };
}

export function useSmtpDiagnostics() {
  const { data, error, mutate, isLoading } = useSWR<SmtpDiagnosticsResponse>(
    '/smtp/diagnostics',
    fetcher
  );

  return {
    diagnostics: data,
    isLoading,
    error,
    runDiagnostics: mutate,
  };
}
