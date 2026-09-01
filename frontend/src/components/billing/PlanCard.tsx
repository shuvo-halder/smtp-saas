import React from 'react';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { Card } from '@/components/ui/Card';

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
    <Card className={`relative flex flex-col p-6 h-full ${isFeatured ? 'border-primary border-2 shadow-lg' : ''}`}>
      {isFeatured && (
        <div className="absolute top-0 left-1/2 -translate-x-1/2 -translate-y-1/2">
          <Badge variant="default" className="bg-primary text-white uppercase tracking-wider text-xs px-3 py-1">
            Recommended
          </Badge>
        </div>
      )}
      
      <div className="mb-4">
        <h3 className="text-xl font-bold text-gray-900 dark:text-white">{plan.name}</h3>
        <p className="text-gray-500 dark:text-gray-400 text-sm mt-2 min-h-[40px]">{plan.description}</p>
      </div>

      <div className="mb-6 flex items-baseline">
        <span className="text-4xl font-extrabold text-gray-900 dark:text-white">${price}</span>
        <span className="text-gray-500 dark:text-gray-400 ml-2">/{billingCycle === 'yearly' ? 'year' : 'mo'}</span>
      </div>

      {billingCycle === 'yearly' && plan.yearly_discount && (
        <div className="mb-6">
          <Badge variant="success">Save {plan.yearly_discount}%</Badge>
        </div>
      )}

      <ul className="flex-1 space-y-3 mb-6">
        <li className="flex items-center text-gray-600 dark:text-gray-300">
          <svg className="w-5 h-5 text-green-500 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7"></path></svg>
          {plan.max_domains_label || `${plan.max_domains} Domains`}
        </li>
        <li className="flex items-center text-gray-600 dark:text-gray-300">
          <svg className="w-5 h-5 text-green-500 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7"></path></svg>
          {plan.max_mailboxes_label || `${plan.max_mailboxes_per_domain} Mailboxes / Domain`}
        </li>
        <li className="flex items-center text-gray-600 dark:text-gray-300">
          <svg className="w-5 h-5 text-green-500 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7"></path></svg>
          {plan.storage_mb_per_mailbox} MB Storage / Mailbox
        </li>
        {plan.features?.map((feature: string, idx: number) => (
          <li key={idx} className="flex items-center text-gray-600 dark:text-gray-300">
            <svg className="w-5 h-5 text-green-500 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7"></path></svg>
            {feature}
          </li>
        ))}
      </ul>

      {isCurrentPlan ? (
        <div className="mt-auto w-full text-center">
          <Badge variant="secondary" className="w-full justify-center py-2 text-sm">
            Current Plan
          </Badge>
        </div>
      ) : (
        <Button 
          variant={isFeatured ? 'default' : 'outline'} 
          className="w-full mt-auto"
          onClick={() => onSelect(plan.id)}
          disabled={isLoading}
        >
          {isLoading ? 'Processing...' : 'Choose Plan'}
        </Button>
      )}
    </Card>
  );
}
