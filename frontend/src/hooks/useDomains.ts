import useSWR from 'swr';
import api from '@/lib/api';
import { Domain, PaginatedResponse } from '@/types';

const fetcher = (url: string) => api.get(url).then((res) => res.data);

export function useDomains(page = 1) {
  const { data, error, isLoading, mutate } = useSWR<PaginatedResponse<Domain>>(
    `/domains?page=${page}`,
    fetcher
  );

  return {
    domains: data,
    isLoading,
    error,
    mutate,
  };
}

export function useDomain(id: string | number) {
  const { data, error, isLoading, mutate } = useSWR<{ data: Domain }>(
    id ? `/domains/${id}` : null,
    fetcher
  );

  return {
    domain: data?.data,
    isLoading,
    error,
    mutate,
  };
}
