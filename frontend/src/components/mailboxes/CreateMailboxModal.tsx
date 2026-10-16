'use client';

import React, { useState } from 'react';
import Link from 'next/link';
import api from '@/lib/api';
import { Button, buttonVariants } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { AlertCircle } from 'lucide-react';
import { cn } from '@/lib/utils';

interface DomainOption {
  id: number;
  domain_name: string;
  status: string;
}

interface CreateMailboxModalProps {
  domainId?: string | number;
  domainName?: string;
  availableDomains?: DomainOption[];
  defaultQuotaMb?: number;
  onClose: () => void;
  onSuccess: () => void;
}

export default function CreateMailboxModal({
  domainId: initialDomainId,
  domainName: initialDomainName,
  availableDomains = [],
  defaultQuotaMb = 1024,
  onClose,
  onSuccess,
}: CreateMailboxModalProps) {
  const activeDomains = availableDomains.filter((d) => d.status === 'active');
  const [selectedDomainId, setSelectedDomainId] = useState<string | number>(
    initialDomainId || (activeDomains.length > 0 ? activeDomains[0].id : '')
  );

  const currentDomainName =
    initialDomainName ||
    activeDomains.find((d) => String(d.id) === String(selectedDomainId))?.domain_name ||
    '';

  const [localPart, setLocalPart] = useState('');
  const [displayName, setDisplayName] = useState('');
  const [password, setPassword] = useState('');
  const [passwordConfirmation, setPasswordConfirmation] = useState('');
  const [quotaMb, setQuotaMb] = useState<number>(defaultQuotaMb);
  const [isLoading, setIsLoading] = useState(false);
  const [errors, setErrors] = useState<Record<string, string[]>>({});

  const isDomainSelectable = !initialDomainId && availableDomains.length > 0;
  const hasNoActiveDomains = !initialDomainId && activeDomains.length === 0;

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setErrors({});

    const targetDomainId = initialDomainId || selectedDomainId;
    if (!targetDomainId) {
      setErrors({ general: ['Please select a verified domain for this mailbox.'] });
      return;
    }

    if (password.length < 8) {
      setErrors({ password: ['Password must be at least 8 characters long'] });
      return;
    }

    if (password !== passwordConfirmation) {
      setErrors({ password_confirmation: ['Passwords do not match'] });
      return;
    }

    setIsLoading(true);
    try {
      await api.post(`/domains/${targetDomainId}/mailboxes`, {
        local_part: localPart.trim(),
        display_name: displayName.trim() || null,
        password: password,
        quota_mb: quotaMb,
      });
      onSuccess();
    } catch (error: any) {
      if (error.response?.data?.errors) {
        setErrors(error.response.data.errors);
      } else if (error.response?.data?.message) {
        setErrors({ general: [error.response.data.message] });
      } else {
        setErrors({ general: ['Failed to create mailbox. Please check inputs and try again.'] });
      }
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
      <div className="bg-white dark:bg-gray-800 rounded-lg shadow-lg w-full max-w-md p-6 relative max-h-[90vh] overflow-y-auto">
        <button
          onClick={onClose}
          className="absolute top-4 right-4 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
        >
          ✕
        </button>
        <h2 className="text-xl font-semibold mb-4 text-gray-900 dark:text-white">Create Mailbox</h2>

        {hasNoActiveDomains ? (
          <div className="space-y-4">
            <div className="p-4 rounded-md bg-amber-50 border border-amber-200 text-amber-800 flex items-start gap-3">
              <AlertCircle className="h-5 w-5 mt-0.5 text-amber-600 flex-shrink-0" />
              <div>
                <h4 className="font-medium">No Verified Domains Available</h4>
                <p className="text-sm mt-1">
                  Mailboxes can only be created on active, DNS-verified domains. You currently have no active domains.
                </p>
              </div>
            </div>
            <div className="flex justify-end gap-3 pt-2">
              <Button variant="secondary" onClick={onClose}>
                Cancel
              </Button>
              <Link href="/domains" className={cn(buttonVariants({ variant: "default" }))}>
                Go to Domains
              </Link>
            </div>
          </div>
        ) : (
          <>
            {errors.general && (
              <div className="mb-4 text-sm text-red-600 bg-red-50 border border-red-200 p-3 rounded">
                {errors.general[0]}
              </div>
            )}

            <form onSubmit={handleSubmit} className="space-y-4">
              {isDomainSelectable && (
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Domain</label>
                  <select
                    value={selectedDomainId}
                    onChange={(e) => setSelectedDomainId(e.target.value)}
                    className="mt-1 block w-full rounded-md border border-gray-300 bg-white py-2 px-3 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-indigo-500 sm:text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                    required
                  >
                    {activeDomains.map((dom) => (
                      <option key={dom.id} value={dom.id}>
                        {dom.domain_name}
                      </option>
                    ))}
                  </select>
                </div>
              )}

              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Email Address</label>
                <div className="flex items-center mt-1">
                  <Input
                    type="text"
                    value={localPart}
                    onChange={(e) => setLocalPart(e.target.value)}
                    placeholder="sales"
                    className="rounded-r-none"
                    required
                  />
                  <span className="inline-flex items-center px-3 rounded-r-md border border-l-0 border-gray-300 bg-gray-50 text-gray-500 sm:text-sm h-10 select-none">
                    @{currentDomainName || 'domain.com'}
                  </span>
                </div>
                {errors.local_part && <p className="text-sm text-red-600 mt-1">{errors.local_part[0]}</p>}
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Display Name (Optional)</label>
                <Input
                  type="text"
                  value={displayName}
                  onChange={(e) => setDisplayName(e.target.value)}
                  placeholder="e.g. Sales Department"
                  className="mt-1"
                />
                {errors.display_name && <p className="text-sm text-red-600 mt-1">{errors.display_name[0]}</p>}
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Password (min 8 chars)</label>
                <Input
                  type="password"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  className="mt-1"
                  required
                  minLength={8}
                />
                {errors.password && <p className="text-sm text-red-600 mt-1">{errors.password[0]}</p>}
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Confirm Password</label>
                <Input
                  type="password"
                  value={passwordConfirmation}
                  onChange={(e) => setPasswordConfirmation(e.target.value)}
                  className="mt-1"
                  required
                  minLength={8}
                />
                {errors.password_confirmation && <p className="text-sm text-red-600 mt-1">{errors.password_confirmation[0]}</p>}
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Storage Quota (MB)</label>
                <Input
                  type="number"
                  value={quotaMb}
                  onChange={(e) => setQuotaMb(Number(e.target.value))}
                  className="mt-1"
                  min={1}
                  required
                />
                <p className="text-xs text-gray-500 mt-1">Default 1024 MB (1 GB). Subject to plan storage cap.</p>
                {errors.quota_mb && <p className="text-sm text-red-600 mt-1">{errors.quota_mb[0]}</p>}
              </div>

              <div className="flex justify-end space-x-3 mt-6 pt-2 border-t">
                <Button variant="secondary" type="button" onClick={onClose} disabled={isLoading}>
                  Cancel
                </Button>
                <Button type="submit" disabled={isLoading}>
                  {isLoading ? 'Creating...' : 'Create Mailbox'}
                </Button>
              </div>
            </form>
          </>
        )}
      </div>
    </div>
  );
}
