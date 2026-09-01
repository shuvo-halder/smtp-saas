import useSWR from 'swr';
import api from '@/lib/api';

export function usePlans() {
  const { data, error, mutate, isLoading } = useSWR('/api/billing/plans', (url) =>
    api.get(url).then((res) => res.data)
  );

  return {
    plans: data?.data || data,
    isLoading,
    error,
    mutate,
  };
}

export function useInvoices(page: number = 1) {
  const { data, error, mutate, isLoading } = useSWR(`/api/billing/invoices?page=${page}`, (url) =>
    api.get(url).then((res) => res.data)
  );

  return {
    invoices: data,
    isLoading,
    error,
    mutate,
  };
}
