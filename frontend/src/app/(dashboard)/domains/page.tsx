'use client';

import { useState } from 'react';
import Link from 'next/link';
import { useDomains } from '@/hooks/useDomains';
import { DomainCard } from '@/components/domains/DomainCard';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { Plus, AlertCircle, ChevronLeft, ChevronRight } from 'lucide-react';
import { buttonVariants } from '@/components/ui/button';
import { cn } from '@/lib/utils';

export default function DomainsPage() {
  const [page, setPage] = useState(1);
  const { domains, isLoading, error } = useDomains(page);

  if (isLoading && !domains) {
    return <div className="flex justify-center p-8"><Spinner className="h-8 w-8 text-primary" /></div>;
  }

  if (error) {
    return <div className="text-red-500 flex items-center gap-2"><AlertCircle className="h-5 w-5" /> Failed to load domains.</div>;
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <h1 className="text-2xl font-bold tracking-tight">Domains</h1>
        <Link href="/domains/new" className={cn(buttonVariants({ variant: "default" }))}>
          <Plus className="mr-2 h-4 w-4" />
          Add Domain
        </Link>
      </div>

      {domains?.data.length === 0 ? (
        <div className="text-center py-12 bg-white rounded-lg border border-dashed">
          <h3 className="mt-2 text-sm font-semibold text-gray-900">No domains</h3>
          <p className="mt-1 text-sm text-gray-500">Get started by adding a new domain to your account.</p>
          <div className="mt-6">
            <Link href="/domains/new" className={cn(buttonVariants({ variant: "default" }))}>
              <Plus className="mr-2 h-4 w-4" />
              Add Domain
            </Link>
          </div>
        </div>
      ) : (
        <>
          <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            {domains?.data.map((domain) => (
              <DomainCard key={domain.id} domain={domain} />
            ))}
          </div>
          
          {domains?.meta && domains.meta.last_page > 1 && (
            <div className="flex items-center justify-between border-t border-gray-200 bg-white px-4 py-3 sm:px-6 mt-6 rounded-lg border">
              <div className="hidden sm:flex sm:flex-1 sm:items-center sm:justify-between">
                <div>
                  <p className="text-sm text-gray-700">
                    Showing <span className="font-medium">{((domains.meta.current_page - 1) * domains.meta.per_page) + 1}</span> to <span className="font-medium">{Math.min(domains.meta.current_page * domains.meta.per_page, domains.meta.total)}</span> of <span className="font-medium">{domains.meta.total}</span> results
                  </p>
                </div>
                <div>
                  <nav className="isolate inline-flex -space-x-px rounded-md shadow-sm" aria-label="Pagination">
                    <Button
                      variant="outline"
                      className="rounded-l-md rounded-r-none px-2"
                      disabled={page === 1}
                      onClick={() => setPage(page - 1)}
                    >
                      <span className="sr-only">Previous</span>
                      <ChevronLeft className="h-5 w-5" aria-hidden="true" />
                    </Button>
                    <span className="relative inline-flex items-center px-4 py-2 text-sm font-semibold text-gray-900 ring-1 ring-inset ring-gray-300">
                      Page {page} of {domains.meta.last_page}
                    </span>
                    <Button
                      variant="outline"
                      className="rounded-r-md rounded-l-none px-2"
                      disabled={page === domains.meta.last_page}
                      onClick={() => setPage(page + 1)}
                    >
                      <span className="sr-only">Next</span>
                      <ChevronRight className="h-5 w-5" aria-hidden="true" />
                    </Button>
                  </nav>
                </div>
              </div>
            </div>
          )}
        </>
      )}
    </div>
  );
}
