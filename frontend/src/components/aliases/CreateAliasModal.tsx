'use client';

import React, { useState } from 'react';
import Link from 'next/link';
import { createAlias } from '@/hooks/useAliases';
import { Button, buttonVariants } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { AlertCircle, ArrowRight, Info } from 'lucide-react';
import { cn } from '@/lib/utils';

interface DomainOption {
  id: number;
  domain_name: string;
  status: string;
}

interface CreateAliasModalProps {
  availableDomains?: DomainOption[];
  initialDomainId?: string | number;
  onClose: () => void;
  onSuccess: () => void;
}

export default function CreateAliasModal({
  availableDomains = [],
  initialDomainId,
  onClose,
  onSuccess,
}: CreateAliasModalProps) {
  const activeDomains = availableDomains.filter((d) => d.status === 'active');
  const [selectedDomainId, setSelectedDomainId] = useState<string | number>(
    initialDomainId || (activeDomains.length > 0 ? activeDomains[0].id : '')
  );

  const selectedDomain = activeDomains.find((d) => String(d.id) === String(selectedDomainId));
  const currentDomainName = selectedDomain?.domain_name || '';

  const [localPart, setLocalPart] = useState('');
  const [destinationEmail, setDestinationEmail] = useState('');
  const [isActive, setIsActive] = useState(true);
  const [isLoading, setIsLoading] = useState(false);
  const [errors, setErrors] = useState<Record<string, string[]>>({});

  const hasNoActiveDomains = activeDomains.length === 0;

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setErrors({});

    const targetDomainId = Number(selectedDomainId);
    if (!targetDomainId) {
      setErrors({ general: ['Please select a verified domain for this alias.'] });
      return;
    }

    if (!localPart.trim()) {
      setErrors({ local_part: ['Alias username / local-part is required.'] });
      return;
    }

    if (!destinationEmail.trim()) {
      setErrors({ destination_email: ['Forwarding destination email is required.'] });
      return;
    }

    // Direct client-side self-forward check
    const constructedSource = `${localPart.trim().toLowerCase()}@${currentDomainName.toLowerCase()}`;
    if (constructedSource === destinationEmail.trim().toLowerCase()) {
      setErrors({ destination_email: ['Self-forwarding loop detected: destination cannot match the source email.'] });
      return;
    }

    setIsLoading(true);
    try {
      await createAlias({
        domain_id: targetDomainId,
        local_part: localPart.trim().toLowerCase(),
        destination_email: destinationEmail.trim().toLowerCase(),
        is_active: isActive,
      });
      onSuccess();
    } catch (error: any) {
      if (error.response?.data?.errors) {
        setErrors(error.response.data.errors);
      } else if (error.response?.data?.message) {
        setErrors({ general: [error.response.data.message] });
      } else {
        setErrors({ general: ['Failed to create email alias. Please check inputs and try again.'] });
      }
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      <div className="bg-white dark:bg-gray-800 rounded-lg shadow-lg w-full max-w-lg p-6 relative max-h-[90vh] overflow-y-auto">
        <button
          onClick={onClose}
          className="absolute top-4 right-4 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
          type="button"
        >
          ✕
        </button>
        <h2 className="text-xl font-semibold mb-2 text-gray-900 dark:text-white">Create Email Alias</h2>
        <p className="text-sm text-gray-500 mb-4">
          Route inbound messages to an external or internal mailbox address.
        </p>

        {hasNoActiveDomains ? (
          <div className="space-y-4">
            <div className="p-4 rounded-md bg-amber-50 border border-amber-200 text-amber-800 flex items-start gap-3">
              <AlertCircle className="h-5 w-5 mt-0.5 text-amber-600 flex-shrink-0" />
              <div>
                <h4 className="font-medium">No Verified Domains Available</h4>
                <p className="text-sm mt-1">
                  Email aliases can only be created on active, DNS-verified domains. Please verify your domain DNS records first.
                </p>
              </div>
            </div>
            <div className="flex justify-end gap-3 pt-2">
              <Button variant="secondary" onClick={onClose}>
                Cancel
              </Button>
              <Link href="/domains" className={cn(buttonVariants({ variant: 'default' }))}>
                View Domains
              </Link>
            </div>
          </div>
        ) : (
          <form onSubmit={handleSubmit} className="space-y-4">
            {errors.general && (
              <div className="p-3 rounded bg-red-50 border border-red-200 text-red-700 text-sm flex items-start gap-2">
                <AlertCircle className="h-4 w-4 mt-0.5 flex-shrink-0" />
                <div>
                  {errors.general.map((err, i) => (
                    <div key={i}>{err}</div>
                  ))}
                </div>
              </div>
            )}

            {/* Domain Selection */}
            <div>
              <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                Domain <span className="text-red-500">*</span>
              </label>
              <select
                value={selectedDomainId}
                onChange={(e) => setSelectedDomainId(e.target.value)}
                className="w-full rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 py-2 px-3 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
              >
                {activeDomains.map((dom) => (
                  <option key={dom.id} value={dom.id}>
                    {dom.domain_name}
                  </option>
                ))}
              </select>
            </div>

            {/* Source Local-part */}
            <div>
              <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                Alias Address (Source) <span className="text-red-500">*</span>
              </label>
              <div className="flex rounded-md shadow-sm">
                <Input
                  type="text"
                  placeholder="e.g. sales, support, info"
                  value={localPart}
                  onChange={(e) => setLocalPart(e.target.value.toLowerCase().replace(/[^a-z0-9._-]/g, ''))}
                  className="rounded-r-none focus:z-10"
                  required
                />
                <span className="inline-flex items-center px-3 rounded-r-md border border-l-0 border-gray-300 bg-gray-50 text-gray-500 text-sm select-none">
                  @{currentDomainName || 'example.com'}
                </span>
              </div>
              {errors.local_part && (
                <p className="text-red-500 text-xs mt-1">{errors.local_part.join(', ')}</p>
              )}
            </div>

            {/* Arrow Divider */}
            <div className="flex items-center justify-center my-1 text-gray-400">
              <span className="text-xs uppercase tracking-wider font-semibold mr-2">Forwards To</span>
              <ArrowRight className="h-4 w-4" />
            </div>

            {/* Forwarding Destination */}
            <div>
              <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                Destination Email <span className="text-red-500">*</span>
              </label>
              <Input
                type="email"
                placeholder="e.g. personal@gmail.com, team@company.com"
                value={destinationEmail}
                onChange={(e) => setDestinationEmail(e.target.value)}
                required
              />
              {errors.destination_email && (
                <p className="text-red-500 text-xs mt-1">{errors.destination_email.join(', ')}</p>
              )}
              <p className="text-xs text-gray-500 mt-1">
                Emails sent to {localPart || 'alias'}@{currentDomainName || 'domain'} will automatically forward here.
              </p>
            </div>

            {/* Active Toggle */}
            <div className="flex items-center gap-2 pt-1">
              <input
                type="checkbox"
                id="is_active"
                checked={isActive}
                onChange={(e) => setIsActive(e.target.checked)}
                className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
              />
              <label htmlFor="is_active" className="text-sm font-medium text-gray-700 dark:text-gray-300 cursor-pointer">
                Enable alias immediately upon creation
              </label>
            </div>

            {/* Architecture Info Notice */}
            <div className="p-3 bg-blue-50 border border-blue-200 rounded-md text-blue-800 text-xs space-y-1">
              <div className="flex items-center gap-1.5 font-medium">
                <Info className="h-4 w-4 text-blue-600" />
                Forwarding & Postfix Status
              </div>
              <p>
                Aliases update database virtual alias maps immediately. Inbound live routing requires your domain MX records to point to the mail server.
              </p>
            </div>

            {/* Modal Actions */}
            <div className="flex justify-end gap-3 pt-4 border-t">
              <Button type="button" variant="secondary" onClick={onClose} disabled={isLoading}>
                Cancel
              </Button>
              <Button type="submit" disabled={isLoading}>
                {isLoading ? 'Creating Alias...' : 'Create Alias'}
              </Button>
            </div>
          </form>
        )}
      </div>
    </div>
  );
}
