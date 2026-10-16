import useSWR from 'swr';
import api from '@/lib/api';
import { Mailbox, PaginatedResponse } from '@/types';

const fetcher = (url: string) => api.get(url).then((res) => res.data);

export interface MailboxFilters {
  page?: number;
  per_page?: number;
  domain_id?: number | string;
  status?: string;
  search?: string;
}

export function useMailboxes(domainId: string | number, page: number = 1) {
  const { data, error, mutate, isLoading } = useSWR<PaginatedResponse<Mailbox>>(
    domainId ? `/domains/${domainId}/mailboxes?page=${page}` : null,
    fetcher
  );

  return {
    mailboxes: data,
    isLoading,
    error,
    mutate,
  };
}

export function useTenantMailboxes(filters: MailboxFilters = {}) {
  const { page = 1, per_page = 15, domain_id = '', status = '', search = '' } = filters;

  const queryParams = new URLSearchParams();
  queryParams.set('page', String(page));
  if (per_page) queryParams.set('per_page', String(per_page));
  if (domain_id) queryParams.set('domain_id', String(domain_id));
  if (status) queryParams.set('status', status);
  if (search) queryParams.set('search', search);

  const url = `/mailboxes?${queryParams.toString()}`;

  const { data, error, mutate, isLoading } = useSWR<PaginatedResponse<Mailbox>>(
    url,
    fetcher
  );

  return {
    mailboxes: data,
    isLoading,
    error,
    mutate,
  };
}

export function useMailboxStorageOverview(filters: MailboxFilters = {}) {
  const { page = 1, per_page = 15, domain_id = '', status = '', search = '' } = filters;

  const queryParams = new URLSearchParams();
  queryParams.set('page', String(page));
  if (per_page) queryParams.set('per_page', String(per_page));
  if (domain_id) queryParams.set('domain_id', String(domain_id));
  if (status) queryParams.set('status', status);
  if (search) queryParams.set('search', search);

  const url = `/mailboxes/storage?${queryParams.toString()}`;

  const { data, error, mutate, isLoading } = useSWR<import('@/types').TenantStorageOverviewResponse>(
    url,
    fetcher
  );

  return {
    storageOverview: data,
    isLoading,
    error,
    mutate,
  };
}

export function useMailboxStorage(mailboxId?: number | string) {
  const { data, error, mutate, isLoading } = useSWR<{ data: import('@/types').MailboxStorageDetails }>(
    mailboxId ? `/mailboxes/${mailboxId}/storage` : null,
    fetcher
  );

  return {
    storage: data?.data,
    isLoading,
    error,
    mutate,
  };
}

