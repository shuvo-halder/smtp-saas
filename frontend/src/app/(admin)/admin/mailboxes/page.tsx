'use client';

import { useTranslations } from 'next-intl';
import useSWR from 'swr';
import api from '@/lib/api';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Spinner } from '@/components/ui/spinner';
import { Mailbox, PaginatedResponse, Domain } from '@/types';
import { useState } from 'react';
import Link from 'next/link';

export default function AdminMailboxesPage() {
  const t = useTranslations('Admin.mailboxes');
  const tTable = useTranslations('Admin.mailboxes.table');
  const tCommon = useTranslations('Admin.common');

  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  
  const fetcher = (url: string) => api.get(url).then(res => res.data);
  const { data, error, isLoading } = useSWR<PaginatedResponse<Mailbox & { domain: Domain & { user: { id: number, name: string } } }>>(`/api/admin/mailboxes?page=${page}&search=${search}`, fetcher);

  if (error) return <div className="text-red-500">{tCommon('error')}</div>;

  return (
    <div className="space-y-6 max-w-6xl mx-auto">
      <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center space-y-4 sm:space-y-0">
        <h1 className="text-2xl font-bold text-gray-900">{t('title')}</h1>
        <input 
          type="text" 
          placeholder={t('search_placeholder')}
          className="border border-gray-300 rounded-md px-4 py-2 text-sm w-full sm:w-64"
          value={search}
          onChange={(e) => {
            setSearch(e.target.value);
            setPage(1);
          }}
        />
      </div>

      <Card>
        <CardContent className="p-0">
          <div className="overflow-x-auto">
            <table className="w-full text-sm text-left text-gray-500">
              <thead className="text-xs text-gray-700 uppercase bg-gray-50 border-b">
                <tr>
                  <th className="px-6 py-3">{tTable('email')}</th>
                  <th className="px-6 py-3">{tTable('domain')}</th>
                  <th className="px-6 py-3">{tTable('tenant')}</th>
                  <th className="px-6 py-3">{tTable('status')}</th>
                  <th className="px-6 py-3">{tTable('quota')}</th>
                  <th className="px-6 py-3">{tTable('created_at')}</th>
                </tr>
              </thead>
              <tbody>
                {isLoading ? (
                  <tr>
                    <td colSpan={6} className="px-6 py-10 text-center">
                      <div className="flex justify-center"><Spinner /></div>
                    </td>
                  </tr>
                ) : data?.data.map((mailbox) => (
                  <tr key={mailbox.id} className="bg-white border-b hover:bg-gray-50">
                    <td className="px-6 py-4 font-medium text-gray-900">{mailbox.email}</td>
                    <td className="px-6 py-4">
                      {mailbox.domain?.domain_name}
                    </td>
                    <td className="px-6 py-4">
                      {mailbox.domain?.user ? (
                        <Link href={`/admin/tenants/${mailbox.domain.user.id}`} className="text-indigo-600 hover:underline">
                          {mailbox.domain.user.name}
                        </Link>
                      ) : (
                        <span className="text-gray-400">Orphaned</span>
                      )}
                    </td>
                    <td className="px-6 py-4">
                      <Badge variant={mailbox.is_active ? 'success' : 'warning'}>
                        {mailbox.is_active ? tCommon('active') : tCommon('suspended')}
                      </Badge>
                    </td>
                    <td className="px-6 py-4">
                      {mailbox.quota_mb === 0 ? tCommon('unlimited') : `${mailbox.quota_mb} MB`}
                    </td>
                    <td className="px-6 py-4 text-gray-500">
                      {new Date(mailbox.created_at).toLocaleDateString()}
                    </td>
                  </tr>
                ))}
                {!isLoading && data?.data.length === 0 && (
                  <tr>
                    <td colSpan={6} className="px-6 py-10 text-center text-gray-500">
                      No mailboxes found.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </CardContent>
      </Card>
      
      {/* Basic Pagination Controls */}
      {data && data.meta.last_page > 1 && (
        <div className="flex justify-between items-center bg-white p-4 rounded-md shadow-sm border">
          <button 
            disabled={page === 1} 
            onClick={() => setPage(p => p - 1)}
            className="px-4 py-2 text-sm bg-gray-100 rounded disabled:opacity-50"
          >
            Previous
          </button>
          <span className="text-sm text-gray-600">
            Page {page} of {data.meta.last_page}
          </span>
          <button 
            disabled={page === data.meta.last_page} 
            onClick={() => setPage(p => p + 1)}
            className="px-4 py-2 text-sm bg-gray-100 rounded disabled:opacity-50"
          >
            Next
          </button>
        </div>
      )}
    </div>
  );
}
