'use client';

import { useState } from 'react';
import Link from 'next/link';
import { useTenantAliases } from '@/hooks/useAliases';
import { useDomains } from '@/hooks/useDomains';
import AliasRow from '@/components/aliases/AliasRow';
import CreateAliasModal from '@/components/aliases/CreateAliasModal';
import { Button, buttonVariants } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import {
  AlertCircle,
  ArrowRightLeft,
  ChevronLeft,
  ChevronRight,
  Filter,
  Globe,
  Info,
  Plus,
  Search,
} from 'lucide-react';
import { cn } from '@/lib/utils';

export default function TenantAliasesPage() {
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [domainId, setDomainId] = useState('');
  const [status, setStatus] = useState('');
  const [isCreateOpen, setIsCreateOpen] = useState(false);

  const { domains } = useDomains(1);
  const { aliases, isLoading, error, mutate } = useTenantAliases({
    page,
    per_page: 15,
    domain_id: domainId,
    status: status,
    search: search,
  });

  const domainOptions = domains?.data || [];
  const activeDomains = domainOptions.filter((d) => d.status === 'active');

  return (
    <div className="space-y-6">
      {/* Page Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-gray-900 dark:text-white flex items-center gap-2">
            <ArrowRightLeft className="h-6 w-6 text-indigo-600" />
            Email Aliases & Forwarding
          </h1>
          <p className="text-sm text-muted-foreground mt-1">
            Create virtual email addresses to forward inbound messages to designated mailboxes without consuming additional mailbox quotas.
          </p>
        </div>
        <Button onClick={() => setIsCreateOpen(true)} className="flex items-center gap-2">
          <Plus className="h-4 w-4" />
          Add Alias
        </Button>
      </div>

      {/* Info Notice */}
      <div className="bg-indigo-50/70 dark:bg-indigo-950/30 border border-indigo-100 dark:border-indigo-900 rounded-lg p-4 flex items-start gap-3 text-indigo-900 dark:text-indigo-200">
        <Info className="h-5 w-5 text-indigo-600 dark:text-indigo-400 mt-0.5 flex-shrink-0" />
        <div className="text-sm">
          <span className="font-semibold">Zero-overhead Forwarding:</span> Aliases do not store messages on the server; they route directly to your specified forwarding target. Addresses configured here automatically synchronize with Postfix virtual alias maps.
        </div>
      </div>

      {/* Filter / Search Bar */}
      <div className="bg-white dark:bg-gray-800 p-4 rounded-lg border shadow-sm flex flex-col md:flex-row gap-3">
        <div className="relative flex-1">
          <Search className="absolute left-3 top-2.5 h-4 w-4 text-gray-400" />
          <Input
            placeholder="Search by alias or forwarding address..."
            value={search}
            onChange={(e) => {
              setSearch(e.target.value);
              setPage(1);
            }}
            className="pl-9"
          />
        </div>

        <div className="flex flex-wrap gap-2">
          <select
            value={domainId}
            onChange={(e) => {
              setDomainId(e.target.value);
              setPage(1);
            }}
            className="rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 py-2 px-3 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
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
            className="rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 py-2 px-3 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
          >
            <option value="">All Statuses</option>
            <option value="active">Active</option>
            <option value="disabled">Disabled</option>
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
      {isLoading && !aliases ? (
        <div className="flex justify-center p-12 bg-white dark:bg-gray-800 rounded-lg border">
          <Spinner className="h-8 w-8 text-primary" />
        </div>
      ) : error ? (
        <div className="p-6 bg-red-50 border border-red-200 rounded-lg text-red-700 flex items-center gap-3">
          <AlertCircle className="h-5 w-5 flex-shrink-0" />
          <div>
            <h4 className="font-medium">Failed to load email aliases</h4>
            <p className="text-sm mt-0.5">Please check your network connection and try again.</p>
          </div>
        </div>
      ) : aliases?.data.length === 0 ? (
        <div className="text-center py-12 bg-white dark:bg-gray-800 rounded-lg border border-dashed p-8">
          <div className="mx-auto w-12 h-12 rounded-full bg-indigo-50 flex items-center justify-center text-indigo-600 mb-4">
            <ArrowRightLeft className="h-6 w-6" />
          </div>
          <h3 className="text-base font-semibold text-gray-900 dark:text-white">No email aliases found</h3>
          <p className="mt-1 text-sm text-gray-500 max-w-md mx-auto">
            {search || domainId || status
              ? 'No aliases match your active filter criteria. Try clearing search filters.'
              : 'Create email aliases (e.g. sales@, info@, support@) to forward incoming messages without setting up individual mailboxes.'}
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
              <Link href="/domains" className={cn(buttonVariants({ variant: 'default' }))}>
                <Globe className="h-4 w-4 mr-2" />
                Configure Domains First
              </Link>
            ) : (
              <Button onClick={() => setIsCreateOpen(true)}>
                <Plus className="h-4 w-4 mr-2" />
                Add Alias
              </Button>
            )}
          </div>
        </div>
      ) : (
        <div className="bg-white dark:bg-gray-800 rounded-lg border shadow-sm overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full text-left border-collapse">
              <thead>
                <tr className="bg-gray-50 dark:bg-gray-900/50 border-b text-xs font-semibold uppercase tracking-wider text-gray-500">
                  <th className="py-3 px-4">Source Alias</th>
                  <th className="py-3 px-4">Forwards To</th>
                  <th className="py-3 px-4">Delivery Status</th>
                  <th className="py-3 px-4">Status</th>
                  <th className="py-3 px-4">Created</th>
                  <th className="py-3 px-4 text-right">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
                {aliases?.data.map((alias) => (
                  <AliasRow key={alias.id} alias={alias} onRefresh={mutate} />
                ))}
              </tbody>
            </table>
          </div>

          {/* Pagination */}
          {aliases?.meta && aliases.meta.last_page > 1 && (
            <div className="flex items-center justify-between border-t border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 px-4 py-3 sm:px-6">
              <div className="hidden sm:flex sm:flex-1 sm:items-center sm:justify-between">
                <div>
                  <p className="text-sm text-gray-700 dark:text-gray-300">
                    Showing{' '}
                    <span className="font-medium">
                      {(aliases.meta.current_page - 1) * aliases.meta.per_page + 1}
                    </span>{' '}
                    to{' '}
                    <span className="font-medium">
                      {Math.min(aliases.meta.current_page * aliases.meta.per_page, aliases.meta.total)}
                    </span>{' '}
                    of <span className="font-medium">{aliases.meta.total}</span> aliases
                  </p>
                </div>
                <div className="flex gap-2">
                  <Button
                    variant="outline"
                    size="sm"
                    disabled={aliases.meta.current_page <= 1}
                    onClick={() => setPage((p) => Math.max(1, p - 1))}
                  >
                    <ChevronLeft className="h-4 w-4 mr-1" />
                    Previous
                  </Button>
                  <Button
                    variant="outline"
                    size="sm"
                    disabled={aliases.meta.current_page >= aliases.meta.last_page}
                    onClick={() => setPage((p) => p + 1)}
                  >
                    Next
                    <ChevronRight className="h-4 w-4 ml-1" />
                  </Button>
                </div>
              </div>
            </div>
          )}
        </div>
      )}

      {/* Create Alias Modal */}
      {isCreateOpen && (
        <CreateAliasModal
          availableDomains={domainOptions}
          initialDomainId={domainId || undefined}
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
