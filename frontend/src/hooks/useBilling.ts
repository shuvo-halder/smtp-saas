import useSWR from 'swr';
import api from '@/lib/api';
import { Plan, Invoice, PaginatedResponse, TenantSubscriptionSummary } from '@/types';

export function useSubscription() {
  const { data, error, mutate, isLoading } = useSWR<TenantSubscriptionSummary>(
    '/api/billing/subscription',
    (url: string) => api.get(url).then((res) => res.data)
  );

  return {
    subscription: data,
    isLoading,
    error,
    mutate,
  };
}

export function usePlans() {
  const { data, error, mutate, isLoading } = useSWR<{ data: Plan[] } | Plan[]>(
    '/api/billing/plans',
    (url: string) => api.get(url).then((res) => res.data)
  );

  const plans: Plan[] = Array.isArray(data) ? data : data?.data || [];

  return {
    plans,
    isLoading,
    error,
    mutate,
  };
}

export function useInvoices(page: number = 1, perPage: number = 10, status?: string) {
  const queryParams = new URLSearchParams({
    page: page.toString(),
    per_page: perPage.toString(),
  });

  if (status && status !== 'all') {
    queryParams.append('status', status);
  }

  const { data, error, mutate, isLoading } = useSWR<PaginatedResponse<Invoice>>(
    `/api/billing/invoices?${queryParams.toString()}`,
    (url: string) => api.get(url).then((res) => res.data)
  );

  return {
    invoices: data,
    isLoading,
    error,
    mutate,
  };
}

export function useInvoice(id: number | null) {
  const { data, error, mutate, isLoading } = useSWR<{ data: Invoice } | null>(
    id ? `/api/billing/invoices/${id}` : null,
    (url: string) => api.get(url).then((res) => res.data)
  );

  return {
    invoice: data?.data || (data as unknown as Invoice) || null,
    isLoading,
    error,
    mutate,
  };
}

export async function initiateCheckout(planId: number | string, billingCycle: 'monthly' | 'yearly') {
  const response = await api.post('/api/billing/checkout', {
    plan_id: planId,
    billing_cycle: billingCycle,
  });

  return response.data;
}
