'use client';

import React, { useState } from 'react';
import api from '@/lib/api';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

interface EditMailboxModalProps {
  mailboxId: string | number;
  email: string;
  initialDisplayName?: string | null;
  onClose: () => void;
  onSuccess: () => void;
}

export default function EditMailboxModal({
  mailboxId,
  email,
  initialDisplayName = '',
  onClose,
  onSuccess,
}: EditMailboxModalProps) {
  const [displayName, setDisplayName] = useState(initialDisplayName || '');
  const [isLoading, setIsLoading] = useState(false);
  const [errors, setErrors] = useState<Record<string, string[]>>({});

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setErrors({});

    setIsLoading(true);
    try {
      await api.put(`/mailboxes/${mailboxId}`, {
        display_name: displayName,
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
          ✕
        </button>
        <h2 className="text-xl font-semibold mb-2 text-gray-900 dark:text-white">Edit Mailbox</h2>
        <p className="text-sm text-gray-600 dark:text-gray-400 mb-4">
          Mailbox: <span className="font-semibold text-gray-900 dark:text-white">{email}</span>
        </p>

        {errors.general && (
          <div className="mb-4 text-sm text-red-600 bg-red-50 p-2 rounded">
            {errors.general[0]}
          </div>
        )}

        <form onSubmit={handleSubmit} className="space-y-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Display Name</label>
            <Input
              type="text"
              value={displayName}
              onChange={(e) => setDisplayName(e.target.value)}
              placeholder="e.g. Sales Team or John Doe"
              className="mt-1"
            />
            {errors.display_name && <p className="text-sm text-red-600 mt-1">{errors.display_name[0]}</p>}
          </div>

          <div className="flex justify-end space-x-3 mt-6">
            <Button variant="secondary" type="button" onClick={onClose} disabled={isLoading}>
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
