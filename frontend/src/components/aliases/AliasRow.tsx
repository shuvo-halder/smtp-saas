'use client';

import React, { useState } from 'react';
import { toggleAlias, deleteAlias } from '@/hooks/useAliases';
import { EmailAlias } from '@/types';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import EditAliasModal from './EditAliasModal';
import { ArrowRight, Edit2, Power, Trash2 } from 'lucide-react';

interface AliasRowProps {
  alias: EmailAlias;
  onRefresh: () => void;
}

export default function AliasRow({ alias, onRefresh }: AliasRowProps) {
  const [isEditing, setIsEditing] = useState(false);
  const [isToggling, setIsToggling] = useState(false);
  const [isDeleting, setIsDeleting] = useState(false);

  const handleToggle = async () => {
    setIsToggling(true);
    try {
      await toggleAlias(alias.id);
      onRefresh();
    } catch (error: any) {
      const message = error.response?.data?.message || 'Failed to toggle alias status';
      alert(message);
    } finally {
      setIsToggling(false);
    }
  };

  const handleDelete = async () => {
    if (
      !window.confirm(
        `Are you sure you want to permanently delete alias "${alias.source_email}"? Inbound emails sent to this address will no longer be forwarded.`
      )
    ) {
      return;
    }

    setIsDeleting(true);
    try {
      await deleteAlias(alias.id);
      onRefresh();
    } catch (error: any) {
      const message = error.response?.data?.message || 'Failed to delete alias';
      alert(message);
    } finally {
      setIsDeleting(false);
    }
  };

  return (
    <>
      <tr className="border-b border-gray-200 hover:bg-gray-50/50 transition-colors">
        {/* Source Email */}
        <td className="py-3 px-4 whitespace-nowrap">
          <div className="font-semibold text-gray-900 dark:text-gray-100 flex items-center gap-1.5">
            <span>{alias.source_email}</span>
          </div>
          <div className="text-xs text-gray-500 mt-0.5">
            Domain: {alias.domain_name || 'N/A'}
          </div>
        </td>

        {/* Arrow & Destination */}
        <td className="py-3 px-4 whitespace-nowrap">
          <div className="flex items-center gap-2">
            <ArrowRight className="h-4 w-4 text-gray-400 flex-shrink-0" />
            <span className="font-medium text-gray-800 dark:text-gray-200">{alias.destination_email}</span>
          </div>
        </td>

        {/* Forwarding Status */}
        <td className="py-3 px-4 whitespace-nowrap">
          <Badge variant="outline" className="bg-blue-50 text-blue-700 border-blue-200 text-xs font-normal">
            Configured
          </Badge>
        </td>

        {/* Active Status */}
        <td className="py-3 px-4 whitespace-nowrap">
          {alias.is_active ? (
            <Badge className="bg-emerald-100 text-emerald-800 hover:bg-emerald-100 border-emerald-200">
              Active
            </Badge>
          ) : (
            <Badge variant="secondary" className="bg-gray-100 text-gray-600 border-gray-200">
              Disabled
            </Badge>
          )}
        </td>

        {/* Created At */}
        <td className="py-3 px-4 whitespace-nowrap text-xs text-gray-500">
          {new Date(alias.created_at).toLocaleDateString(undefined, {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
          })}
        </td>

        {/* Actions */}
        <td className="py-3 px-4 whitespace-nowrap text-right space-x-1">
          <Button
            variant="ghost"
            size="sm"
            onClick={handleToggle}
            disabled={isToggling}
            title={alias.is_active ? 'Disable alias' : 'Enable alias'}
            className={alias.is_active ? 'text-gray-600 hover:text-amber-600' : 'text-gray-600 hover:text-emerald-600'}
          >
            <Power className="h-4 w-4" />
          </Button>

          <Button
            variant="ghost"
            size="sm"
            onClick={() => setIsEditing(true)}
            title="Edit destination"
            className="text-gray-600 hover:text-indigo-600"
          >
            <Edit2 className="h-4 w-4" />
          </Button>

          <Button
            variant="ghost"
            size="sm"
            onClick={handleDelete}
            disabled={isDeleting}
            title="Delete alias"
            className="text-gray-600 hover:text-red-600"
          >
            <Trash2 className="h-4 w-4" />
          </Button>
        </td>
      </tr>

      {isEditing && (
        <EditAliasModal
          alias={alias}
          onClose={() => setIsEditing(false)}
          onSuccess={() => {
            setIsEditing(false);
            onRefresh();
          }}
        />
      )}
    </>
  );
}
