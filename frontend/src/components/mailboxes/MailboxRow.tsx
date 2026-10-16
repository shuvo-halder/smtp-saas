'use client';

import React, { useState } from 'react';
import Link from 'next/link';
import api from '@/lib/api';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import ChangePasswordModal from './ChangePasswordModal';
import EditMailboxModal from './EditMailboxModal';
import { Mailbox } from '@/types';
import { ExternalLink, Edit2, Key, Power, Trash2 } from 'lucide-react';

interface MailboxRowProps {
  mailbox: Mailbox;
  showDomain?: boolean;
  onRefresh: () => void;
}

export default function MailboxRow({ mailbox, showDomain = false, onRefresh }: MailboxRowProps) {
  const [isChangingPassword, setIsChangingPassword] = useState(false);
  const [isEditing, setIsEditing] = useState(false);
  const [isToggling, setIsToggling] = useState(false);
  const [isDeleting, setIsDeleting] = useState(false);

  const handleToggle = async () => {
    setIsToggling(true);
    try {
      await api.post(`/mailboxes/${mailbox.id}/toggle`);
      onRefresh();
    } catch (error: any) {
      const message = error.response?.data?.message || 'Failed to toggle mailbox status';
      alert(message);
    } finally {
      setIsToggling(false);
    }
  };

  const handleDelete = async () => {
    if (!window.confirm(`Are you sure you want to permanently delete mailbox "${mailbox.email}"? All stored emails and configuration will be deleted. This action cannot be undone.`)) {
      return;
    }

    setIsDeleting(true);
    try {
      await api.delete(`/mailboxes/${mailbox.id}`);
      onRefresh();
    } catch (error: any) {
      const message = error.response?.data?.message || 'Failed to delete mailbox';
      alert(message);
    } finally {
      setIsDeleting(false);
    }
  };

  return (
    <>
      <tr className="border-b border-gray-200 hover:bg-gray-50/50 transition-colors">
        <td className="py-3 px-4 whitespace-nowrap">
          <div className="font-medium text-gray-900">{mailbox.email}</div>
          {mailbox.display_name && (
            <div className="text-xs text-gray-500">{mailbox.display_name}</div>
          )}
        </td>

        {showDomain && (
          <td className="py-3 px-4 whitespace-nowrap text-sm text-gray-600">
            {mailbox.domain_id ? (
              <Link
                href={`/domains/${mailbox.domain_id}`}
                className="text-indigo-600 hover:underline inline-flex items-center gap-1 font-medium"
              >
                {mailbox.domain_name || `Domain #${mailbox.domain_id}`}
              </Link>
            ) : (
              <span>{mailbox.domain_name || '-'}</span>
            )}
          </td>
        )}

        <td className="py-3 px-4 whitespace-nowrap text-sm text-gray-600">
          <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-800">
            {mailbox.quota_formatted || `${mailbox.quota_mb} MB`}
          </span>
        </td>

        <td className="py-3 px-4 whitespace-nowrap">
          <Badge variant={mailbox.is_active ? 'success' : 'secondary'}>
            {mailbox.is_active ? 'Active' : 'Disabled'}
          </Badge>
        </td>

        <td className="py-3 px-4 whitespace-nowrap text-sm text-gray-500">
          {mailbox.last_login_at
            ? new Date(mailbox.last_login_at).toLocaleDateString()
            : 'Never'}
        </td>

        <td className="py-3 px-4 whitespace-nowrap text-sm">
          {mailbox.webmail_url ? (
            <a
              href={mailbox.webmail_url}
              target="_blank"
              rel="noopener noreferrer"
              className="text-indigo-600 hover:text-indigo-900 inline-flex items-center gap-1 font-medium"
              title="Open Webmail"
            >
              Webmail <ExternalLink className="h-3 w-3" />
            </a>
          ) : (
            <span className="text-gray-400">-</span>
          )}
        </td>

        <td className="py-3 px-4 whitespace-nowrap text-right text-sm font-medium space-x-1">
          <Button
            variant="ghost"
            size="sm"
            onClick={() => setIsEditing(true)}
            title="Edit display name"
            className="h-8 px-2"
          >
            <Edit2 className="h-3.5 w-3.5" />
          </Button>

          <Button
            variant="ghost"
            size="sm"
            onClick={() => setIsChangingPassword(true)}
            title="Change password"
            className="h-8 px-2"
          >
            <Key className="h-3.5 w-3.5" />
          </Button>

          <Button
            variant={mailbox.is_active ? 'outline' : 'secondary'}
            size="sm"
            onClick={handleToggle}
            disabled={isToggling}
            title={mailbox.is_active ? 'Deactivate mailbox' : 'Activate mailbox'}
            className="h-8 px-2.5"
          >
            <Power className="h-3.5 w-3.5 mr-1" />
            {mailbox.is_active ? 'Disable' : 'Enable'}
          </Button>

          <Button
            variant="ghost"
            size="sm"
            onClick={handleDelete}
            disabled={isDeleting}
            title="Delete mailbox"
            className="h-8 px-2 text-red-600 hover:text-red-800 hover:bg-red-50"
          >
            <Trash2 className="h-3.5 w-3.5" />
          </Button>
        </td>
      </tr>

      {isEditing && (
        <EditMailboxModal
          mailboxId={mailbox.id}
          email={mailbox.email}
          initialDisplayName={mailbox.display_name}
          onClose={() => setIsEditing(false)}
          onSuccess={() => {
            setIsEditing(false);
            onRefresh();
          }}
        />
      )}

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
