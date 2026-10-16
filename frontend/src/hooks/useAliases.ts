import useSWR from 'swr';
import api from '@/lib/api';
import { EmailAlias, PaginatedResponse } from '@/types';

const fetcher = (url: string) => api.get(url).then((res) => res.data);

export interface AliasFilters {
  page?: number;
  per_page?: number;
  domain_id?: number | string;
  status?: string;
  search?: string;
}

export function useTenantAliases(filters: AliasFilters = {}) {
  const { page = 1, per_page = 15, domain_id = '', status = '', search = '' } = filters;

  const queryParams = new URLSearchParams();
  queryParams.set('page', String(page));
  if (per_page) queryParams.set('per_page', String(per_page));
  if (domain_id) queryParams.set('domain_id', String(domain_id));
  if (status) queryParams.set('status', status);
  if (search) queryParams.set('search', search);

  const url = `/aliases?${queryParams.toString()}`;

  const { data, error, mutate, isLoading } = useSWR<PaginatedResponse<EmailAlias>>(
    url,
    fetcher
  );

  return {
    aliases: data,
    isLoading,
    error,
    mutate,
  };
}

export function useDomainAliases(domainId: string | number | null, page: number = 1) {
  const { data, error, mutate, isLoading } = useSWR<PaginatedResponse<EmailAlias>>(
    domainId ? `/domains/${domainId}/aliases?page=${page}` : null,
    fetcher
  );

  return {
    aliases: data,
    isLoading,
    error,
    mutate,
  };
}

export async function createAlias(payload: {
  domain_id: number;
  local_part?: string;
  source_email?: string;
  destination_email: string;
  is_active?: boolean;
}) {
  const res = await api.post('/aliases', payload);
  return res.data;
}

export async function updateAlias(
  id: number,
  payload: {
    destination_email: string;
    is_active?: boolean;
  }
) {
  const res = await api.put(`/aliases/${id}`, payload);
  return res.data;
}

export async function toggleAlias(id: number) {
  const res = await api.post(`/aliases/${id}/toggle`);
  return res.data;
}

export async function deleteAlias(id: number) {
  const res = await api.delete(`/aliases/${id}`);
  return res.data;
}
