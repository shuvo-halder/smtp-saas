'use client';

import React, { useState } from 'react';
import Link from 'next/link';
import { usePlans, initiateCheckout } from '@/hooks/useBilling';
import { useAuth } from '@/lib/auth';
import PlanCard from '@/components/billing/PlanCard';
import { Button } from '@/components/ui/button';
import { ArrowLeft, AlertCircle } from 'lucide-react';
import { Plan } from '@/types';

export default function PlansPage() {
  const { plans, isLoading, error: plansError } = usePlans();
  const { user } = useAuth();
  const [billingCycle, setBillingCycle] = useState<'monthly' | 'yearly'>('yearly');
  const [checkoutLoading, setCheckoutLoading] = useState<number | string | null>(null);
  const [checkoutError, setCheckoutError] = useState<string | null>(null);

  const handleCheckout = async (planId: number | string) => {
    setCheckoutLoading(planId);
    setCheckoutError(null);
    try {
      const response = await initiateCheckout(planId, billingCycle);
      if (response?.redirect_url) {
        window.location.href = response.redirect_url;
      }
    } catch (error: any) {
      console.error('Checkout failed', error);
      setCheckoutError(
        error.response?.data?.message || 'Payment initiation failed. Please try again.'
      );
      setCheckoutLoading(null);
    }
  };

  return (
    <div className="max-w-6xl mx-auto p-4 md:p-8 space-y-8">
      {/* Top Navigation */}
      <div>
        <Link href="/billing">
          <Button variant="ghost" size="sm" className="gap-1.5 text-gray-600 dark:text-gray-400">
            <ArrowLeft className="w-4 h-4" /> Back to Billing Dashboard
          </Button>
        </Link>
      </div>

      <div className="text-center mb-8">
        <h1 className="text-3xl font-extrabold text-gray-900 dark:text-white sm:text-4xl tracking-tight">
          Subscription Plans & Pricing
        </h1>
        <p className="mt-3 text-base text-gray-500 dark:text-gray-400 max-w-2xl mx-auto">
          Choose the ideal tier for your business domains, mailboxes, and outbound deliverability quotas.
        </p>

        {/* Billing Cycle Switcher */}
        <div className="mt-8 flex justify-center items-center">
          <div className="relative flex items-center p-1 bg-gray-100 dark:bg-gray-800 rounded-full border border-gray-200 dark:border-gray-700">
            <button
              className={`px-5 py-2 text-sm font-semibold rounded-full transition-all ${
                billingCycle === 'monthly'
                  ? 'bg-white dark:bg-gray-700 shadow text-gray-900 dark:text-white'
                  : 'text-gray-500 hover:text-gray-700 dark:hover:text-gray-300'
              }`}
              onClick={() => setBillingCycle('monthly')}
            >
              Monthly billing
            </button>
            <button
              className={`px-5 py-2 text-sm font-semibold rounded-full transition-all ${
                billingCycle === 'yearly'
                  ? 'bg-white dark:bg-gray-700 shadow text-gray-900 dark:text-white'
                  : 'text-gray-500 hover:text-gray-700 dark:hover:text-gray-300'
              }`}
              onClick={() => setBillingCycle('yearly')}
            >
              Yearly billing
            </button>
          </div>
        </div>
      </div>

      {checkoutError && (
        <div className="max-w-xl mx-auto bg-red-50 dark:bg-red-950/40 p-4 rounded-xl border border-red-200 dark:border-red-800 flex items-center text-red-700 dark:text-red-300 text-sm">
          <AlertCircle className="w-4 h-4 mr-2 flex-shrink-0" /> {checkoutError}
        </div>
      )}

      {plansError && (
        <div className="max-w-xl mx-auto bg-red-50 p-4 rounded-xl border border-red-200 text-red-700 text-sm text-center">
          Failed to load available subscription plans. Please refresh or try again later.
        </div>
      )}

      {isLoading ? (
        <div className="text-center py-16 text-gray-500 dark:text-gray-400">
          <div className="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600 mb-3"></div>
          <p>Loading available plans...</p>
        </div>
      ) : plans.length === 0 ? (
        <div className="text-center py-16 bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700">
          <p className="text-gray-500 dark:text-gray-400">No active plans are currently published.</p>
        </div>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-3 gap-8 items-stretch">
          {plans.map((plan: Plan) => (
            <PlanCard
              key={plan.id}
              plan={plan}
              billingCycle={billingCycle}
              isCurrentPlan={user?.plan?.id === plan.id}
              onSelect={handleCheckout}
              isLoading={checkoutLoading === plan.id}
            />
          ))}
        </div>
      )}
    </div>
  );
}
