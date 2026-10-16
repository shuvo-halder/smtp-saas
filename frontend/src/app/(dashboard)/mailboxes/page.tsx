'use client';

import { useState } from 'react';
import Link from 'next/link';
import { useTenantMailboxes, useMailboxStorageOverview } from '@/hooks/useMailboxes';
import { useDomains } from '@/hooks/useDomains';
import MailboxRow from '@/components/mailboxes/MailboxRow';
import CreateMailboxModal from '@/components/mailboxes/CreateMailboxModal';
import { Button, buttonVariants } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Mail, Plus, Search, Filter, AlertCircle, ChevronLeft, ChevronRight, Globe, HardDrive } from 'lucide-react';
import { cn } from '@/lib/utils';

export default function TenantMailboxesPage() {
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [domainId, setDomainId] = useState('');
  const [status, setStatus] = useState('');
  const [isCreateOpen, setIsCreateOpen] = useState(false);

  const { domains } = useDomains(1);
  const { mailboxes, isLoading, error, mutate } = useTenantMailboxes({
    page,
    per_page: 15,
    domain_id: domainId,
    status: status,
    search: search,
  });

  const { storageOverview, mutate: mutateStorage } = useMailboxStorageOverview({
    domain_id: domainId,
    search: search,
  });

  const domainOptions = domains?.data || [];
  const activeDomains = domainOptions.filter((d) => d.status === 'active');
  const summary = storageOverview?.summary;

  return (
    <div className="space-y-6">
      {/* Page Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-gray-900">Mailboxes</h1>
          <p className="text-sm text-muted-foreground mt-1">
            Manage your company email accounts across all verified domains.
          </p>
        </div>
        <Button onClick={() => setIsCreateOpen(true)} className="flex items-center gap-2">
          <Plus className="h-4 w-4" />
          Add Mailbox
        </Button>
      </div>

      {/* Storage & Quota Telemetry Overview Banner */}
      <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div className="bg-white p-4 rounded-lg border shadow-sm flex items-center gap-3">
          <div className="p-2.5 rounded-lg bg-indigo-50 text-indigo-600">
            <Mail className="h-5 w-5" />
          </div>
          <div>
            <div className="text-xs text-gray-500 font-medium">Provisioned Mailboxes</div>
            <div className="text-lg font-bold text-gray-900">
              {summary ? `${summary.active_mailboxes} / ${summary.total_mailboxes} Active` : '-'}
            </div>
            <div className="text-xs text-gray-400">Accounts configured</div>
          </div>
        </div>

        <div className="bg-white p-4 rounded-lg border shadow-sm flex items-center gap-3">
          <div className="p-2.5 rounded-lg bg-emerald-50 text-emerald-600">
            <HardDrive className="h-5 w-5" />
          </div>
          <div>
            <div className="text-xs text-gray-500 font-medium">Provisioned Storage</div>
            <div className="text-lg font-bold text-gray-900">
              {summary ? summary.total_quota_formatted : '-'}
            </div>
            <div className="text-xs text-gray-400">
              {summary ? `Cap: ${summary.plan_storage_limit_formatted || `${summary.plan_storage_limit_per_mailbox_mb} MB`}/box` : '-'}
            </div>
          </div>
        </div>

        <div className="bg-white p-4 rounded-lg border shadow-sm flex items-center gap-3">
          <div className="p-2.5 rounded-lg bg-blue-50 text-blue-600">
            <Filter className="h-5 w-5" />
          </div>
          <div>
            <div className="text-xs text-gray-500 font-medium">Storage Telemetry</div>
            <div className="text-sm font-semibold text-gray-800">
              {summary?.environment_support
                ? `${summary.measured_mailboxes_count} / ${summary.total_mailboxes} Measured`
                : 'Dovecot Unmeasured'}
            </div>
            <div className="text-xs text-gray-400">
              {summary?.environment_support
                ? `${summary.total_usage_formatted} used`
                : 'Local environment (Offline MDA)'}
            </div>
          </div>
        </div>
      </div>

      {/* Filter / Search Bar */}
      <div className="bg-white p-4 rounded-lg border shadow-sm flex flex-col md:flex-row gap-3">
        <div className="relative flex-1">
          <Search className="absolute left-3 top-2.5 h-4 w-4 text-gray-400" />
          <Input
            placeholder="Search by email, name..."
            value={search}
            onChange={(e) => {
              setSearch(e.target.value);
              setPage(1);
            }}
            className="pl-9"
          />
        </div>

        <div className="flex gap-2">
          <select
            value={domainId}
            onChange={(e) => {
              setDomainId(e.target.value);
              setPage(1);
            }}
            className="rounded-md border border-gray-300 bg-white py-2 px-3 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-indigo-500"
          >
            <option value="">All Domains</option>
            {domainOptions.map((dom) => (
              <option key={dom.id} value={dom.id}>
                {dom.domain_name} {dom.status !== 'active' ? `(${dom.status})` : ''}
              </option>
            ))}
          </select>

          <select
            value={status}
            onChange={(e) => {
              setStatus(e.target.value);
              setPage(1);
            }}
            className="rounded-md border border-gray-300 bg-white py-2 px-3 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-indigo-500"
          >
            <option value="">All Statuses</option>
            <option value="active">Active</option>
            <option value="disabled">Disabled</option>
            <option value="over_limit">Storage: Over Quota</option>
            <option value="near_limit">Storage: Near Limit (90%+)</option>
            <option value="warning">Storage: Warning (80%+)</option>
            <option value="normal">Storage: Normal Usage</option>
            <option value="unknown">Storage: Unmeasured</option>
          </select>

          {(search || domainId || status) && (
            <Button
              variant="ghost"
              size="sm"
              onClick={() => {
                setSearch('');
                setDomainId('');
                setStatus('');
                setPage(1);
              }}
            >
              Reset
            </Button>
          )}
        </div>
      </div>

      {/* Content State */}
      {isLoading && !mailboxes ? (
        <div className="flex justify-center p-12 bg-white rounded-lg border">
          <Spinner className="h-8 w-8 text-primary" />
        </div>
      ) : error ? (
        <div className="p-6 bg-red-50 border border-red-200 rounded-lg text-red-700 flex items-center gap-3">
          <AlertCircle className="h-5 w-5 flex-shrink-0" />
          <div>
            <h4 className="font-medium">Failed to load mailboxes</h4>
            <p className="text-sm mt-0.5">Please check your network connection and try again.</p>
          </div>
        </div>
      ) : mailboxes?.data.length === 0 ? (
        <div className="text-center py-12 bg-white rounded-lg border border-dashed p-8">
          <div className="mx-auto w-12 h-12 rounded-full bg-indigo-50 flex items-center justify-center text-indigo-600 mb-4">
            <Mail className="h-6 w-6" />
          </div>
          <h3 className="text-base font-semibold text-gray-900">No mailboxes found</h3>
          <p className="mt-1 text-sm text-gray-500 max-w-md mx-auto">
            {search || domainId || status
              ? 'No mailboxes match your active filter criteria. Try clearing search filters.'
              : 'Create your first mailbox under a verified domain to start sending and receiving emails.'}
          </p>

          <div className="mt-6 flex justify-center gap-3">
            {search || domainId || status ? (
              <Button
                variant="outline"
                onClick={() => {
                  setSearch('');
                  setDomainId('');
                  setStatus('');
                  setPage(1);
                }}
              >
                Clear Filters
              </Button>
            ) : activeDomains.length === 0 ? (
              <Link href="/domains" className={cn(buttonVariants({ variant: "default" }))}>
                <Globe className="h-4 w-4 mr-2" />
                Configure Domains First
              </Link>
            ) : (
              <Button onClick={() => setIsCreateOpen(true)}>
                <Plus className="h-4 w-4 mr-2" />
                Add Mailbox
              </Button>
            )}
          </div>
        </div>
      ) : (
        <div className="bg-white rounded-lg border shadow-sm overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full text-left border-collapse">
              <thead>
                <tr className="bg-gray-50 border-b text-xs font-semibold uppercase tracking-wider text-gray-500">
                  <th className="py-3 px-4">Mailbox</th>
                  <th className="py-3 px-4">Domain</th>
                  <th className="py-3 px-4">Storage & Quota</th>
                  <th className="py-3 px-4">Status</th>
                  <th className="py-3 px-4">Last Login</th>
                  <th className="py-3 px-4">Webmail</th>
                  <th className="py-3 px-4 text-right">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200">
                {mailboxes?.data.map((mailbox) => (
                  <MailboxRow
                    key={mailbox.id}
                    mailbox={mailbox}
                    showDomain={true}
                    onRefresh={() => {
                      mutate();
                      mutateStorage();
                    }}
                  />
                ))}
              </tbody>
            </table>
          </div>

          {/* Pagination */}
          {mailboxes?.meta && mailboxes.meta.last_page > 1 && (
            <div className="flex items-center justify-between border-t border-gray-200 bg-white px-4 py-3 sm:px-6">
              <div className="hidden sm:flex sm:flex-1 sm:items-center sm:justify-between">
                <div>
                  <p className="text-sm text-gray-700">
                    Showing{' '}
                    <span className="font-medium">
                      {(mailboxes.meta.current_page - 1) * mailboxes.meta.per_page + 1}
                    </span>{' '}
                    to{' '}
                    <span className="font-medium">
                      {Math.min(
                        mailboxes.meta.current_page * mailboxes.meta.per_page,
                        mailboxes.meta.total
                      )}
                    </span>{' '}
                    of <span className="font-medium">{mailboxes.meta.total}</span> mailboxes
                  </p>
                </div>
                <div>
                  <nav className="isolate inline-flex -space-x-px rounded-md shadow-sm">
                    <Button
                      variant="outline"
                      className="rounded-l-md rounded-r-none px-2.5 h-8"
                      disabled={page === 1}
                      onClick={() => setPage(page - 1)}
                    >
                      <ChevronLeft className="h-4 w-4" />
                    </Button>
                    <span className="relative inline-flex items-center px-4 py-1 text-xs font-semibold text-gray-900 border border-input">
                      Page {page} of {mailboxes.meta.last_page}
                    </span>
                    <Button
                      variant="outline"
                      className="rounded-r-md rounded-l-none px-2.5 h-8"
                      disabled={page === mailboxes.meta.last_page}
                      onClick={() => setPage(page + 1)}
                    >
                      <ChevronRight className="h-4 w-4" />
                    </Button>
                  </nav>
                </div>
              </div>
            </div>
          )}
        </div>
      )}

      {/* Create Mailbox Modal */}
      {isCreateOpen && (
        <CreateMailboxModal
          availableDomains={domainOptions}
          onClose={() => setIsCreateOpen(false)}
          onSuccess={() => {
            setIsCreateOpen(false);
            mutate();
          }}
        />
      )}
    </div>
  );
}
