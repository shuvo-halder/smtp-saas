'use client';

import React from 'react';
import { MailboxStorageDetails } from '@/types';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { HardDrive, AlertTriangle, CheckCircle, Info, X } from 'lucide-react';
import { cn } from '@/lib/utils';

interface MailboxStorageModalProps {
  storage: MailboxStorageDetails;
  onClose: () => void;
}

export default function MailboxStorageModal({ storage, onClose }: MailboxStorageModalProps) {
  const getStatusBadge = () => {
    switch (storage.status) {
      case 'over_limit':
        return <Badge variant="destructive" className="bg-red-600">Over Quota Limit</Badge>;
      case 'near_limit':
        return <Badge variant="outline" className="text-orange-600 border-orange-300 bg-orange-50">Near Limit (90%+)</Badge>;
      case 'warning':
        return <Badge variant="outline" className="text-amber-600 border-amber-300 bg-amber-50">Warning (80%+)</Badge>;
      case 'normal':
        return <Badge variant="success">Normal Usage</Badge>;
      default:
        return <Badge variant="secondary" className="bg-gray-100 text-gray-700">Telemetry Unmeasured</Badge>;
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      <div className="bg-white rounded-lg shadow-xl max-w-md w-full overflow-hidden border">
        {/* Modal Header */}
        <div className="flex items-center justify-between p-4 border-b bg-gray-50">
          <div className="flex items-center gap-2">
            <div className="p-2 rounded-md bg-indigo-50 text-indigo-600">
              <HardDrive className="h-5 w-5" />
            </div>
            <div>
              <h3 className="font-semibold text-gray-900">Storage Telemetry</h3>
              <p className="text-xs text-gray-500 font-mono">{storage.email}</p>
            </div>
          </div>
          <button
            onClick={onClose}
            className="text-gray-400 hover:text-gray-600 p-1 rounded-md"
          >
            <X className="h-5 w-5" />
          </button>
        </div>

        {/* Modal Body */}
        <div className="p-6 space-y-5">
          {/* Status & Utilization */}
          <div className="bg-gray-50 p-4 rounded-lg border flex items-center justify-between">
            <div>
              <div className="text-xs text-gray-500 font-medium">Current Status</div>
              <div className="mt-1">{getStatusBadge()}</div>
            </div>
            <div className="text-right">
              <div className="text-xs text-gray-500 font-medium">Utilization</div>
              <div className="text-lg font-bold text-gray-900 mt-0.5">
                {storage.is_measured && storage.utilization_percent !== null
                  ? `${storage.utilization_percent}%`
                  : 'Unmeasured'}
              </div>
            </div>
          </div>

          {/* Progress Bar (Only when genuinely measured) */}
          {storage.is_measured && storage.utilization_percent !== null && (
            <div className="space-y-1.5">
              <div className="flex justify-between text-xs text-gray-600">
                <span>{storage.usage_formatted} used</span>
                <span>{storage.quota_formatted} limit</span>
              </div>
              <div className="w-full bg-gray-200 rounded-full h-2.5 overflow-hidden">
                <div
                  className={cn(
                    "h-full rounded-full transition-all",
                    storage.status === 'over_limit' ? "bg-red-500" :
                    storage.status === 'near_limit' ? "bg-orange-500" :
                    storage.status === 'warning' ? "bg-amber-500" : "bg-emerald-500"
                  )}
                  style={{ width: `${Math.min(storage.utilization_percent, 100)}%` }}
                />
              </div>
            </div>
          )}

          {/* Details Grid */}
          <div className="grid grid-cols-2 gap-3 text-sm">
            <div className="p-3 bg-gray-50 rounded border">
              <span className="text-xs text-gray-500 block">Configured Quota</span>
              <span className="font-semibold text-gray-800">{storage.quota_formatted}</span>
            </div>
            <div className="p-3 bg-gray-50 rounded border">
              <span className="text-xs text-gray-500 block">Plan Ceiling Limit</span>
              <span className="font-semibold text-gray-800">{storage.plan_limit_formatted || `${storage.plan_limit_mb} MB`}</span>
            </div>
            <div className="p-3 bg-gray-50 rounded border">
              <span className="text-xs text-gray-500 block">Measured Usage</span>
              <span className="font-semibold text-gray-800">
                {storage.is_measured ? storage.usage_formatted : 'Unavailable'}
              </span>
            </div>
            <div className="p-3 bg-gray-50 rounded border">
              <span className="text-xs text-gray-500 block">Remaining Capacity</span>
              <span className="font-semibold text-gray-800">
                {storage.is_measured ? storage.remaining_formatted : 'Unavailable'}
              </span>
            </div>
          </div>

          {/* Warning / Environment Notice */}
          {storage.warning && (
            <div className="p-3 bg-amber-50 border border-amber-200 rounded-md text-amber-800 text-xs flex items-start gap-2">
              <AlertTriangle className="h-4 w-4 text-amber-600 flex-shrink-0 mt-0.5" />
              <div>
                <span className="font-medium">Notice:</span> {storage.warning}
              </div>
            </div>
          )}

          {/* Measurement Provenance */}
          <div className="text-xs text-gray-400 space-y-1 border-t pt-3">
            <div className="flex justify-between">
              <span>Telemetry Source:</span>
              <span className="font-mono text-gray-600 uppercase">{storage.measurement_source}</span>
            </div>
            {storage.measured_at && (
              <div className="flex justify-between">
                <span>Last Probe:</span>
                <span className="text-gray-600">{new Date(storage.measured_at).toLocaleString()}</span>
              </div>
            )}
            <div className="flex justify-between">
              <span>MTA Quota Enforcement:</span>
              <span className="text-emerald-700 font-medium">Dovecot SQL Quota Rule</span>
            </div>
          </div>
        </div>

        {/* Modal Footer */}
        <div className="p-4 bg-gray-50 border-t flex justify-end">
          <Button variant="outline" onClick={onClose} size="sm">
            Close
          </Button>
        </div>
      </div>
    </div>
  );
}
