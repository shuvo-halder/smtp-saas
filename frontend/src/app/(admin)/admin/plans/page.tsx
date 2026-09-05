'use client';

import { useTranslations } from 'next-intl';
import useSWR from 'swr';
import api from '@/lib/api';
import { Card, CardContent } from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { Plan } from '@/types';
import { formatCurrency } from '@/lib/utils';
import { Plus, Edit, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useForm } from 'react-hook-form';

export default function AdminPlansPage() {
  const t = useTranslations('Admin.plans');
  const tTable = useTranslations('Admin.plans.table');
  const tForm = useTranslations('Admin.plans.form');
  const tCommon = useTranslations('Admin.common');

  const { data, error, isLoading, mutate } = useSWR<{ data: Plan[] }>('/api/admin/plans', (url: string) => api.get(url).then(res => res.data));

  const [isModalOpen, setIsModalOpen] = useState(false);
  const [editingPlan, setEditingPlan] = useState<Plan | null>(null);
  
  const { register, handleSubmit, reset, formState: { errors, isSubmitting } } = useForm<any>();

  const openModal = (plan?: Plan) => {
    if (plan) {
      setEditingPlan(plan);
      reset(plan);
    } else {
      setEditingPlan(null);
      reset({
        name: '',
        slug: '',
        price_monthly: 0,
        price_yearly: 0,
        max_domains: 0,
        max_mailboxes_per_domain: 0,
        storage_mb_per_mailbox: 1024,
        max_aliases_per_domain: 0,
        is_active: true,
      });
    }
    setIsModalOpen(true);
  };

  const closeModal = () => {
    setIsModalOpen(false);
    setEditingPlan(null);
  };

  const onSubmit = async (formData: any) => {
    try {
      if (editingPlan) {
        await api.put(`/api/admin/plans/${editingPlan.id}`, formData);
      } else {
        await api.post('/api/admin/plans', formData);
      }
      alert(t('save_success'));
      mutate();
      closeModal();
    } catch (err: any) {
      alert(err.response?.data?.message || tCommon('error'));
    }
  };

  const handleDelete = async (plan: Plan) => {
    if (confirm(t('delete_confirm'))) {
      try {
        await api.delete(`/api/admin/plans/${plan.id}`);
        alert(t('delete_success'));
        mutate();
      } catch (err: any) {
        alert(err.response?.data?.message || tCommon('error'));
      }
    }
  };

  if (error) return <div className="text-red-500">{tCommon('error')}</div>;

  return (
    <div className="space-y-6 max-w-6xl mx-auto">
      <div className="flex justify-between items-center">
        <h1 className="text-2xl font-bold text-gray-900">{t('title')}</h1>
        <button 
          onClick={() => openModal()}
          className="flex items-center px-4 py-2 bg-indigo-600 text-white rounded-md text-sm font-medium hover:bg-indigo-700 transition-colors"
        >
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
                  <th className="px-6 py-3">{tTable('status')}</th>
                  <th className="px-6 py-3 text-right">{tTable('actions')}</th>
                </tr>
              </thead>
              <tbody>
                {isLoading ? (
                  <tr>
                    <td colSpan={7} className="px-6 py-10 text-center">
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
                    <td className="px-6 py-4">
                      {plan.is_active ? (
                        <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                          Active
                        </span>
                      ) : (
                        <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                          Inactive
                        </span>
                      )}
                    </td>
                    <td className="px-6 py-4 text-right space-x-2">
                      <button 
                        onClick={() => openModal(plan)}
                        className="text-indigo-600 hover:text-indigo-900 p-1"
                        title={t('edit_plan')}
                      >
                        <Edit className="w-4 h-4 inline" />
                      </button>
                      <button 
                        onClick={() => handleDelete(plan)}
                        className="text-red-600 hover:text-red-900 p-1"
                        title={t('delete_plan')}
                      >
                        <Trash2 className="w-4 h-4 inline" />
                      </button>
                    </td>
                  </tr>
                ))}
                {!isLoading && (!data?.data || data.data.length === 0) && (
                  <tr>
                    <td colSpan={7} className="px-6 py-10 text-center text-gray-500">
                      No plans configured.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </CardContent>
      </Card>

      {/* Basic Modal */}
      {isModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black bg-opacity-50">
          <div className="bg-white rounded-lg shadow-xl w-full max-w-2xl max-h-[90vh] flex flex-col">
            <div className="px-6 py-4 border-b">
              <h3 className="text-lg font-medium">
                {editingPlan ? t('edit_plan') : t('add_plan')}
              </h3>
            </div>
            
            <form onSubmit={handleSubmit(onSubmit)} className="overflow-y-auto flex-1">
              <div className="px-6 py-4 space-y-4">
                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <label className="block text-sm font-medium text-gray-700">{tForm('name')}</label>
                    <input type="text" {...register('name', { required: true })} className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" />
                  </div>
                  <div>
                    <label className="block text-sm font-medium text-gray-700">{tForm('slug')}</label>
                    <input type="text" {...register('slug', { required: true })} className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" />
                  </div>
                </div>

                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <label className="block text-sm font-medium text-gray-700">{tForm('price_monthly')}</label>
                    <input type="number" step="0.01" {...register('price_monthly', { required: true, valueAsNumber: true })} className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" />
                  </div>
                  <div>
                    <label className="block text-sm font-medium text-gray-700">{tForm('price_yearly')}</label>
                    <input type="number" step="0.01" {...register('price_yearly', { required: true, valueAsNumber: true })} className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" />
                  </div>
                </div>

                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <label className="block text-sm font-medium text-gray-700">{tForm('max_domains')}</label>
                    <input type="number" {...register('max_domains', { required: true, valueAsNumber: true })} className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" />
                  </div>
                  <div>
                    <label className="block text-sm font-medium text-gray-700">{tForm('max_mailboxes')}</label>
                    <input type="number" {...register('max_mailboxes_per_domain', { required: true, valueAsNumber: true })} className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" />
                  </div>
                </div>

                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <label className="block text-sm font-medium text-gray-700">{tForm('storage_mb')}</label>
                    <input type="number" {...register('storage_mb_per_mailbox', { required: true, valueAsNumber: true })} className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" />
                  </div>
                  <div>
                    <label className="block text-sm font-medium text-gray-700">{tForm('max_aliases')}</label>
                    <input type="number" {...register('max_aliases_per_domain', { required: true, valueAsNumber: true })} className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" />
                  </div>
                </div>

                <div>
                  <label className="flex items-center space-x-2">
                    <input type="checkbox" {...register('is_active')} className="rounded border-gray-300 text-indigo-600 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    <span className="text-sm font-medium text-gray-700">{tForm('is_active')}</span>
                  </label>
                </div>
              </div>

              <div className="px-6 py-4 border-t bg-gray-50 flex justify-end space-x-3 rounded-b-lg">
                <button type="button" onClick={closeModal} className="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                  {tForm('cancel')}
                </button>
                <button type="submit" disabled={isSubmitting} className="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50">
                  {isSubmitting ? '...' : tForm('save')}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
