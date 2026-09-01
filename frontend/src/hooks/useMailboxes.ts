import useSWR from 'swr';
import api from '@/lib/api';

export function useMailboxes(domainId: string | number, page: number = 1) {
  const { data, error, mutate, isLoading } = useSWR(
    domainId ? `/api/domains/${domainId}/mailboxes?page=${page}` : null,
    (url) => api.get(url).then((res) => res.data)
  );

  return {
    mailboxes: data,
    isLoading,
    error,
    mutate,
  };
}
