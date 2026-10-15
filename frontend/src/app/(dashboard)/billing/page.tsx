'use client';

import React, { useState } from 'react';
import Link from 'next/link';
import { useSearchParams } from 'next/navigation';
import { useSubscription, useInvoices, initiateCheckout } from '@/hooks/useBilling';
import InvoiceTable from '@/components/billing/InvoiceTable';
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { formatCurrency, formatDate } from '@/lib/utils';
import {
  CreditCard,
  Calendar,
  AlertTriangle,
  CheckCircle2,
  XCircle,
  Clock,
  Globe,
  Mail,
  Send,
  HardDrive,
  RefreshCw,
  ArrowRight,
  ShieldAlert,
} from 'lucide-react';

export default function BillingPage() {
  const searchParams = useSearchParams();
  const paymentStatus = searchParams?.get('status');

  const { subscription: subData, isLoading: isSubLoading, mutate: mutateSub } = useSubscription();

  const [page, setPage] = useState(1);
  const [statusFilter, setStatusFilter] = useState('all');
  const [isRenewing, setIsRenewing] = useState(false);
  const [renewError, setRenewError] = useState<string | null>(null);

  const { invoices, isLoading: isInvoicesLoading } = useInvoices(page, 10, statusFilter);

  const handleRenew = async () => {
    if (!subData?.plan?.id) return;
    setIsRenewing(true);
    setRenewError(null);
    try {
      const result = await initiateCheckout(subData.plan.id, 'monthly');
      if (result.redirect_url) {
        window.location.href = result.redirect_url;
      }
    } catch (err: any) {
      setRenewError(err.response?.data?.message || 'Failed to initiate renewal. Please try again.');
      setIsRenewing(false);
    }
  };

  const getStatusBadge = (status: string, isExpiringSoon: boolean) => {
    if (status === 'active' && isExpiringSoon) {
      return (
        <Badge variant="warning" className="gap-1 inline-flex items-center text-xs">
          <Clock className="w-3 h-3" /> Expiring Soon
        </Badge>
      );
    }
    switch (status) {
      case 'active':
        return (
          <Badge variant="success" className="gap-1 inline-flex items-center text-xs">
            <CheckCircle2 className="w-3 h-3" /> Active
          </Badge>
        );
      case 'expired':
        return (
          <Badge variant="destructive" className="gap-1 inline-flex items-center text-xs">
            <AlertTriangle className="w-3 h-3" /> Expired
          </Badge>
        );
      case 'suspended':
        return (
          <Badge variant="destructive" className="gap-1 inline-flex items-center text-xs">
            <ShieldAlert className="w-3 h-3" /> Suspended
          </Badge>
        );
      default:
        return (
          <Badge variant="secondary" className="gap-1 inline-flex items-center text-xs">
            Inactive
          </Badge>
        );
    }
  };

  const sub = subData?.subscription;
  const plan = subData?.plan;
  const usage = subData?.usage;

  return (
    <div className="max-w-6xl mx-auto p-4 md:p-8 space-y-8">
      {/* Page Header */}
      <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
          <h1 className="text-3xl font-extrabold text-gray-900 dark:text-white tracking-tight">
            Billing & Subscription
          </h1>
          <p className="text-sm text-gray-500 dark:text-gray-400 mt-1">
            Authoritative plan details, resource quota consumption, and invoice ledger.
          </p>
        </div>
        <div className="flex items-center gap-3">
          <Link href="/billing/plans">
            <Button variant="outline" className="gap-1.5">
              <CreditCard className="w-4 h-4" /> Compare Plans
            </Button>
          </Link>
        </div>
      </div>

      {/* Gateway Return Feedback Banners */}
      {paymentStatus === 'success' && (
        <div className="flex items-center gap-3 bg-green-50 dark:bg-green-950/40 border border-green-200 dark:border-green-800 text-green-900 dark:text-green-200 px-5 py-4 rounded-xl shadow-sm">
          <CheckCircle2 className="w-5 h-5 text-green-600 dark:text-green-400 flex-shrink-0" />
          <div>
            <p className="font-semibold text-sm">Payment Successful!</p>
            <p className="text-xs text-green-700 dark:text-green-300">
              Your payment has been processed and your subscription has been refreshed.
            </p>
          </div>
        </div>
      )}
      {paymentStatus === 'failed' && (
        <div className="flex items-center gap-3 bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-800 text-red-900 dark:text-red-200 px-5 py-4 rounded-xl shadow-sm">
          <XCircle className="w-5 h-5 text-red-600 dark:text-red-400 flex-shrink-0" />
          <div>
            <p className="font-semibold text-sm">Payment Transaction Failed</p>
            <p className="text-xs text-red-700 dark:text-red-300">
              The payment gateway reported a failure. Please try another card or payment method.
            </p>
          </div>
        </div>
      )}
      {paymentStatus === 'cancelled' && (
        <div className="flex items-center gap-3 bg-yellow-50 dark:bg-yellow-950/40 border border-yellow-200 dark:border-yellow-800 text-yellow-900 dark:text-yellow-200 px-5 py-4 rounded-xl shadow-sm">
          <AlertTriangle className="w-5 h-5 text-yellow-600 dark:text-yellow-400 flex-shrink-0" />
          <div>
            <p className="font-semibold text-sm">Payment Cancelled</p>
            <p className="text-xs text-yellow-700 dark:text-yellow-300">
              Checkout was cancelled before completion. No charges were made to your account.
            </p>
          </div>
        </div>
      )}

      {/* Subscription Warnings */}
      {sub?.status === 'expired' && (
        <div className="flex items-start gap-3 bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-800 text-red-900 dark:text-red-200 px-5 py-4 rounded-xl">
          <AlertTriangle className="w-5 h-5 text-red-600 dark:text-red-400 mt-0.5 flex-shrink-0" />
          <div className="flex-1">
            <h4 className="font-bold text-sm">Subscription Expired</h4>
            <p className="text-xs text-red-700 dark:text-red-300 mt-0.5">
              Your subscription has expired. Mailbox provisioning and outbound SMTP deliveries are restricted. Renew immediately to restore full service.
            </p>
          </div>
          {sub?.can_renew && (
            <Button
              size="sm"
              variant="destructive"
              onClick={handleRenew}
              disabled={isRenewing}
              className="ml-auto text-xs whitespace-nowrap"
            >
              {isRenewing ? 'Processing...' : 'Renew Now'}
            </Button>
          )}
        </div>
      )}

      {sub?.is_expiring_soon && sub.status === 'active' && (
        <div className="flex items-start gap-3 bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-800 text-amber-900 dark:text-amber-200 px-5 py-4 rounded-xl">
          <Clock className="w-5 h-5 text-amber-600 dark:text-amber-400 mt-0.5 flex-shrink-0" />
          <div className="flex-1">
            <h4 className="font-bold text-sm">Subscription Expiring Soon</h4>
            <p className="text-xs text-amber-700 dark:text-amber-300 mt-0.5">
              You have {sub.days_remaining} {sub.days_remaining === 1 ? 'day' : 'days'} remaining on your active subscription period. Renew now to prevent disruption.
            </p>
          </div>
          <Button
            size="sm"
            onClick={handleRenew}
            disabled={isRenewing}
            className="ml-auto text-xs bg-amber-600 hover:bg-amber-700 text-white whitespace-nowrap"
          >
            {isRenewing ? 'Processing...' : 'Renew Subscription'}
          </Button>
        </div>
      )}

      {renewError && (
        <div className="text-xs text-red-600 bg-red-50 p-3 rounded-lg border border-red-200">
          {renewError}
        </div>
      )}

      {/* Subscription Posture Overview */}
      <Card className="overflow-hidden border border-gray-200 dark:border-gray-800 shadow-sm">
        <CardHeader className="bg-gray-50/60 dark:bg-gray-900/40 border-b border-gray-100 dark:border-gray-800 pb-4">
          <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-2">
            <div>
              <CardTitle className="text-lg font-bold text-gray-900 dark:text-white">
                Subscription Posture
              </CardTitle>
              <CardDescription className="text-xs text-gray-500">
                Authoritative server-side plan tier and lifecycle state.
              </CardDescription>
            </div>
            {sub && getStatusBadge(sub.status, sub.is_expiring_soon)}
          </div>
        </CardHeader>
        <CardContent className="p-6">
          {isSubLoading ? (
            <div className="py-8 text-center text-gray-500">
              <div className="inline-block animate-spin rounded-full h-6 w-6 border-b-2 border-indigo-600 mb-2"></div>
              <p className="text-sm">Fetching subscription state...</p>
            </div>
          ) : plan ? (
            <div className="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-6">
              <div className="space-y-2">
                <div className="flex items-center gap-3">
                  <h3 className="text-2xl font-black text-gray-900 dark:text-white">
                    {plan.name}
                  </h3>
                  <span className="text-sm font-semibold text-indigo-600 dark:text-indigo-400">
                    {formatCurrency(plan.price_monthly)} / month
                  </span>
                </div>
                <p className="text-xs text-gray-500 dark:text-gray-400 max-w-xl">
                  {plan.description || 'Standard business email package with multi-domain support and anti-abuse safeguards.'}
                </p>
                <div className="flex flex-wrap items-center gap-4 pt-2 text-xs text-gray-600 dark:text-gray-300">
                  <div className="flex items-center gap-1.5">
                    <Calendar className="w-3.5 h-3.5 text-gray-400" />
                    <span>
                      Expires:{' '}
                      <strong className="text-gray-900 dark:text-white">
                        {sub?.expires_at ? formatDate(sub.expires_at) : 'Never'}
                      </strong>
                    </span>
                  </div>
                  {sub?.days_remaining !== null && (
                    <div className="flex items-center gap-1.5">
                      <Clock className="w-3.5 h-3.5 text-gray-400" />
                      <span>
                        Remaining:{' '}
                        <strong className="text-gray-900 dark:text-white">
                          {sub?.days_remaining} {sub?.days_remaining === 1 ? 'day' : 'days'}
                        </strong>
                      </span>
                    </div>
                  )}
                </div>
              </div>

              <div className="flex flex-wrap sm:flex-nowrap items-center gap-3 w-full lg:w-auto">
                <Button
                  onClick={handleRenew}
                  disabled={isRenewing}
                  className="w-full sm:w-auto gap-2 text-sm bg-indigo-600 hover:bg-indigo-700 text-white"
                >
                  <RefreshCw className={`w-4 h-4 ${isRenewing ? 'animate-spin' : ''}`} />
                  {isRenewing ? 'Initiating...' : 'Renew Subscription'}
                </Button>
                <Link href="/billing/plans" className="w-full sm:w-auto">
                  <Button variant="outline" className="w-full sm:w-auto gap-1.5 text-sm">
                    Change Plan <ArrowRight className="w-4 h-4" />
                  </Button>
                </Link>
              </div>
            </div>
          ) : (
            <div className="text-center py-8">
              <h3 className="text-lg font-bold text-gray-900 dark:text-white">No Active Plan</h3>
              <p className="text-xs text-gray-500 dark:text-gray-400 mt-1 mb-4">
                You have not selected a subscription plan yet. Choose a plan to configure domains and create mailboxes.
              </p>
              <Link href="/billing/plans">
                <Button className="bg-indigo-600 hover:bg-indigo-700 text-white">
                  Choose a Plan
                </Button>
              </Link>
            </div>
          )}
        </CardContent>
      </Card>

      {/* Quotas & Usage Breakdown */}
      {usage && (
        <div>
          <h2 className="text-lg font-bold text-gray-900 dark:text-white mb-4">
            Resource Posture & Usage
          </h2>
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            {/* Domains Usage */}
            <Card className="border border-gray-200 dark:border-gray-800 shadow-sm">
              <CardContent className="p-5 space-y-3">
                <div className="flex items-center justify-between text-gray-500 dark:text-gray-400">
                  <span className="text-xs font-semibold uppercase tracking-wider">Domains</span>
                  <Globe className="w-4 h-4 text-indigo-500" />
                </div>
                <div>
                  <div className="flex items-baseline gap-1">
                    <span className="text-2xl font-black text-gray-900 dark:text-white">
                      {usage.domains_used}
                    </span>
                    <span className="text-xs text-gray-400">
                      / {usage.domains_limit === -1 ? '∞' : usage.domains_limit}
                    </span>
                  </div>
                  <p className="text-xs text-gray-500 mt-1">Configured custom domains</p>
                </div>
                {usage.domains_limit !== -1 && (
                  <div className="w-full bg-gray-100 dark:bg-gray-800 rounded-full h-1.5 overflow-hidden">
                    <div
                      className="bg-indigo-600 h-1.5 rounded-full"
                      style={{
                        width: `${Math.min(
                          Math.round((usage.domains_used / usage.domains_limit) * 100),
                          100
                        )}%`,
                      }}
                    />
                  </div>
                )}
              </CardContent>
            </Card>

            {/* Mailboxes Usage */}
            <Card className="border border-gray-200 dark:border-gray-800 shadow-sm">
              <CardContent className="p-5 space-y-3">
                <div className="flex items-center justify-between text-gray-500 dark:text-gray-400">
                  <span className="text-xs font-semibold uppercase tracking-wider">Mailboxes</span>
                  <Mail className="w-4 h-4 text-emerald-500" />
                </div>
                <div>
                  <div className="flex items-baseline gap-1">
                    <span className="text-2xl font-black text-gray-900 dark:text-white">
                      {usage.mailboxes_used}
                    </span>
                    <span className="text-xs text-gray-400">
                      / {usage.mailboxes_limit === -1 ? '∞' : usage.mailboxes_limit}
                    </span>
                  </div>
                  <p className="text-xs text-gray-500 mt-1">
                    Max {usage.max_mailboxes_per_domain === -1 ? 'unlimited' : usage.max_mailboxes_per_domain} / domain
                  </p>
                </div>
                {usage.mailboxes_limit !== -1 && (
                  <div className="w-full bg-gray-100 dark:bg-gray-800 rounded-full h-1.5 overflow-hidden">
                    <div
                      className="bg-emerald-500 h-1.5 rounded-full"
                      style={{
                        width: `${Math.min(
                          Math.round((usage.mailboxes_used / usage.mailboxes_limit) * 100),
                          100
                        )}%`,
                      }}
                    />
                  </div>
                )}
              </CardContent>
            </Card>

            {/* Outbound Quota Today */}
            <Card className="border border-gray-200 dark:border-gray-800 shadow-sm">
              <CardContent className="p-5 space-y-3">
                <div className="flex items-center justify-between text-gray-500 dark:text-gray-400">
                  <span className="text-xs font-semibold uppercase tracking-wider">Today Outbound</span>
                  <Send className="w-4 h-4 text-amber-500" />
                </div>
                <div>
                  <div className="flex items-baseline gap-1">
                    <span className="text-2xl font-black text-gray-900 dark:text-white">
                      {usage.outbound_today}
                    </span>
                    <span className="text-xs text-gray-400">
                      / {usage.outbound_daily_limit === -1 ? '∞' : usage.outbound_daily_limit}
                    </span>
                  </div>
                  <p className="text-xs text-gray-500 mt-1">Recipients dispatched today</p>
                </div>
                {usage.outbound_daily_limit !== -1 && (
                  <div className="w-full bg-gray-100 dark:bg-gray-800 rounded-full h-1.5 overflow-hidden">
                    <div
                      className="bg-amber-500 h-1.5 rounded-full"
                      style={{
                        width: `${Math.min(
                          Math.round((usage.outbound_today / usage.outbound_daily_limit) * 100),
                          100
                        )}%`,
                      }}
                    />
                  </div>
                )}
              </CardContent>
            </Card>

            {/* Mailbox Storage Limit */}
            <Card className="border border-gray-200 dark:border-gray-800 shadow-sm">
              <CardContent className="p-5 space-y-3">
                <div className="flex items-center justify-between text-gray-500 dark:text-gray-400">
                  <span className="text-xs font-semibold uppercase tracking-wider">Mailbox Storage</span>
                  <HardDrive className="w-4 h-4 text-blue-500" />
                </div>
                <div>
                  <div className="flex items-baseline gap-1">
                    <span className="text-2xl font-black text-gray-900 dark:text-white">
                      {usage.storage_mb_per_mailbox >= 1024
                        ? `${roundToOne(usage.storage_mb_per_mailbox / 1024)} GB`
                        : `${usage.storage_mb_per_mailbox} MB`}
                    </span>
                  </div>
                  <p className="text-xs text-gray-500 mt-1">Storage ceiling per mailbox</p>
                </div>
                <div className="w-full bg-blue-100 dark:bg-blue-950 rounded-full h-1.5">
                  <div className="bg-blue-500 h-1.5 rounded-full w-full" />
                </div>
              </CardContent>
            </Card>
          </div>
        </div>
      )}

      {/* Invoice History */}
      <div className="space-y-4 pt-4 border-t border-gray-100 dark:border-gray-800">
        <div>
          <h2 className="text-xl font-bold text-gray-900 dark:text-white tracking-tight">
            Invoice Ledger
          </h2>
          <p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
            View, inspect and download official billing statements.
          </p>
        </div>

        <InvoiceTable
          invoices={invoices?.data || []}
          isLoading={isInvoicesLoading}
          currentPage={invoices?.meta?.current_page || 1}
          lastPage={invoices?.meta?.last_page || 1}
          total={invoices?.meta?.total || 0}
          onPageChange={(newPage) => setPage(newPage)}
          statusFilter={statusFilter}
          onStatusFilterChange={(newStatus) => {
            setStatusFilter(newStatus);
            setPage(1);
          }}
        />
      </div>
    </div>
  );
}

function roundToOne(num: number): number {
  return Math.round(num * 10) / 10;
}
