'use client';

import { useState, useEffect, Suspense } from 'react';
import { useTranslations } from 'next-intl';
import { useSearchParams } from 'next/navigation';
import useSWR from 'swr';
import api from '@/lib/api';
import { SmtpOverview as SmtpOverviewType } from '@/types';
import SmtpOverview from '@/components/admin/smtp/SmtpOverview';
import SmtpTenantsTable from '@/components/admin/smtp/SmtpTenantsTable';
import SmtpMailboxesTable from '@/components/admin/smtp/SmtpMailboxesTable';
import SmtpAbuseTable from '@/components/admin/smtp/SmtpAbuseTable';
import SmtpIncidentsTable from '@/components/admin/smtp/SmtpIncidentsTable';
import { Spinner } from '@/components/ui/spinner';
import { Activity, Users, Mail, AlertTriangle, ShieldAlert } from 'lucide-react';

type TabKey = 'overview' | 'incidents' | 'tenants' | 'mailboxes' | 'abuse';

function AdminSmtpContent() {
  const t = useTranslations('Admin.smtp');
  const searchParams = useSearchParams();

  const [activeTab, setActiveTab] = useState<TabKey>('overview');
  const [mailboxSearch, setMailboxSearch] = useState('');

  useEffect(() => {
    const tabParam = searchParams?.get('tab');
    if (tabParam && ['overview', 'incidents', 'tenants', 'mailboxes', 'abuse'].includes(tabParam)) {
      setActiveTab(tabParam as TabKey);
    }
  }, [searchParams]);

  const fetcher = (url: string) => api.get(url).then(res => res.data);
  const { data: overview, isLoading: isOverviewLoading, mutate: mutateOverview } = useSWR<SmtpOverviewType>(
    '/api/admin/smtp/overview',
    fetcher
  );

  const handleSelectMailboxFromAbuse = (email: string) => {
    setMailboxSearch(email);
    setActiveTab('mailboxes');
  };

  const handleActionComplete = () => {
    mutateOverview();
  };

  const tabs: { key: TabKey; label: string; icon: any; count?: number | null }[] = [
    { key: 'overview', label: t('tabs.overview'), icon: Activity },
    { key: 'incidents', label: t('tabs.incidents'), icon: ShieldAlert },
    { key: 'tenants', label: t('tabs.tenants'), icon: Users },
    { key: 'mailboxes', label: t('tabs.mailboxes'), icon: Mail },
    { 
      key: 'abuse', 
      label: t('tabs.abuse'), 
      icon: AlertTriangle, 
      count: overview?.active_abuse_warnings_count 
    },
  ];

  return (
    <div className="space-y-6 max-w-7xl mx-auto">
      {/* Page Header */}
      <div>
        <h1 className="text-2xl font-bold text-gray-900">{t('title')}</h1>
        <p className="text-sm text-gray-500 mt-1">{t('subtitle')}</p>
      </div>

      {/* Tabs Navigation */}
      <div className="border-b border-gray-200">
        <nav className="-mb-px flex space-x-6">
          {tabs.map((tab) => {
            const Icon = tab.icon;
            const isActive = activeTab === tab.key;

            return (
              <button
                key={tab.key}
                onClick={() => setActiveTab(tab.key)}
                className={`flex items-center pb-3 px-1 border-b-2 text-sm font-medium transition-colors ${
                  isActive
                    ? 'border-indigo-600 text-indigo-600'
                    : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
                }`}
              >
                <Icon className={`w-4 h-4 mr-2 ${isActive ? 'text-indigo-600' : 'text-gray-400'}`} />
                <span>{tab.label}</span>
                {tab.count !== undefined && tab.count !== null && tab.count > 0 && (
                  <span className="ml-2 bg-red-100 text-red-800 text-xs px-2 py-0.5 rounded-full font-bold">
                    {tab.count}
                  </span>
                )}
              </button>
            );
          })}
        </nav>
      </div>

      {/* Tab Panels */}
      <div>
        {activeTab === 'overview' && (
          <SmtpOverview
            overview={overview}
            isLoading={isOverviewLoading}
            onRefresh={mutateOverview}
          />
        )}

        {activeTab === 'incidents' && (
          <SmtpIncidentsTable
            onActionComplete={handleActionComplete}
          />
        )}

        {activeTab === 'tenants' && (
          <SmtpTenantsTable />
        )}

        {activeTab === 'mailboxes' && (
          <SmtpMailboxesTable
            initialSearch={mailboxSearch}
            onActionComplete={handleActionComplete}
          />
        )}

        {activeTab === 'abuse' && (
          <SmtpAbuseTable
            onSelectMailbox={handleSelectMailboxFromAbuse}
            onRefresh={mutateOverview}
          />
        )}
      </div>
    </div>
  );
}

export default function AdminSmtpPage() {
  return (
    <Suspense fallback={<div className="flex h-64 items-center justify-center"><Spinner /></div>}>
      <AdminSmtpContent />
    </Suspense>
  );
}
