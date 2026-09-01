'use client';

import { useState } from 'react';
import { useTranslations } from 'next-intl';
import useSWR from 'swr';
import { api } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { Card, CardContent, CardHeader, CardTitle, CardDescription, CardFooter } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { Plan, Invoice, PaginatedResponse } from '@/types';
import { Check, Download, AlertCircle } from 'lucide-react';
import { formatCurrency, formatDate } from '@/lib/utils';
import { useSearchParams } from 'next/navigation';

export default function TenantBillingPage() {
  const t = useTranslations('Tenant.billing');
  const { user } = useAuth();
  const searchParams = useSearchParams();
  const paymentStatus = searchParams?.get('status');

  const [billingCycle, setBillingCycle] = useState<'monthly' | 'yearly'>('monthly');
  const [isProcessingId, setIsProcessingId] = useState<number | null>(null);
  const [checkoutError, setCheckoutError] = useState<string | null>(null);

  const fetcher = (url: string) => api.get(url).then(res => res.data);
  const { data: plansData, isLoading: loadingPlans } = useSWR<{ data: Plan[] }>('/api/billing/plans', fetcher);
  const { data: invoicesData, isLoading: loadingInvoices } = useSWR<PaginatedResponse<Invoice>>('/api/billing/invoices', fetcher);

  const handleCheckout = async (planId: number) => {
    setIsProcessingId(planId);
    setCheckoutError(null);
    try {
      const response = await api.post('/api/billing/checkout', {
        plan_id: planId,
        billing_cycle: billingCycle,
      });
      // Redirect to SSLCommerz gateway
      if (response.data.redirect_url) {
        window.location.href = response.data.redirect_url;
      }
    } catch (err: any) {
      setCheckoutError(err.response?.data?.message || 'Payment initiation failed.');
      setIsProcessingId(null);
    }
  };

  const getStatusBadge = (status: string) => {
    switch(status) {
      case 'paid': return <Badge variant="success">{t('status_paid')}</Badge>;
      case 'pending': return <Badge variant="warning">{t('status_pending')}</Badge>;
      case 'failed':
      case 'cancelled': return <Badge variant="destructive">{t('status_failed')}</Badge>;
      default: return <Badge variant="secondary">{status}</Badge>;
    }
  };

  const activePlanId = user?.plan?.id;

  return (
    <div className="space-y-10 max-w-6xl mx-auto">
      
      {/* Payment Gateway Return Status Alerts */}
      {paymentStatus === 'success' && (
        <div className="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-md shadow-sm">
           Payment successful! Your subscription is now active.
        </div>
      )}
      {paymentStatus === 'failed' && (
        <div className="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-md shadow-sm">
           Payment failed. Please try again.
        </div>
      )}
      {paymentStatus === 'cancelled' && (
        <div className="bg-yellow-50 border border-yellow-200 text-yellow-800 px-4 py-3 rounded-md shadow-sm">
           Payment was cancelled.
        </div>
      )}

      {/* Pricing Section */}
      <section>
        <div className="text-center mb-8">
          <h1 className="text-3xl font-bold text-gray-900 tracking-tight">{t('title')}</h1>
          <p className="mt-2 text-gray-500">Manage your subscription and billing history.</p>
          
          <div className="mt-6 flex justify-center">
            <div className="relative flex rounded-full bg-gray-100 p-1 shadow-sm border border-gray-200">
              <button
                onClick={() => setBillingCycle('monthly')}
                className={`relative w-32 rounded-full py-1.5 text-sm font-medium whitespace-nowrap transition-colors ${billingCycle === 'monthly' ? 'bg-white text-indigo-700 shadow-sm' : 'text-gray-500 hover:text-gray-900'}`}
              >
                {t('monthly')}
              </button>
              <button
                onClick={() => setBillingCycle('yearly')}
                className={`relative w-32 rounded-full py-1.5 text-sm font-medium whitespace-nowrap transition-colors ${billingCycle === 'yearly' ? 'bg-white text-indigo-700 shadow-sm' : 'text-gray-500 hover:text-gray-900'}`}
              >
                {t('yearly')}
              </button>
            </div>
          </div>
        </div>

        {checkoutError && (
          <div className="mb-6 bg-red-50 p-4 rounded-md border border-red-200 flex items-center text-red-700 text-sm">
            <AlertCircle className="w-4 h-4 mr-2" /> {checkoutError}
          </div>
        )}

        {loadingPlans ? (
          <div className="flex justify-center p-12"><Spinner /></div>
        ) : (
          <div className="grid gap-6 md:grid-cols-3">
            {plansData?.data.map((plan) => {
              const price = billingCycle === 'monthly' ? plan.price_monthly : plan.price_yearly;
              const isCurrentPlan = activePlanId === plan.id;
              
              return (
                <Card key={plan.id} className={`relative flex flex-col ${plan.is_featured ? 'border-indigo-500 shadow-lg scale-105' : 'border-gray-200'} transition-all`}>
                  {plan.is_featured && (
                    <div className="absolute top-0 right-0 -translate-y-1/2 translate-x-1/4">
                      <span className="inline-flex items-center gap-x-1.5 rounded-full bg-indigo-600 px-3 py-1 text-xs font-medium text-white shadow-sm">
                        Most Popular
                      </span>
                    </div>
                  )}
                  <CardHeader>
                    <CardTitle className="text-xl text-gray-900">{plan.name}</CardTitle>
                    <CardDescription>{plan.description}</CardDescription>
                  </CardHeader>
                  <CardContent className="flex-1">
                    <div className="mb-6">
                      <span className="text-4xl font-extrabold text-gray-900">{formatCurrency(price)}</span>
                      <span className="text-base font-medium text-gray-500">/{billingCycle === 'monthly' ? 'mo' : 'yr'}</span>
                    </div>
                    
                    <ul className="space-y-3 text-sm text-gray-600">
                      <li className="flex gap-x-3">
                        <Check className="h-5 w-5 text-indigo-600 flex-shrink-0" />
                        <span>{plan.max_domains === -1 ? t('unlimited') : plan.max_domains} Domains</span>
                      </li>
                      <li className="flex gap-x-3">
                        <Check className="h-5 w-5 text-indigo-600 flex-shrink-0" />
                        <span>{plan.max_mailboxes_per_domain === -1 ? t('unlimited') : plan.max_mailboxes_per_domain} Mailboxes / Domain</span>
                      </li>
                      <li className="flex gap-x-3">
                        <Check className="h-5 w-5 text-indigo-600 flex-shrink-0" />
                        <span>{plan.storage_mb_per_mailbox / 1024} GB Storage / Mailbox</span>
                      </li>
                      {plan.features?.map((feature, i) => (
                        <li key={i} className="flex gap-x-3">
                          <Check className="h-5 w-5 text-indigo-600 flex-shrink-0" />
                          <span>{feature}</span>
                        </li>
                      ))}
                    </ul>
                  </CardContent>
                  <CardFooter>
                    <Button 
                      className={`w-full ${isCurrentPlan ? 'bg-green-500 hover:bg-green-600 text-white' : 'bg-indigo-600 hover:bg-indigo-700'}`}
                      variant={isCurrentPlan ? 'default' : 'default'}
                      disabled={isCurrentPlan || isProcessingId === plan.id}
                      onClick={() => handleCheckout(plan.id)}
                    >
                      {isProcessingId === plan.id ? (
                        <><Spinner className="mr-2" /> Processing...</>
                      ) : isCurrentPlan ? (
                        <><Check className="mr-2 w-4 h-4"/> {t('current_plan')}</>
                      ) : (
                        t('upgrade_plan')
                      )}
                    </Button>
                  </CardFooter>
                </Card>
              );
            })}
          </div>
        )}
      </section>

      {/* Invoice History Section */}
      <section className="pt-8 border-t border-gray-100">
        <h2 className="text-xl font-bold text-gray-900 mb-6">{t('invoice_history')}</h2>
        
        <Card className="shadow-sm">
          <CardContent className="p-0">
            <div className="overflow-x-auto">
              <table className="w-full text-sm text-left text-gray-600">
                <thead className="bg-gray-50 text-xs uppercase text-gray-500 border-b">
                  <tr>
                    <th className="px-6 py-4">{t('invoice_number')}</th>
                    <th className="px-6 py-4">{t('amount')}</th>
                    <th className="px-6 py-4">{t('date')}</th>
                    <th className="px-6 py-4">{t('status')}</th>
                    <th className="px-6 py-4 text-right">{t('download')}</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-100">
                  {loadingInvoices ? (
                    <tr><td colSpan={5} className="p-8 text-center"><Spinner /></td></tr>
                  ) : invoicesData?.data.length === 0 ? (
                    <tr><td colSpan={5} className="p-8 text-center text-gray-500">No invoices found.</td></tr>
                  ) : (
                    invoicesData?.data.map((invoice) => (
                      <tr key={invoice.id} className="hover:bg-gray-50 bg-white">
                        <td className="px-6 py-4 font-medium text-gray-900">{invoice.invoice_number}</td>
                        <td className="px-6 py-4 font-semibold">{formatCurrency(invoice.total)}</td>
                        <td className="px-6 py-4">{formatDate(invoice.created_at)}</td>
                        <td className="px-6 py-4">{getStatusBadge(invoice.status)}</td>
                        <td className="px-6 py-4 text-right">
                          {invoice.status === 'paid' && (
                            <a href={`/api/billing/invoices/${invoice.id}/download`} target="_blank" rel="noreferrer" className="inline-flex items-center text-indigo-600 hover:text-indigo-900">
                              <Download className="w-4 h-4 mr-1" />
                              {t('download')}
                            </a>
                          )}
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          </CardContent>
        </Card>
      </section>

    </div>
  );
}
