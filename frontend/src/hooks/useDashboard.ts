import useSWR from 'swr';
import api from '@/lib/api';
import { DashboardStats } from '@/types';

const fetcher = (url: string) => api.get(url).then((res) => res.data);

export function useDashboard() {
  const { data, error, isLoading } = useSWR<{ data: DashboardStats }>(
    '/dashboard',
    fetcher
  );

  return {
    stats: data?.data,
    isLoading,
    error,
  };
}
