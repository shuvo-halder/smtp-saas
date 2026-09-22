'use client';

import { useTranslations } from 'next-intl';
import AuditLogsTable from '@/components/admin/audit/AuditLogsTable';

export default function AdminAuditLogsPage() {
  const t = useTranslations('Admin.audit_logs');

  return (
    <div className="space-y-6 max-w-7xl mx-auto">
      {/* Page Header */}
      <div>
        <h1 className="text-2xl font-bold text-gray-900">{t('title')}</h1>
        <p className="text-sm text-gray-500 mt-1">{t('subtitle')}</p>
      </div>

      {/* Audit Logs Table */}
      <AuditLogsTable />
    </div>
  );
}
