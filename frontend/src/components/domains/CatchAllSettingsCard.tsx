'use client';

import React, { useState, useEffect } from 'react';
import { useDomainCatchAll } from '@/hooks/useDomains';
import { CatchAllPolicy } from '@/types';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Spinner } from '@/components/ui/spinner';
import {
  Inbox,
  ShieldAlert,
  AlertTriangle,
  CheckCircle2,
  XCircle,
  HelpCircle,
  Save,
  Radio,
} from 'lucide-react';

interface CatchAllSettingsCardProps {
  domainId: number;
  domainName: string;
  isDomainActive: boolean;
  onRefresh?: () => void;
}

export function CatchAllSettingsCard({
  domainId,
  domainName,
  isDomainActive,
  onRefresh,
}: CatchAllSettingsCardProps) {
  const { catchAll, isLoading, error, updateCatchAll, mutate } = useDomainCatchAll(domainId);

  const [selectedPolicy, setSelectedPolicy] = useState<CatchAllPolicy>('disabled');
  const [selectedMailboxId, setSelectedMailboxId] = useState<string>('');
  const [isSaving, setIsSaving] = useState(false);
  const [saveSuccess, setSaveSuccess] = useState(false);
  const [apiError, setApiError] = useState<string | null>(null);
  const [confirmOpen, setConfirmOpen] = useState(false);

  // Sync state when API data loads
  useEffect(() => {
    if (catchAll) {
      setSelectedPolicy(catchAll.policy || 'disabled');
      setSelectedMailboxId(catchAll.mailbox ? String(catchAll.mailbox.id) : '');
    }
  }, [catchAll]);

  const handleSave = async () => {
    setApiError(null);
    setSaveSuccess(false);

    // Validation
    if (selectedPolicy === 'mailbox' && !selectedMailboxId) {
      setApiError('Please select a destination mailbox to route catch-all emails to.');
      return;
    }

    // Require confirmation when turning on mailbox or discard
    if (
      (selectedPolicy === 'mailbox' || selectedPolicy === 'discard') &&
      catchAll?.policy !== selectedPolicy &&
      !confirmOpen
    ) {
      setConfirmOpen(true);
      return;
    }

    setConfirmOpen(false);
    setIsSaving(true);

    try {
      await updateCatchAll({
        policy: selectedPolicy,
        mailbox_id: selectedPolicy === 'mailbox' ? Number(selectedMailboxId) : null,
      });

      setSaveSuccess(true);
      setTimeout(() => setSaveSuccess(false), 4000);
      if (onRefresh) onRefresh();
    } catch (err: any) {
      setApiError(err.response?.data?.message || 'Failed to update catch-all policy.');
    } finally {
      setIsSaving(false);
    }
  };

  const getRoutingBadge = (status?: string) => {
    switch (status) {
      case 'operational':
        return (
          <Badge variant="success" className="bg-emerald-100 text-emerald-800 border-emerald-200">
            <Radio className="h-3 w-3 mr-1 animate-pulse" /> Live Operational
          </Badge>
        );
      case 'synchronized':
        return (
          <Badge variant="secondary" className="bg-blue-100 text-blue-800 border-blue-200">
            <CheckCircle2 className="h-3 w-3 mr-1" /> Map Synchronized
          </Badge>
        );
      case 'failed':
        return (
          <Badge variant="destructive">
            <XCircle className="h-3 w-3 mr-1" /> Routing Failed
          </Badge>
        );
      case 'configured':
      default:
        return (
          <Badge variant="secondary" className="bg-gray-100 text-gray-700">
            Configured
          </Badge>
        );
    }
  };

  if (isLoading && !catchAll) {
    return (
      <div className="bg-white border rounded-lg p-6 shadow-sm flex items-center justify-center">
        <Spinner className="h-6 w-6 text-primary" />
        <span className="ml-2 text-sm text-muted-foreground">Loading catch-all policy...</span>
      </div>
    );
  }

  const hasChanges =
    catchAll &&
    (selectedPolicy !== catchAll.policy ||
      (selectedPolicy === 'mailbox' && selectedMailboxId !== String(catchAll.mailbox?.id ?? '')));

  return (
    <div className="bg-white border rounded-lg shadow-sm overflow-hidden">
      {/* Header */}
      <div className="p-6 border-b bg-gray-50/50 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <div className="flex items-center gap-3">
            <div className="w-8 h-8 rounded-full bg-indigo-50 text-indigo-600 flex items-center justify-center font-semibold">
              <Inbox className="h-4 w-4" />
            </div>
            <div>
              <h3 className="text-lg font-semibold text-gray-900">
                Catch-All & Unknown Recipient Policy
              </h3>
              <p className="text-sm text-muted-foreground">
                Control how incoming emails sent to unconfigured addresses @{domainName} are handled.
              </p>
            </div>
          </div>
        </div>

        <div className="flex items-center gap-2">
          {catchAll && getRoutingBadge(catchAll.routing?.status)}
          <Badge
            variant={
              catchAll?.policy === 'mailbox'
                ? 'success'
                : catchAll?.policy === 'discard'
                ? 'warning'
                : 'secondary'
            }
          >
            Policy:{' '}
            {catchAll?.policy === 'mailbox'
              ? 'Mailbox'
              : catchAll?.policy === 'discard'
              ? 'Discard'
              : catchAll?.policy === 'reject'
              ? 'Reject'
              : 'Disabled (Default)'}
          </Badge>
        </div>
      </div>

      <div className="p-6 space-y-6">
        {/* Spam Risk Advisory Banner */}
        <div className="bg-blue-50 border-l-4 border-blue-400 p-4 rounded-r-md">
          <div className="flex items-start">
            <HelpCircle className="h-5 w-5 text-blue-500 mt-0.5 flex-shrink-0" />
            <div className="ml-3 text-sm text-blue-700">
              <p className="font-medium text-blue-800">About Catch-All Policies</p>
              <p className="mt-0.5">
                Catch-all rules route emails sent to unknown or mistyped addresses. Note that enabling
                catch-all frequently increases inbound spam and directory harvest volume. By default,
                unknown recipients are rejected with standard RFC 550 errors.
              </p>
            </div>
          </div>
        </div>

        {/* Warning if domain not active */}
        {!isDomainActive && (
          <div className="bg-amber-50 border-l-4 border-amber-400 p-4 rounded-r-md">
            <div className="flex items-start">
              <AlertTriangle className="h-5 w-5 text-amber-500 mt-0.5 flex-shrink-0" />
              <div className="ml-3 text-sm text-amber-800">
                <p className="font-medium">Domain Verification Required</p>
                <p className="mt-0.5">
                  Catch-all can only be enabled on active and verified domains. Please complete DNS verification first.
                </p>
              </div>
            </div>
          </div>
        )}

        {/* Policy Radios */}
        <div className="space-y-4">
          <label className="text-sm font-semibold text-gray-900 block">
            Select Unknown Recipient Handling Policy:
          </label>

          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            {/* Policy: Disabled */}
            <div
              onClick={() => setSelectedPolicy('disabled')}
              className={`border rounded-lg p-4 cursor-pointer transition-all ${
                selectedPolicy === 'disabled'
                  ? 'border-indigo-600 bg-indigo-50/20 ring-2 ring-indigo-600/20'
                  : 'hover:border-gray-300'
              }`}
            >
              <div className="flex items-start gap-3">
                <input
                  type="radio"
                  name="catchall_policy"
                  value="disabled"
                  checked={selectedPolicy === 'disabled'}
                  onChange={() => setSelectedPolicy('disabled')}
                  className="mt-1 text-indigo-600 focus:ring-indigo-500"
                />
                <div>
                  <div className="font-medium text-sm text-gray-900">
                    Disabled (Recommended Default)
                  </div>
                  <div className="text-xs text-muted-foreground mt-1">
                    No catch-all handling. Unknown recipients are rejected during SMTP RCPT TO with standard 550 Mailbox not found.
                  </div>
                </div>
              </div>
            </div>

            {/* Policy: Reject */}
            <div
              onClick={() => setSelectedPolicy('reject')}
              className={`border rounded-lg p-4 cursor-pointer transition-all ${
                selectedPolicy === 'reject'
                  ? 'border-indigo-600 bg-indigo-50/20 ring-2 ring-indigo-600/20'
                  : 'hover:border-gray-300'
              }`}
            >
              <div className="flex items-start gap-3">
                <input
                  type="radio"
                  name="catchall_policy"
                  value="reject"
                  checked={selectedPolicy === 'reject'}
                  onChange={() => setSelectedPolicy('reject')}
                  className="mt-1 text-indigo-600 focus:ring-indigo-500"
                />
                <div>
                  <div className="font-medium text-sm text-gray-900">
                    Explicit Reject (550)
                  </div>
                  <div className="text-xs text-muted-foreground mt-1">
                    Explicitly enforce rejection for any incoming address that does not match a configured mailbox or alias.
                  </div>
                </div>
              </div>
            </div>

            {/* Policy: Route to Mailbox */}
            <div
              onClick={() => isDomainActive && setSelectedPolicy('mailbox')}
              className={`border rounded-lg p-4 cursor-pointer transition-all ${
                !isDomainActive ? 'opacity-60 cursor-not-allowed bg-gray-50' : ''
              } ${
                selectedPolicy === 'mailbox'
                  ? 'border-emerald-600 bg-emerald-50/20 ring-2 ring-emerald-600/20'
                  : 'hover:border-gray-300'
              }`}
            >
              <div className="flex items-start gap-3">
                <input
                  type="radio"
                  name="catchall_policy"
                  value="mailbox"
                  disabled={!isDomainActive}
                  checked={selectedPolicy === 'mailbox'}
                  onChange={() => setSelectedPolicy('mailbox')}
                  className="mt-1 text-emerald-600 focus:ring-emerald-500"
                />
                <div>
                  <div className="font-medium text-sm text-gray-900 flex items-center gap-1.5">
                    Route to Designated Mailbox
                    <Badge variant="success" className="text-[10px] py-0 px-1.5">
                      Catch-All
                    </Badge>
                  </div>
                  <div className="text-xs text-muted-foreground mt-1">
                    Deliver all unmapped incoming emails to an eligible mailbox configured under @{domainName}.
                  </div>
                </div>
              </div>
            </div>

            {/* Policy: Discard */}
            <div
              onClick={() => isDomainActive && setSelectedPolicy('discard')}
              className={`border rounded-lg p-4 cursor-pointer transition-all ${
                !isDomainActive ? 'opacity-60 cursor-not-allowed bg-gray-50' : ''
              } ${
                selectedPolicy === 'discard'
                  ? 'border-amber-600 bg-amber-50/20 ring-2 ring-amber-600/20'
                  : 'hover:border-gray-300'
              }`}
            >
              <div className="flex items-start gap-3">
                <input
                  type="radio"
                  name="catchall_policy"
                  value="discard"
                  disabled={!isDomainActive}
                  checked={selectedPolicy === 'discard'}
                  onChange={() => setSelectedPolicy('discard')}
                  className="mt-1 text-amber-600 focus:ring-amber-500"
                />
                <div>
                  <div className="font-medium text-sm text-gray-900 flex items-center gap-1.5">
                    Accept & Discard
                    <Badge variant="warning" className="text-[10px] py-0 px-1.5">
                      Silent
                    </Badge>
                  </div>
                  <div className="text-xs text-muted-foreground mt-1">
                    Accept incoming messages with 250 OK and silently drop them into the bitbucket without bounce.
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        {/* Destination Mailbox Picker when 'mailbox' selected */}
        {selectedPolicy === 'mailbox' && (
          <div className="p-4 bg-gray-50 border rounded-lg space-y-3">
            <label className="text-sm font-semibold text-gray-900 block">
              Designate Catch-All Mailbox:
            </label>
            <div className="max-w-md">
              <select
                value={selectedMailboxId}
                onChange={(e) => setSelectedMailboxId(e.target.value)}
                className="w-full border rounded-md px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-primary"
              >
                <option value="">-- Select an eligible mailbox --</option>
                {catchAll?.available_mailboxes?.map((mb) => (
                  <option key={mb.id} value={mb.id}>
                    {mb.email} {mb.display_name ? `(${mb.display_name})` : ''}
                  </option>
                ))}
              </select>
            </div>
            <p className="text-xs text-muted-foreground">
              Only active mailboxes under @{domainName} are eligible. Known mailboxes and explicit
              aliases will still receive their own mail directly.
            </p>
          </div>
        )}

        {/* Discard Warning Alert */}
        {selectedPolicy === 'discard' && (
          <div className="bg-red-50 border border-red-200 rounded-lg p-4">
            <div className="flex items-start gap-3">
              <ShieldAlert className="h-5 w-5 text-red-600 mt-0.5 flex-shrink-0" />
              <div className="text-sm text-red-900 space-y-1">
                <p className="font-semibold">Destructive Discard Policy Warning</p>
                <p className="text-xs leading-relaxed text-red-800">
                  Enabling <strong>Accept & Discard</strong> means the mail transfer agent will
                  acknowledge delivery to the sender with SMTP 250 OK and immediately destroy the message.
                  Senders will not receive bounce notifications, and legitimate emails sent to mistyped
                  addresses cannot be recovered.
                </p>
              </div>
            </div>
          </div>
        )}

        {/* Confirmation Modal / Banner */}
        {confirmOpen && (
          <div className="bg-amber-50 border border-amber-300 rounded-lg p-4 space-y-3">
            <div className="flex items-start gap-3">
              <AlertTriangle className="h-5 w-5 text-amber-600 mt-0.5 flex-shrink-0" />
              <div>
                <h4 className="font-semibold text-sm text-amber-900">
                  Confirm Policy Change to{' '}
                  <span className="uppercase">{selectedPolicy}</span>
                </h4>
                <p className="text-xs text-amber-800 mt-1">
                  {selectedPolicy === 'mailbox'
                    ? `Are you sure you want to route all unknown @${domainName} email to ${
                        catchAll?.available_mailboxes?.find((m) => String(m.id) === selectedMailboxId)
                          ?.email || 'the selected mailbox'
                      }? This mailbox will receive all spam sent to unregistered addresses.`
                    : 'Are you sure you want to silently discard all unknown recipient mail? Senders will receive 250 OK and messages will be dropped.'}
                </p>
              </div>
            </div>
            <div className="flex items-center gap-2 justify-end">
              <Button
                variant="outline"
                size="sm"
                onClick={() => setConfirmOpen(false)}
                disabled={isSaving}
              >
                Cancel
              </Button>
              <Button
                variant="destructive"
                size="sm"
                onClick={handleSave}
                disabled={isSaving}
              >
                {isSaving ? <Spinner className="h-3.5 w-3.5 mr-1" /> : null}
                Confirm & Apply Policy
              </Button>
            </div>
          </div>
        )}

        {/* Feedback Alerts */}
        {apiError && (
          <div className="bg-red-50 border border-red-200 text-red-700 p-3 rounded text-sm flex items-center gap-2">
            <XCircle className="h-4 w-4 flex-shrink-0" />
            <span>{apiError}</span>
          </div>
        )}

        {saveSuccess && (
          <div className="bg-emerald-50 border border-emerald-200 text-emerald-800 p-3 rounded text-sm flex items-center gap-2">
            <CheckCircle2 className="h-4 w-4 flex-shrink-0" />
            <span>Catch-all policy updated and synchronized with Postfix routing maps.</span>
          </div>
        )}

        {/* Action Button */}
        {!confirmOpen && (
          <div className="flex items-center justify-between pt-4 border-t">
            <div className="text-xs text-muted-foreground">
              {catchAll?.routing?.warning ? (
                <span className="text-amber-600 flex items-center gap-1">
                  <AlertTriangle className="h-3.5 w-3.5" />
                  {catchAll.routing.warning}
                </span>
              ) : (
                <span>Routing rule evaluated via Postfix MySQL mapping engine.</span>
              )}
            </div>

            <Button
              onClick={handleSave}
              disabled={isSaving || !hasChanges}
              className="flex items-center gap-2"
            >
              {isSaving ? <Spinner className="h-4 w-4" /> : <Save className="h-4 w-4" />}
              Save Policy
            </Button>
          </div>
        )}
      </div>
    </div>
  );
}
