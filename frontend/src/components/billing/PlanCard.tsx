import React from 'react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import { formatCurrency } from '@/lib/utils';
import { Check, Sparkles } from 'lucide-react';

interface PlanCardProps {
  plan: any;
  billingCycle: 'monthly' | 'yearly';
  isCurrentPlan: boolean;
  onSelect: (planId: number | string) => void;
  isLoading?: boolean;
}

export default function PlanCard({
  plan,
  billingCycle,
  isCurrentPlan,
  onSelect,
  isLoading
}: PlanCardProps) {
  const price = billingCycle === 'yearly' ? plan.price_yearly : plan.price_monthly;
  const isFeatured = plan.is_featured;

  return (
    <Card className={`relative flex flex-col p-6 h-full transition-all duration-200 ${isFeatured ? 'border-indigo-500 border-2 shadow-lg ring-1 ring-indigo-500/20' : 'border-gray-200 dark:border-gray-800'}`}>
      {isFeatured && (
        <div className="absolute top-0 left-1/2 -translate-x-1/2 -translate-y-1/2">
          <Badge variant="default" className="bg-indigo-600 hover:bg-indigo-700 text-white uppercase tracking-wider text-xs px-3 py-1 flex items-center gap-1 shadow-sm">
            <Sparkles className="w-3 h-3" /> Recommended
          </Badge>
        </div>
      )}
      
      <div className="mb-4">
        <h3 className="text-xl font-bold text-gray-900 dark:text-white">{plan.name}</h3>
        <p className="text-gray-500 dark:text-gray-400 text-sm mt-2 min-h-[40px]">{plan.description}</p>
      </div>

      <div className="mb-6 flex items-baseline">
        <span className="text-3xl sm:text-4xl font-extrabold text-gray-900 dark:text-white">
          {formatCurrency(price)}
        </span>
        <span className="text-gray-500 dark:text-gray-400 ml-2 text-sm font-medium">
          /{billingCycle === 'yearly' ? 'year' : 'month'}
        </span>
      </div>

      {billingCycle === 'yearly' && plan.yearly_discount > 0 && (
        <div className="mb-6">
          <Badge variant="success">Save {plan.yearly_discount}% with annual billing</Badge>
        </div>
      )}

      <ul className="flex-1 space-y-3 mb-6 text-sm">
        <li className="flex items-center text-gray-600 dark:text-gray-300">
          <Check className="w-4 h-4 text-emerald-500 mr-2.5 flex-shrink-0" />
          <span>{plan.max_domains === -1 ? 'Unlimited' : plan.max_domains} Domains</span>
        </li>
        <li className="flex items-center text-gray-600 dark:text-gray-300">
          <Check className="w-4 h-4 text-emerald-500 mr-2.5 flex-shrink-0" />
          <span>{plan.max_mailboxes_per_domain === -1 ? 'Unlimited' : plan.max_mailboxes_per_domain} Mailboxes / Domain</span>
        </li>
        <li className="flex items-center text-gray-600 dark:text-gray-300">
          <Check className="w-4 h-4 text-emerald-500 mr-2.5 flex-shrink-0" />
          <span>
            {plan.storage_mb_per_mailbox >= 1024
              ? `${Math.round(plan.storage_mb_per_mailbox / 1024)} GB`
              : `${plan.storage_mb_per_mailbox} MB`} Storage / Mailbox
          </span>
        </li>
        {plan.daily_outbound_recipients && (
          <li className="flex items-center text-gray-600 dark:text-gray-300">
            <Check className="w-4 h-4 text-emerald-500 mr-2.5 flex-shrink-0" />
            <span>{plan.daily_outbound_recipients.toLocaleString()} Daily Outbound Recipients</span>
          </li>
        )}
        {plan.features?.map((feature: string, idx: number) => (
          <li key={idx} className="flex items-center text-gray-600 dark:text-gray-300">
            <Check className="w-4 h-4 text-emerald-500 mr-2.5 flex-shrink-0" />
            <span>{feature}</span>
          </li>
        ))}
      </ul>

      {isCurrentPlan ? (
        <div className="mt-auto w-full text-center">
          <Badge variant="secondary" className="w-full justify-center py-2.5 text-sm bg-gray-100 dark:bg-gray-800 text-gray-800 dark:text-gray-200">
            Current Plan
          </Badge>
        </div>
      ) : (
        <Button 
          variant={isFeatured ? 'default' : 'outline'} 
          className={`w-full mt-auto ${isFeatured ? 'bg-indigo-600 hover:bg-indigo-700 text-white' : ''}`}
          onClick={() => onSelect(plan.id)}
          disabled={isLoading}
        >
          {isLoading ? 'Processing...' : 'Choose Plan'}
        </Button>
      )}
    </Card>
  );
}
