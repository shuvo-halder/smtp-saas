'use client';

import React from 'react';
import { useAuth } from '@/lib/auth';
import { useInvoices } from '@/hooks/useBilling';
import InvoiceTable from '@/components/billing/InvoiceTable';
import { Card } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import Link from 'next/link';

export default function BillingPage() {
  const { user, isLoading: isUserLoading } = useAuth();
  const { invoices, isLoading: isInvoicesLoading } = useInvoices(1);

  if (isUserLoading) return <div className="p-8">Loading...</div>;

  return (
    <div className="max-w-6xl mx-auto p-4 md:p-8 space-y-8">
      <div className="flex justify-between items-center">
        <h1 className="text-3xl font-bold text-gray-900 dark:text-white">Billing & Subscription</h1>
      </div>

      <Card className="p-6">
        <h2 className="text-xl font-semibold mb-4 text-gray-900 dark:text-white">Current Plan</h2>
        {user?.plan ? (
          <div className="flex flex-col md:flex-row justify-between items-start md:items-center p-4 bg-gray-50 dark:bg-gray-900/50 rounded-lg border border-gray-200 dark:border-gray-700">
            <div>
              <h3 className="text-lg font-bold text-gray-900 dark:text-white">{user.plan.name}</h3>
              <p className="text-sm text-gray-500 dark:text-gray-400 mt-1">
                Status: <span className="font-medium text-green-600 capitalize">{user.status}</span>
              </p>
              {user.plan_expires_at && (
                <p className="text-sm text-gray-500 dark:text-gray-400 mt-1">
                  Expires/Renews on: {new Date(user.plan_expires_at).toLocaleDateString()}
                </p>
              )}
            </div>
            <div className="mt-4 md:mt-0">
              <Link href="/billing/plans">
                <Button>Upgrade or Change Plan</Button>
              </Link>
            </div>
          </div>
        ) : (
          <div className="text-center py-6 bg-gray-50 dark:bg-gray-900/50 rounded-lg border border-gray-200 dark:border-gray-700">
            <p className="text-gray-600 dark:text-gray-400 mb-4">You are not currently subscribed to any active plan.</p>
            <Link href="/billing/plans">
              <Button size="lg">Choose a Plan</Button>
            </Link>
          </div>
        )}
      </Card>

      <div>
        <h2 className="text-xl font-semibold mb-4 text-gray-900 dark:text-white">Invoice History</h2>
        <InvoiceTable 
          invoices={invoices?.data || invoices || []} 
          isLoading={isInvoicesLoading} 
        />
      </div>
    </div>
  );
}
