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

export function useDomainCatchAll(domainId: string | number | null) {
  const { data, error, isLoading, mutate } = useSWR<{ data: import('@/types').DomainCatchAllDetails }>(
    domainId ? `/domains/${domainId}/catch-all` : null,
    fetcher
  );

  const updateCatchAll = async (payload: import('@/types').CatchAllUpdateRequest) => {
    const res = await api.put(`/domains/${domainId}/catch-all`, payload);
    await mutate();
    return res.data;
  };

  return {
    catchAll: data?.data,
    isLoading,
    error,
    mutate,
    updateCatchAll,
  };
}

export function useDomainDnsWizard(domainId: string | number | null) {
  const { data, error, isLoading, mutate } = useSWR<{ data: import('@/types').DomainDnsWizardData }>(
    domainId ? `/domains/${domainId}/dns-wizard` : null,
    fetcher
  );

  const verifyDns = async (): Promise<import('@/types').DnsVerifyResponse> => {
    const res = await api.post<import('@/types').DnsVerifyResponse>(`/domains/${domainId}/verify-dns`);
    await mutate();
    return res.data;
  };

  return {
    wizard: data?.data,
    isLoading,
    error,
    mutate,
    verifyDns,
  };
}


