'use client';

import { useTranslations } from 'next-intl';
import useSWR from 'swr';
import api from '@/lib/api';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Spinner } from '@/components/ui/spinner';
import { User, Domain, Mailbox, Invoice } from '@/types';
import Link from 'next/link';
import { formatCurrency } from '@/lib/utils';
import { ArrowLeft } from 'lucide-react';

export default function TenantDetailsPage({ params }: { params: { id: string } }) {
  const t = useTranslations('Admin.tenants.details');
  const tStatus = useTranslations('Admin.tenants.status');
  const tActions = useTranslations('Admin.tenants.actions');

  const fetcher = (url: string) => api.get(url).then(res => res.data);
  const { data: user, error, isLoading } = useSWR<User & { 
    domains: (Domain & { mailboxes: Mailbox[] })[], 
    invoices: Invoice[],
    domains_count: number,
    mailboxes_count: number 
  }>(`/api/admin/users/${params.id}`, fetcher);

  if (isLoading) return <div className="flex h-64 items-center justify-center"><Spinner /></div>;
  if (error || !user) return <div className="text-red-500">Error loading tenant details</div>;

  return (
    <div className="space-y-6 max-w-6xl mx-auto">
      <div className="flex items-center space-x-4">
        <Link href="/admin/tenants" className="text-gray-500 hover:text-gray-900">
          <ArrowLeft className="w-5 h-5" />
        </Link>
        <h1 className="text-2xl font-bold text-gray-900">{user.name}</h1>
        <Badge 
          variant={
            user.status === 'active' ? 'success' : 
            user.status === 'suspended' ? 'destructive' : 'warning'
          }
        >
          {tStatus(user.status)}
        </Badge>
      </div>

      <div className="grid gap-6 md:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>{t('contact_info')}</CardTitle>
          </CardHeader>
          <CardContent className="space-y-2 text-sm">
            <div className="flex justify-between">
              <span className="text-gray-500">Email:</span>
              <span className="font-medium text-gray-900">{user.email}</span>
            </div>
            <div className="flex justify-between">
              <span className="text-gray-500">{t('company')}:</span>
              <span className="font-medium text-gray-900">{user.company_name || 'N/A'}</span>
            </div>
            <div className="flex justify-between">
              <span className="text-gray-500">{t('phone')}:</span>
              <span className="font-medium text-gray-900">{user.phone || 'N/A'}</span>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>{t('subscription')}</CardTitle>
          </CardHeader>
          <CardContent className="space-y-2 text-sm">
            <div className="flex justify-between">
              <span className="text-gray-500">Plan:</span>
              <span className="font-medium text-gray-900">{user.plan?.name || 'Free Tier'}</span>
            </div>
            <div className="flex justify-between">
              <span className="text-gray-500">{t('plan_expires')}:</span>
              <span className="font-medium text-gray-900">{user.plan_expires_at ? new Date(user.plan_expires_at).toLocaleDateString() : 'N/A'}</span>
            </div>
            <div className="flex justify-between">
              <span className="text-gray-500">Usage:</span>
              <span className="font-medium text-gray-900">{user.domains_count} Domains / {user.mailboxes_count} Mailboxes</span>
            </div>
          </CardContent>
        </Card>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>{t('domains_list')}</CardTitle>
        </CardHeader>
        <CardContent className="p-0">
          <div className="overflow-x-auto">
            <table className="w-full text-sm text-left text-gray-500">
              <thead className="text-xs text-gray-700 uppercase bg-gray-50 border-b">
                <tr>
                  <th className="px-6 py-3">Domain</th>
                  <th className="px-6 py-3">Mailboxes</th>
                  <th className="px-6 py-3">Status</th>
                  <th className="px-6 py-3">Created</th>
                </tr>
              </thead>
              <tbody>
                {user.domains?.map((domain) => (
                  <tr key={domain.id} className="bg-white border-b hover:bg-gray-50">
                    <td className="px-6 py-4 font-medium text-gray-900">{domain.domain_name}</td>
                    <td className="px-6 py-4">{domain.mailboxes?.length || 0}</td>
                    <td className="px-6 py-4">
                      <Badge variant={domain.status === 'active' ? 'success' : 'warning'}>{domain.status}</Badge>
                    </td>
                    <td className="px-6 py-4">{new Date(domain.created_at).toLocaleDateString()}</td>
                  </tr>
                ))}
                {(!user.domains || user.domains.length === 0) && (
                  <tr>
                    <td colSpan={4} className="px-6 py-10 text-center text-gray-500">
                      No domains connected
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>{t('invoices_list')}</CardTitle>
        </CardHeader>
        <CardContent className="p-0">
          <div className="overflow-x-auto">
            <table className="w-full text-sm text-left text-gray-500">
              <thead className="text-xs text-gray-700 uppercase bg-gray-50 border-b">
                <tr>
                  <th className="px-6 py-3">Invoice #</th>
                  <th className="px-6 py-3">Amount</th>
                  <th className="px-6 py-3">Status</th>
                  <th className="px-6 py-3">Date</th>
                </tr>
              </thead>
              <tbody>
                {user.invoices?.map((invoice) => (
                  <tr key={invoice.id} className="bg-white border-b hover:bg-gray-50">
                    <td className="px-6 py-4 font-medium text-gray-900">{invoice.invoice_number || invoice.id}</td>
                    <td className="px-6 py-4">{formatCurrency(invoice.total)}</td>
                    <td className="px-6 py-4">
                      <Badge variant={invoice.status === 'paid' ? 'success' : invoice.status === 'pending' ? 'warning' : 'destructive'}>
                        {invoice.status}
                      </Badge>
                    </td>
                    <td className="px-6 py-4">{new Date(invoice.created_at).toLocaleDateString()}</td>
                  </tr>
                ))}
                {(!user.invoices || user.invoices.length === 0) && (
                  <tr>
                    <td colSpan={4} className="px-6 py-10 text-center text-gray-500">
                      No invoices found
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
