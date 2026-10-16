'use client';

import React, { useState } from 'react';
import Link from 'next/link';
import api from '@/lib/api';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import ChangePasswordModal from './ChangePasswordModal';
import EditMailboxModal from './EditMailboxModal';
import MailboxStorageModal from './MailboxStorageModal';
import MailboxRulesModal from './MailboxRulesModal';
import { Mailbox } from '@/types';
import { ExternalLink, Edit2, Key, Power, Trash2, HardDrive, SlidersHorizontal } from 'lucide-react';
import { cn } from '@/lib/utils';

interface MailboxRowProps {
  mailbox: Mailbox;
  showDomain?: boolean;
  onRefresh: () => void;
}

export default function MailboxRow({ mailbox, showDomain = false, onRefresh }: MailboxRowProps) {
  const [isChangingPassword, setIsChangingPassword] = useState(false);
  const [isEditing, setIsEditing] = useState(false);
  const [isViewingStorage, setIsViewingStorage] = useState(false);
  const [isViewingRules, setIsViewingRules] = useState(false);
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
          <div className="flex items-center gap-2">
            <div className="font-medium text-gray-900">{mailbox.email}</div>
            {mailbox.is_forwarding_enabled && (
              <Badge variant="outline" className="text-[10px] px-1.5 py-0 bg-blue-50 text-blue-700 border-blue-200 font-normal">
                Fwd
              </Badge>
            )}
            {mailbox.auto_reply_enabled && (
              <Badge variant="outline" className="text-[10px] px-1.5 py-0 bg-purple-50 text-purple-700 border-purple-200 font-normal">
                Auto-Reply
              </Badge>
            )}
          </div>
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
          <div
            onClick={() => mailbox.storage && setIsViewingStorage(true)}
            className={cn(
              "flex flex-col gap-1 max-w-[170px]",
              mailbox.storage && "cursor-pointer group"
            )}
            title={mailbox.storage?.warning || 'Click to inspect detailed storage telemetry'}
          >
            <div className="flex items-center justify-between text-xs">
              <span className="font-medium text-gray-700 group-hover:text-indigo-600 transition-colors">
                {mailbox.storage?.is_measured
                  ? `${mailbox.storage.usage_formatted} / ${mailbox.storage.quota_formatted}`
                  : (mailbox.quota_formatted || `${mailbox.quota_mb} MB`)}
              </span>
              {mailbox.storage?.is_measured && mailbox.storage.utilization_percent !== null ? (
                <span
                  className={cn(
                    "font-semibold text-[11px]",
                    mailbox.storage.status === 'over_limit' ? "text-red-600" :
                    mailbox.storage.status === 'near_limit' ? "text-orange-600" :
                    mailbox.storage.status === 'warning' ? "text-amber-600" : "text-emerald-600"
                  )}
                >
                  {mailbox.storage.utilization_percent}%
                </span>
              ) : (
                <span className="text-[10px] bg-gray-100 text-gray-500 px-1.5 py-0.5 rounded border border-gray-200">
                  Unmeasured
                </span>
              )}
            </div>

            {mailbox.storage?.is_measured && mailbox.storage.utilization_percent !== null ? (
              <div className="w-full bg-gray-200 rounded-full h-1.5 overflow-hidden">
                <div
                  className={cn(
                    "h-full rounded-full transition-all",
                    mailbox.storage.status === 'over_limit' ? "bg-red-500" :
                    mailbox.storage.status === 'near_limit' ? "bg-orange-500" :
                    mailbox.storage.status === 'warning' ? "bg-amber-500" : "bg-emerald-500"
                  )}
                  style={{ width: `${Math.min(mailbox.storage.utilization_percent, 100)}%` }}
                />
              </div>
            ) : (
              <div className="text-[10px] text-gray-400 italic">
                Dovecot unmeasured
              </div>
            )}
          </div>
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
          {mailbox.storage && (
            <Button
              variant="ghost"
              size="sm"
              onClick={() => setIsViewingStorage(true)}
              title="Inspect storage telemetry"
              className="h-8 px-2 text-gray-500 hover:text-indigo-600"
            >
              <HardDrive className="h-3.5 w-3.5" />
            </Button>
          )}

          <Button
            variant="ghost"
            size="sm"
            onClick={() => setIsViewingRules(true)}
            title="Forwarding & Auto-Reply Rules"
            className="h-8 px-2 text-gray-500 hover:text-indigo-600"
          >
            <SlidersHorizontal className="h-3.5 w-3.5" />
          </Button>

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

      {isViewingRules && (
        <MailboxRulesModal
          mailboxId={mailbox.id}
          email={mailbox.email}
          onClose={() => setIsViewingRules(false)}
          onSuccess={() => {
            setIsViewingRules(false);
            onRefresh();
          }}
        />
      )}

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

      {isViewingStorage && mailbox.storage && (
        <MailboxStorageModal
          storage={mailbox.storage}
          onClose={() => setIsViewingStorage(false)}
        />
      )}
    </>
  );
}
