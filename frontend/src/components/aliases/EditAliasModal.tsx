'use client';

import React, { useState } from 'react';
import { updateAlias } from '@/hooks/useAliases';
import { EmailAlias } from '@/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { AlertCircle, ArrowRight } from 'lucide-react';

interface EditAliasModalProps {
  alias: EmailAlias;
  onClose: () => void;
  onSuccess: () => void;
}

export default function EditAliasModal({
  alias,
  onClose,
  onSuccess,
}: EditAliasModalProps) {
  const [destinationEmail, setDestinationEmail] = useState(alias.destination_email);
  const [isActive, setIsActive] = useState(alias.is_active);
  const [isLoading, setIsLoading] = useState(false);
  const [errors, setErrors] = useState<Record<string, string[]>>({});

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setErrors({});

    if (!destinationEmail.trim()) {
      setErrors({ destination_email: ['Destination email is required.'] });
      return;
    }

    if (destinationEmail.trim().toLowerCase() === alias.source_email.toLowerCase()) {
      setErrors({ destination_email: ['Self-forwarding loop detected: destination cannot match the source email.'] });
      return;
    }

    setIsLoading(true);
    try {
      await updateAlias(alias.id, {
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
        setErrors({ general: ['Failed to update email alias. Please check inputs and try again.'] });
      }
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      <div className="bg-white dark:bg-gray-800 rounded-lg shadow-lg w-full max-w-md p-6 relative">
        <button
          onClick={onClose}
          className="absolute top-4 right-4 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
          type="button"
        >
          ✕
        </button>
        <h2 className="text-xl font-semibold mb-2 text-gray-900 dark:text-white">Edit Forwarding Destination</h2>
        <p className="text-sm text-gray-500 mb-4">
          Update the forwarding target email address for this alias.
        </p>

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

          {/* Source Address (Read-only) */}
          <div>
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
              Source Alias Address
            </label>
            <div className="p-2.5 bg-gray-50 dark:bg-gray-700/50 border border-gray-200 dark:border-gray-600 rounded-md font-mono text-sm text-gray-800 dark:text-gray-200 select-all">
              {alias.source_email}
            </div>
            <p className="text-xs text-gray-400 mt-1">
              Source addresses are permanent. To change the source address, delete this alias and create a new one.
            </p>
          </div>

          <div className="flex items-center justify-center my-1 text-gray-400">
            <span className="text-xs uppercase tracking-wider font-semibold mr-2">Forwards To</span>
            <ArrowRight className="h-4 w-4" />
          </div>

          {/* Destination Email */}
          <div>
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
              New Destination Email <span className="text-red-500">*</span>
            </label>
            <Input
              type="email"
              value={destinationEmail}
              onChange={(e) => setDestinationEmail(e.target.value)}
              placeholder="e.g. newteam@external.com"
              required
            />
            {errors.destination_email && (
              <p className="text-red-500 text-xs mt-1">{errors.destination_email.join(', ')}</p>
            )}
          </div>

          {/* Active status */}
          <div className="flex items-center gap-2 pt-1">
            <input
              type="checkbox"
              id="edit_is_active"
              checked={isActive}
              onChange={(e) => setIsActive(e.target.checked)}
              className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
            />
            <label htmlFor="edit_is_active" className="text-sm font-medium text-gray-700 dark:text-gray-300 cursor-pointer">
              Alias is active and forwarding
            </label>
          </div>

          <div className="flex justify-end gap-3 pt-4 border-t">
            <Button type="button" variant="secondary" onClick={onClose} disabled={isLoading}>
              Cancel
            </Button>
            <Button type="submit" disabled={isLoading}>
              {isLoading ? 'Saving...' : 'Save Changes'}
            </Button>
          </div>
        </form>
      </div>
    </div>
  );
}
