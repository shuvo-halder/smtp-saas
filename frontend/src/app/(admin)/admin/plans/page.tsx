'use client';

import { useTranslations } from 'next-intl';

export default function AdminPlansPage() {
  const t = useTranslations('Admin.plans');

  return (
    <div className="space-y-6 max-w-6xl mx-auto">
      <h1 className="text-2xl font-bold text-gray-900">{t('title')}</h1>
      <div className="bg-white p-6 rounded-lg border border-gray-200 text-center text-gray-500">
        Subscription Plans Management (Coming Soon)
      </div>
    </div>
  );
}
