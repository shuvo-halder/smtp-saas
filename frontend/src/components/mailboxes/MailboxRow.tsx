'use client';

import React, { useState } from 'react';
import api from '@/lib/api';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import ChangePasswordModal from './ChangePasswordModal';

interface MailboxRowProps {
  mailbox: any;
  onRefresh: () => void;
}

export default function MailboxRow({ mailbox, onRefresh }: MailboxRowProps) {
  const [isChangingPassword, setIsChangingPassword] = useState(false);
  const [isToggling, setIsToggling] = useState(false);
  const [isDeleting, setIsDeleting] = useState(false);

  const handleToggle = async () => {
    setIsToggling(true);
    try {
      await api.post(`/api/mailboxes/${mailbox.id}/toggle`);
      onRefresh();
    } catch (error) {
      console.error('Failed to toggle mailbox status', error);
    } finally {
      setIsToggling(false);
    }
  };

  const handleDelete = async () => {
    if (!window.confirm('Are you sure you want to delete this mailbox? This cannot be undone.')) return;
    
    setIsDeleting(true);
    try {
      await api.delete(`/api/mailboxes/${mailbox.id}`);
      onRefresh();
    } catch (error) {
      console.error('Failed to delete mailbox', error);
    } finally {
      setIsDeleting(false);
    }
  };

  return (
    <>
      <tr className="border-b border-gray-200 dark:border-gray-700">
        <td className="py-4 px-6 whitespace-nowrap">
          <div className="font-semibold text-gray-900 dark:text-white">{mailbox.email}</div>
        </td>
        <td className="py-4 px-6 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
          {mailbox.display_name || '-'}
        </td>
        <td className="py-4 px-6 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
          {mailbox.quota_formatted || `${mailbox.quota_mb} MB`}
        </td>
        <td className="py-4 px-6 whitespace-nowrap">
          <Badge variant={mailbox.is_active ? 'success' : 'secondary'}>
            {mailbox.is_active ? 'Active' : 'Inactive'}
          </Badge>
        </td>
        <td className="py-4 px-6 whitespace-nowrap text-sm">
          {mailbox.webmail_url && (
            <a 
              href={mailbox.webmail_url} 
              target="_blank" 
              rel="noopener noreferrer"
              className="text-blue-600 hover:text-blue-800 dark:text-blue-400 flex items-center gap-1"
            >
              Webmail ↗
            </a>
          )}
        </td>
        <td className="py-4 px-6 whitespace-nowrap text-right text-sm font-medium space-x-2">
          <Button 
            variant="outline" 
            size="sm" 
            onClick={handleToggle}
            disabled={isToggling}
          >
            {mailbox.is_active ? 'Deactivate' : 'Activate'}
          </Button>
          <Button 
            variant="outline" 
            size="sm"
            onClick={() => setIsChangingPassword(true)}
          >
            Password
          </Button>
          <Button 
            variant="destructive" 
            size="sm"
            onClick={handleDelete}
            disabled={isDeleting}
          >
            Delete
          </Button>
        </td>
      </tr>

      {isChangingPassword && (
        <ChangePasswordModal
          mailboxId={mailbox.id}
          email={mailbox.email}
          onClose={() => setIsChangingPassword(false)}
          onSuccess={() => {
            setIsChangingPassword(false);
            onRefresh();
          }}
        />
      )}
    </>
  );
}
