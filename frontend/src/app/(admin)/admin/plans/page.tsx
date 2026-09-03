'use client';

import { useTranslations } from 'next-intl';
import useSWR from 'swr';
import { api } from '@/lib/api';
import { Card, CardContent } from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { Plan } from '@/types';
import { formatCurrency } from '@/lib/utils';
import { Plus } from 'lucide-react';
import { useState } from 'react';

export default function AdminPlansPage() {
  const t = useTranslations('Admin.plans');
  const tTable = useTranslations('Admin.plans.table');
  const tCommon = useTranslations('Admin.common');

  const fetcher = (url: string) => api.get(url).then(res => res.data);
  const { data, error, isLoading } = useSWR<{ data: Plan[] }>('/api/admin/plans', fetcher);

  if (error) return <div className="text-red-500">{tCommon('error')}</div>;

  return (
    <div className="space-y-6 max-w-6xl mx-auto">
      <div className="flex justify-between items-center">
        <h1 className="text-2xl font-bold text-gray-900">{t('title')}</h1>
        <button className="flex items-center px-4 py-2 bg-indigo-600 text-white rounded-md text-sm font-medium hover:bg-indigo-700 transition-colors">
          <Plus className="w-4 h-4 mr-2" />
          {t('add_plan')}
        </button>
      </div>

      <Card>
        <CardContent className="p-0">
          <div className="overflow-x-auto">
            <table className="w-full text-sm text-left text-gray-500">
              <thead className="text-xs text-gray-700 uppercase bg-gray-50 border-b">
                <tr>
                  <th className="px-6 py-3">{tTable('name')}</th>
                  <th className="px-6 py-3">{tTable('price_monthly')}</th>
                  <th className="px-6 py-3">{tTable('price_yearly')}</th>
                  <th className="px-6 py-3">{tTable('max_domains')}</th>
                  <th className="px-6 py-3">{tTable('max_mailboxes')}</th>
                </tr>
              </thead>
              <tbody>
                {isLoading ? (
                  <tr>
                    <td colSpan={5} className="px-6 py-10 text-center">
                      <div className="flex justify-center"><Spinner /></div>
                    </td>
                  </tr>
                ) : data?.data.map((plan) => (
                  <tr key={plan.id} className="bg-white border-b hover:bg-gray-50">
                    <td className="px-6 py-4 font-medium text-gray-900">{plan.name}</td>
                    <td className="px-6 py-4">{formatCurrency(plan.price_monthly)}</td>
                    <td className="px-6 py-4">{formatCurrency(plan.price_yearly)}</td>
                    <td className="px-6 py-4">
                      {plan.max_domains === 0 ? tCommon('unlimited') : plan.max_domains}
                    </td>
                    <td className="px-6 py-4">
                      {plan.max_mailboxes_per_domain === 0 ? tCommon('unlimited') : plan.max_mailboxes_per_domain}
                    </td>
                  </tr>
                ))}
                {!isLoading && (!data?.data || data.data.length === 0) && (
                  <tr>
                    <td colSpan={5} className="px-6 py-10 text-center text-gray-500">
                      No plans configured.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </CardContent>
      </Card>
      
    </div>
  );
}
