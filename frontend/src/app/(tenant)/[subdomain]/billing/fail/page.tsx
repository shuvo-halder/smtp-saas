'use client';

import { useTranslations } from 'next-intl';
import Link from 'next/link';
import { Card, CardContent } from '@/components/ui/card';
import { XCircle } from 'lucide-react';
import { Button, buttonVariants } from '@/components/ui/button';

export default function PaymentFailedPage() {
  const t = useTranslations('Tenant.billing');

  return (
    <div className="flex items-center justify-center min-h-[60vh]">
      <Card className="max-w-md w-full shadow-lg border-red-100">
        <CardContent className="pt-10 pb-8 px-8 text-center flex flex-col items-center">
          <div className="rounded-full bg-red-100 p-3 mb-4">
            <XCircle className="h-12 w-12 text-red-600" />
          </div>
          <h1 className="text-2xl font-bold text-gray-900 mb-2">Payment Failed</h1>
          <p className="text-gray-500 mb-8">
            Unfortunately, your payment could not be processed. Please check your payment method and try again. No charges were made.
          </p>
          <div className="w-full flex gap-3">
            <Link href="/billing" className={buttonVariants({ variant: 'default', className: "w-full bg-indigo-600 hover:bg-indigo-700" })}>
              Try Again
            </Link>
            <Link href="/dashboard" className={buttonVariants({ variant: 'outline', className: "w-full" })}>
              Return to Dashboard
            </Link>
          </div>
        </CardContent>
      </Card>
    </div>
  );
}
