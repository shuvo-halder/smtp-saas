import useSWR from 'swr';
import api from '@/lib/api';
import { DashboardStats, PaginatedResponse, TenantAbuseIncident } from '@/types';

const fetcher = (url: string) => api.get(url).then((res) => res.data);

export function useDashboard() {
  const { data, error, isLoading, mutate } = useSWR<any>(
    '/dashboard',
    fetcher
  );

  const stats: DashboardStats | undefined = data?.data ?? data;

  return {
    stats,
    isLoading,
    error,
    mutate,
  };
}

export function useSecurityIncidents(
  page = 1, 
  status?: string, 
  severity?: string, 
  search?: string
) {
  const queryParams = new URLSearchParams();
  queryParams.set('page', page.toString());
  if (status && status !== 'all') queryParams.set('status', status);
  if (severity && severity !== 'all') queryParams.set('severity', severity);
  if (search && search.trim()) queryParams.set('search', search.trim());

  const url = `/security/incidents?${queryParams.toString()}`;

  const { data, error, isLoading, mutate } = useSWR<PaginatedResponse<TenantAbuseIncident>>(
    url,
    fetcher
  );

  return {
    incidents: data,
    isLoading,
    error,
    mutate,
  };
}
