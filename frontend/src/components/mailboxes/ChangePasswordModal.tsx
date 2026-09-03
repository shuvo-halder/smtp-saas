'use client';

import React, { useState } from 'react';
import api from '@/lib/api';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

interface ChangePasswordModalProps {
  mailboxId: string | number;
  email: string;
  onClose: () => void;
  onSuccess: () => void;
}

export default function ChangePasswordModal({
  mailboxId,
  email,
  onClose,
  onSuccess,
}: ChangePasswordModalProps) {
  const [password, setPassword] = useState('');
  const [passwordConfirmation, setPasswordConfirmation] = useState('');
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
      await api.post(`/api/mailboxes/${mailboxId}/change-password`, {
        password: password,
        password_confirmation: passwordConfirmation,
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
        <h2 className="text-xl font-semibold mb-2 text-gray-900 dark:text-white">Change Password</h2>
        <p className="text-sm text-gray-600 dark:text-gray-400 mb-4">
          For mailbox: <span className="font-semibold">{email}</span>
        </p>
        
        {errors.general && (
          <div className="mb-4 text-sm text-red-600 bg-red-50 p-2 rounded">
            {errors.general[0]}
          </div>
        )}

        <form onSubmit={handleSubmit} className="space-y-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">New Password</label>
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
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Confirm New Password</label>
            <Input
              type="password"
              value={passwordConfirmation}
              onChange={(e) => setPasswordConfirmation(e.target.value)}
              className="mt-1"
              required
            />
            {errors.password_confirmation && <p className="text-sm text-red-600 mt-1">{errors.password_confirmation[0]}</p>}
          </div>

          <div className="flex justify-end space-x-3 mt-6">
            <Button variant="secondary" type="button" onClick={onClose} disabled={isLoading}>
              Cancel
            </Button>
            <Button type="submit" disabled={isLoading}>
              {isLoading ? 'Changing...' : 'Change Password'}
            </Button>
          </div>
        </form>
      </div>
    </div>
  );
}
