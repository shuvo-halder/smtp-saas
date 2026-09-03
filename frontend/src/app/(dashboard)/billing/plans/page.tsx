'use client';

import React, { useState } from 'react';
import { usePlans } from '@/hooks/useBilling';
import { useAuth } from '@/lib/auth';
import PlanCard from '@/components/billing/PlanCard';
import api from '@/lib/api';

export default function PlansPage() {
  const { plans, isLoading } = usePlans();
  const { user } = useAuth();
  const [billingCycle, setBillingCycle] = useState<'monthly' | 'yearly'>('yearly');
  const [checkoutLoading, setCheckoutLoading] = useState<number | string | null>(null);

  const handleCheckout = async (planId: number | string) => {
    setCheckoutLoading(planId);
    try {
      const response = await api.post('/api/billing/checkout', {
        plan_id: planId,
        billing_cycle: billingCycle,
      });
      if (response.data?.redirect_url) {
        window.location.href = response.data.redirect_url;
      }
    } catch (error) {
      console.error('Checkout failed', error);
      alert('Checkout failed. Please try again.');
    } finally {
      setCheckoutLoading(null);
    }
  };

  return (
    <div className="max-w-6xl mx-auto p-4 md:p-8">
      <div className="text-center mb-12">
        <h1 className="text-3xl font-bold text-gray-900 dark:text-white sm:text-4xl">Choose Your Plan</h1>
        <p className="mt-4 text-xl text-gray-500 dark:text-gray-400">
          Select the perfect plan for your email hosting needs.
        </p>
        
        <div className="mt-8 flex justify-center items-center">
          <div className="relative flex items-center p-1 bg-gray-100 dark:bg-gray-800 rounded-full">
            <button
              className={`px-4 py-2 text-sm font-medium rounded-full transition-colors ${
                billingCycle === 'monthly'
                  ? 'bg-white dark:bg-gray-700 shadow text-gray-900 dark:text-white'
                  : 'text-gray-500 hover:text-gray-700 dark:hover:text-gray-300'
              }`}
              onClick={() => setBillingCycle('monthly')}
            >
              Monthly billing
            </button>
            <button
              className={`px-4 py-2 text-sm font-medium rounded-full transition-colors ${
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

      {isLoading ? (
        <div className="text-center py-12 text-gray-500">Loading plans...</div>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-3 gap-8">
          {plans?.map((plan: any) => (
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
