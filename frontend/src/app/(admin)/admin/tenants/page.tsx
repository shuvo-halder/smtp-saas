'use client';

import { useTranslations } from 'next-intl';
import useSWR from 'swr';
import api from '@/lib/api';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Spinner } from '@/components/ui/spinner';
import { User, PaginatedResponse } from '@/types';
import { useState } from 'react';

export default function AdminTenantsPage() {
  const t = useTranslations('Admin.tenants');
  const tStatus = useTranslations('Admin.tenants.status');
  const tTable = useTranslations('Admin.tenants.table');
  const tActions = useTranslations('Admin.tenants.actions');

  const [page, setPage] = useState(1);
  const fetcher = (url: string) => api.get(url).then(res => res.data);
  const { data, error, isLoading, mutate } = useSWR<PaginatedResponse<User>>(`/api/admin/users?page=${page}`, fetcher);

  const toggleStatus = async (user: User) => {
    try {
      const endpoint = user.status === 'active' 
        ? `/api/admin/users/${user.id}/suspend` 
        : `/api/admin/users/${user.id}/activate`;
      await api.post(endpoint);
      mutate();
    } catch (err) {
      console.error(err);
    }
  };

  if (error) return <div className="text-red-500">Error loading tenants</div>;

  return (
    <div className="space-y-6 max-w-6xl mx-auto">
      <h1 className="text-2xl font-bold text-gray-900">{t('title')}</h1>

      <Card>
        <CardContent className="p-0">
          <div className="overflow-x-auto">
            <table className="w-full text-sm text-left text-gray-500">
              <thead className="text-xs text-gray-700 uppercase bg-gray-50 border-b">
                <tr>
                  <th className="px-6 py-3">{tTable('name')}</th>
                  <th className="px-6 py-3">{tTable('email')}</th>
                  <th className="px-6 py-3">{tTable('domains')}</th>
                  <th className="px-6 py-3">{tTable('status')}</th>
                  <th className="px-6 py-3 text-right">{tTable('actions')}</th>
                </tr>
              </thead>
              <tbody>
                {isLoading ? (
                  <tr>
                    <td colSpan={5} className="px-6 py-10 text-center">
                      <div className="flex justify-center"><Spinner /></div>
                    </td>
                  </tr>
                ) : data?.data.map((user) => (
                  <tr key={user.id} className="bg-white border-b hover:bg-gray-50">
                    <td className="px-6 py-4 font-medium text-gray-900">{user.name}</td>
                    <td className="px-6 py-4">{user.email}</td>
                    <td className="px-6 py-4">{(user as any).domains_count ?? 0}</td>
                    <td className="px-6 py-4">
                      <Badge 
                        variant={
                          user.status === 'active' ? 'success' : 
                          user.status === 'suspended' ? 'destructive' : 'warning'
                        }
                      >
                        {tStatus(user.status)}
                      </Badge>
                    </td>
                    <td className="px-6 py-4 text-right space-x-3">
                      <a href={`/admin/tenants/${user.id}`} className="font-medium text-indigo-600 hover:underline">
                        {tActions('view')}
                      </a>
                      <button
                        onClick={() => toggleStatus(user)}
                        className={`font-medium hover:underline ${
                          user.status === 'active' ? 'text-red-600' : 'text-green-600'
                        }`}
                      >
                        {user.status === 'active' ? tActions('suspend') : tActions('activate')}
                      </button>
                    </td>
                  </tr>
                ))}
                {!isLoading && data?.data.length === 0 && (
                  <tr>
                    <td colSpan={5} className="px-6 py-10 text-center text-gray-500">
                      No tenants found
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
