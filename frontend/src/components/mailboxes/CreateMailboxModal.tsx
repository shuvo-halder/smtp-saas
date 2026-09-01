'use client';

import React, { useState } from 'react';
import api from '@/lib/api';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';

interface CreateMailboxModalProps {
  domainId: string | number;
  domainName: string;
  defaultQuotaMb?: number;
  onClose: () => void;
  onSuccess: () => void;
}

export default function CreateMailboxModal({
  domainId,
  domainName,
  defaultQuotaMb = 1024,
  onClose,
  onSuccess,
}: CreateMailboxModalProps) {
  const [localPart, setLocalPart] = useState('');
  const [displayName, setDisplayName] = useState('');
  const [password, setPassword] = useState('');
  const [passwordConfirmation, setPasswordConfirmation] = useState('');
  const [quotaMb, setQuotaMb] = useState<number>(defaultQuotaMb);
  const [isLoading, setIsLoading] = useState(false);
  const [errors, setErrors] = useState<Record<string, string[]>>({});

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setErrors({});
    
    if (password !== passwordConfirmation) {
      setErrors({ password_confirmation: ['Passwords do not match'] });
      return;
    }

    setIsLoading(true);
    try {
      await api.post(`/api/domains/${domainId}/mailboxes`, {
        local_part: localPart,
        display_name: displayName,
        password: password,
        password_confirmation: passwordConfirmation,
        quota_mb: quotaMb,
      });
      onSuccess();
    } catch (error: any) {
      if (error.response?.data?.errors) {
        setErrors(error.response.data.errors);
      } else if (error.response?.data?.message) {
        setErrors({ general: [error.response.data.message] });
      }
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
      <div className="bg-white dark:bg-gray-800 rounded-lg shadow-lg w-full max-w-md p-6 relative">
        <button
          onClick={onClose}
          className="absolute top-4 right-4 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
        >
          X
        </button>
        <h2 className="text-xl font-semibold mb-4 text-gray-900 dark:text-white">Create Mailbox</h2>
        
        {errors.general && (
          <div className="mb-4 text-sm text-red-600 bg-red-50 p-2 rounded">
            {errors.general[0]}
          </div>
        )}

        <form onSubmit={handleSubmit} className="space-y-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Email Address</label>
            <div className="flex items-center mt-1">
              <Input
                type="text"
                value={localPart}
                onChange={(e) => setLocalPart(e.target.value)}
                placeholder="info"
                className="rounded-r-none"
                required
              />
              <span className="inline-flex items-center px-3 rounded-r-md border border-l-0 border-gray-300 bg-gray-50 text-gray-500 sm:text-sm h-10">
                @{domainName}
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
              placeholder="e.g. Info Desk"
              className="mt-1"
            />
            {errors.display_name && <p className="text-sm text-red-600 mt-1">{errors.display_name[0]}</p>}
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Password</label>
            <Input
              type="password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              className="mt-1"
              required
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
            />
            {errors.password_confirmation && <p className="text-sm text-red-600 mt-1">{errors.password_confirmation[0]}</p>}
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Quota (MB)</label>
            <Input
              type="number"
              value={quotaMb}
              onChange={(e) => setQuotaMb(Number(e.target.value))}
              className="mt-1"
              required
            />
            {errors.quota_mb && <p className="text-sm text-red-600 mt-1">{errors.quota_mb[0]}</p>}
          </div>

          <div className="flex justify-end space-x-3 mt-6">
            <Button variant="secondary" type="button" onClick={onClose} disabled={isLoading}>
              Cancel
            </Button>
            <Button type="submit" disabled={isLoading}>
              {isLoading ? 'Creating...' : 'Create Mailbox'}
            </Button>
          </div>
        </form>
      </div>
    </div>
  );
}
