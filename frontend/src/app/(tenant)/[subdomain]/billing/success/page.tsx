'use client';

import { useTranslations } from 'next-intl';
import Link from 'next/link';
import { Card, CardContent } from '@/components/ui/card';
import { CheckCircle } from 'lucide-react';
import { Button, buttonVariants } from '@/components/ui/button';

export default function PaymentSuccessPage() {
  const t = useTranslations('Tenant.billing');

  return (
    <div className="flex items-center justify-center min-h-[60vh]">
      <Card className="max-w-md w-full shadow-lg border-green-100">
        <CardContent className="pt-10 pb-8 px-8 text-center flex flex-col items-center">
          <div className="rounded-full bg-green-100 p-3 mb-4">
            <CheckCircle className="h-12 w-12 text-green-600" />
          </div>
          <h1 className="text-2xl font-bold text-gray-900 mb-2">Payment Successful!</h1>
          <p className="text-gray-500 mb-8">
            Thank you for your purchase. Your subscription has been successfully upgraded and your new limits are now active.
          </p>
          <div className="w-full flex gap-3">
            <Link href="/dashboard" className={buttonVariants({ variant: 'default', className: "w-full bg-indigo-600 hover:bg-indigo-700" })}>
              Return to Dashboard
            </Link>
            <Link href="/billing" className={buttonVariants({ variant: 'outline', className: "w-full" })}>
              View Invoice
            </Link>
          </div>
        </CardContent>
      </Card>
    </div>
  );
}
