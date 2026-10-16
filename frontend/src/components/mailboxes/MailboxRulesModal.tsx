'use client';

import React, { useState, useEffect } from 'react';
import { useMailboxRules, updateMailboxForwarding, updateMailboxAutoReply } from '@/hooks/useMailboxes';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { Spinner } from '@/components/ui/spinner';
import {
  X,
  ArrowRightLeft,
  Clock,
  Terminal,
  AlertTriangle,
  Info,
  CheckCircle,
  ShieldAlert,
  Send,
  Calendar,
} from 'lucide-react';
import { cn } from '@/lib/utils';

interface MailboxRulesModalProps {
  mailboxId: number;
  email: string;
  onClose: () => void;
  onSuccess?: () => void;
}

export default function MailboxRulesModal({
  mailboxId,
  email,
  onClose,
  onSuccess,
}: MailboxRulesModalProps) {
  const [activeTab, setActiveTab] = useState<'forwarding' | 'autoreply' | 'diagnostics'>('forwarding');
  const { rules, isLoading, error, mutate } = useMailboxRules(mailboxId);

  // Forwarding form state
  const [forwardingEnabled, setForwardingEnabled] = useState(false);
  const [forwardingDestinations, setForwardingDestinations] = useState('');
  const [keepCopy, setKeepCopy] = useState(true);
  const [isSavingForwarding, setIsSavingForwarding] = useState(false);
  const [forwardingFeedback, setForwardingFeedback] = useState<{ type: 'success' | 'error'; message: string } | null>(null);

  // Auto-Reply form state
  const [autoReplyEnabled, setAutoReplyEnabled] = useState(false);
  const [autoReplySubject, setAutoReplySubject] = useState('');
  const [autoReplyBody, setAutoReplyBody] = useState('');
  const [autoReplyStartAt, setAutoReplyStartAt] = useState('');
  const [autoReplyEndAt, setAutoReplyEndAt] = useState('');
  const [isSavingAutoReply, setIsSavingAutoReply] = useState(false);
  const [autoReplyFeedback, setAutoReplyFeedback] = useState<{ type: 'success' | 'error'; message: string } | null>(null);

  // Sync state when rules load
  useEffect(() => {
    if (rules) {
      setForwardingEnabled(rules.forwarding.is_enabled);
      setForwardingDestinations(rules.forwarding.destinations.join(', '));
      setKeepCopy(rules.forwarding.keep_copy);

      setAutoReplyEnabled(rules.auto_reply.is_enabled);
      setAutoReplySubject(rules.auto_reply.subject || 'Out of Office: Automatic Reply');
      setAutoReplyBody(rules.auto_reply.body || '');
      setAutoReplyStartAt(rules.auto_reply.start_at ? rules.auto_reply.start_at.substring(0, 16) : '');
      setAutoReplyEndAt(rules.auto_reply.end_at ? rules.auto_reply.end_at.substring(0, 16) : '');
    }
  }, [rules]);

  const handleSaveForwarding = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsSavingForwarding(true);
    setForwardingFeedback(null);

    try {
      const destList = forwardingDestinations
        .split(/[,\n]/)
        .map((s) => s.trim())
        .filter(Boolean);

      const res = await updateMailboxForwarding(mailboxId, {
        is_forwarding_enabled: forwardingEnabled,
        destinations: destList,
        keep_copy: keepCopy,
      });

      setForwardingFeedback({ type: 'success', message: res.message || 'Forwarding updated successfully' });
      mutate();
      if (onSuccess) onSuccess();
    } catch (err: any) {
      const msg = err.response?.data?.message || 'Failed to update forwarding rules';
      setForwardingFeedback({ type: 'error', message: msg });
    } finally {
      setIsSavingForwarding(false);
    }
  };

  const handleSaveAutoReply = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsSavingAutoReply(true);
    setAutoReplyFeedback(null);

    try {
      const res = await updateMailboxAutoReply(mailboxId, {
        auto_reply_enabled: autoReplyEnabled,
        subject: autoReplySubject,
        body: autoReplyBody,
        start_at: autoReplyStartAt ? new Date(autoReplyStartAt).toISOString() : null,
        end_at: autoReplyEndAt ? new Date(autoReplyEndAt).toISOString() : null,
      });

      setAutoReplyFeedback({ type: 'success', message: res.message || 'Auto-reply updated successfully' });
      mutate();
      if (onSuccess) onSuccess();
    } catch (err: any) {
      const msg = err.response?.data?.message || 'Failed to update auto-reply rules';
      setAutoReplyFeedback({ type: 'error', message: msg });
    } finally {
      setIsSavingAutoReply(false);
    }
  };

  const getStatusBadge = (status?: string) => {
    switch (status) {
      case 'operational':
        return <Badge variant="success">Operational</Badge>;
      case 'synchronized':
        return <Badge variant="secondary" className="bg-blue-100 text-blue-800 border-blue-200">Synchronized</Badge>;
      case 'configured':
        return <Badge variant="secondary">Configured</Badge>;
      case 'failed':
        return <Badge variant="destructive">Routing Failed</Badge>;
      default:
        return <Badge variant="outline">Unknown</Badge>;
    }
  };

  return (
    <div className="fixed inset-0 z-50 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4 overflow-y-auto">
      <div className="bg-white rounded-xl shadow-2xl border border-gray-200 max-w-2xl w-full max-h-[90vh] flex flex-col overflow-hidden animate-in fade-in zoom-in-95 duration-150">
        {/* Modal Header */}
        <div className="px-6 py-4 border-b border-gray-100 flex items-center justify-between bg-gray-50/80">
          <div>
            <h2 className="text-lg font-bold text-gray-900 flex items-center gap-2">
              <Send className="h-5 w-5 text-indigo-600" />
              Mailbox Rules & Automation
            </h2>
            <p className="text-xs text-gray-500 font-mono mt-0.5">{email}</p>
          </div>
          <Button variant="ghost" size="sm" onClick={onClose} className="h-8 w-8 p-0 text-gray-400 hover:text-gray-600">
            <X className="h-4 w-4" />
          </Button>
        </div>

        {/* Tab Navigation */}
        <div className="flex border-b border-gray-200 bg-gray-50/50 px-6 pt-2">
          <button
            type="button"
            onClick={() => setActiveTab('forwarding')}
            className={cn(
              "px-4 py-2.5 text-sm font-medium border-b-2 transition-colors flex items-center gap-2 -mb-px",
              activeTab === 'forwarding'
                ? "border-indigo-600 text-indigo-600 bg-white rounded-t-md"
                : "border-transparent text-gray-500 hover:text-gray-700"
            )}
          >
            <ArrowRightLeft className="h-4 w-4" />
            Email Forwarding
            {rules?.forwarding.is_enabled && (
              <span className="h-2 w-2 rounded-full bg-emerald-500 animate-pulse" />
            )}
          </button>

          <button
            type="button"
            onClick={() => setActiveTab('autoreply')}
            className={cn(
              "px-4 py-2.5 text-sm font-medium border-b-2 transition-colors flex items-center gap-2 -mb-px",
              activeTab === 'autoreply'
                ? "border-indigo-600 text-indigo-600 bg-white rounded-t-md"
                : "border-transparent text-gray-500 hover:text-gray-700"
            )}
          >
            <Clock className="h-4 w-4" />
            Out of Office (Auto-Reply)
            {rules?.auto_reply.is_enabled && (
              <span className="h-2 w-2 rounded-full bg-indigo-500 animate-pulse" />
            )}
          </button>

          <button
            type="button"
            onClick={() => setActiveTab('diagnostics')}
            className={cn(
              "px-4 py-2.5 text-sm font-medium border-b-2 transition-colors flex items-center gap-2 -mb-px",
              activeTab === 'diagnostics'
                ? "border-indigo-600 text-indigo-600 bg-white rounded-t-md"
                : "border-transparent text-gray-500 hover:text-gray-700"
            )}
          >
            <Terminal className="h-4 w-4" />
            Sieve & Diagnostics
          </button>
        </div>

        {/* Modal Body */}
        <div className="p-6 overflow-y-auto flex-1 space-y-5">
          {isLoading ? (
            <div className="py-12 flex flex-col items-center justify-center gap-3">
              <Spinner className="h-6 w-6 text-indigo-600" />
              <p className="text-sm text-gray-500">Loading mailbox rules...</p>
            </div>
          ) : error ? (
            <div className="p-4 bg-red-50 border border-red-200 rounded-lg text-red-700 text-sm flex items-center gap-2">
              <AlertTriangle className="h-4 w-4 shrink-0" />
              Failed to load mailbox rules. Please try again.
            </div>
          ) : (
            <>
              {/* TAB 1: EMAIL FORWARDING */}
              {activeTab === 'forwarding' && (
                <form onSubmit={handleSaveForwarding} className="space-y-4">
                  <div className="flex items-center justify-between pb-3 border-b">
                    <div>
                      <div className="font-semibold text-gray-900 text-sm">Forward Incoming Mail</div>
                      <div className="text-xs text-gray-500">Automatically redirect incoming messages to destination addresses</div>
                    </div>
                    <div className="flex items-center gap-2">
                      {getStatusBadge(rules?.forwarding.status)}
                      <input
                        type="checkbox"
                        checked={forwardingEnabled}
                        onChange={(e) => setForwardingEnabled(e.target.checked)}
                        className="h-5 w-5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 cursor-pointer"
                      />
                    </div>
                  </div>

                  {forwardingFeedback && (
                    <div className={cn(
                      "p-3 rounded-lg text-xs flex items-center gap-2",
                      forwardingFeedback.type === 'success'
                        ? "bg-emerald-50 border border-emerald-200 text-emerald-800"
                        : "bg-red-50 border border-red-200 text-red-800"
                    )}>
                      {forwardingFeedback.type === 'success' ? <CheckCircle className="h-4 w-4 shrink-0" /> : <AlertTriangle className="h-4 w-4 shrink-0" />}
                      {forwardingFeedback.message}
                    </div>
                  )}

                  {rules?.forwarding.warning && (
                    <div className="p-3 bg-amber-50 border border-amber-200 rounded-lg text-amber-800 text-xs flex items-start gap-2">
                      <ShieldAlert className="h-4 w-4 shrink-0 mt-0.5" />
                      <div>
                        <strong>Routing Notice:</strong> {rules.forwarding.warning}
                      </div>
                    </div>
                  )}

                  <div className="space-y-2">
                    <label className="block text-xs font-medium text-gray-700">
                      Destination Email Addresses <span className="text-gray-400 font-normal">(comma-separated, max 10)</span>
                    </label>
                    <textarea
                      rows={3}
                      value={forwardingDestinations}
                      onChange={(e) => setForwardingDestinations(e.target.value)}
                      placeholder="e.g. personal@gmail.com, colleague@example.com"
                      disabled={!forwardingEnabled}
                      className="w-full text-sm rounded-md border border-gray-300 p-2.5 focus:border-indigo-500 focus:ring-indigo-500 disabled:bg-gray-100 disabled:text-gray-400 font-mono"
                    />
                    <p className="text-[11px] text-gray-500">
                      Messages sent to <code className="text-gray-700">{email}</code> will be relayed to these destinations. Self-forwarding and circular loops are rejected automatically.
                    </p>
                  </div>

                  <div className="p-3.5 bg-gray-50 rounded-lg border border-gray-200 flex items-start gap-3">
                    <input
                      type="checkbox"
                      id="keep_copy"
                      checked={keepCopy}
                      onChange={(e) => setKeepCopy(e.target.checked)}
                      disabled={!forwardingEnabled}
                      className="mt-0.5 h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 cursor-pointer disabled:cursor-not-allowed"
                    />
                    <label htmlFor="keep_copy" className="text-xs text-gray-700 cursor-pointer">
                      <span className="font-semibold block text-gray-900">Preserve local delivery (Keep a copy)</span>
                      Keep a copy of all forwarded messages in this mailbox. If unchecked, messages will be relayed directly to destinations without storing locally.
                    </label>
                  </div>

                  <div className="pt-2 flex justify-end">
                    <Button type="submit" disabled={isSavingForwarding} className="flex items-center gap-2">
                      {isSavingForwarding && <Spinner className="h-4 w-4" />}
                      Save Forwarding Rules
                    </Button>
                  </div>
                </form>
              )}

              {/* TAB 2: OUT OF OFFICE / AUTO-REPLY */}
              {activeTab === 'autoreply' && (
                <form onSubmit={handleSaveAutoReply} className="space-y-4">
                  <div className="flex items-center justify-between pb-3 border-b">
                    <div>
                      <div className="font-semibold text-gray-900 text-sm">Out-of-Office Vacation Auto-Reply</div>
                      <div className="text-xs text-gray-500">Send automated email responses to senders while away</div>
                    </div>
                    <div className="flex items-center gap-2">
                      <Badge variant={
                        rules?.auto_reply.schedule_status === 'active' ? 'success' :
                        rules?.auto_reply.schedule_status === 'scheduled' ? 'outline' : 'secondary'
                      }>
                        {rules?.auto_reply.schedule_status ? rules.auto_reply.schedule_status.toUpperCase() : 'DISABLED'}
                      </Badge>
                      <input
                        type="checkbox"
                        checked={autoReplyEnabled}
                        onChange={(e) => setAutoReplyEnabled(e.target.checked)}
                        className="h-5 w-5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 cursor-pointer"
                      />
                    </div>
                  </div>

                  {autoReplyFeedback && (
                    <div className={cn(
                      "p-3 rounded-lg text-xs flex items-center gap-2",
                      autoReplyFeedback.type === 'success'
                        ? "bg-emerald-50 border border-emerald-200 text-emerald-800"
                        : "bg-red-50 border border-red-200 text-red-800"
                    )}>
                      {autoReplyFeedback.type === 'success' ? <CheckCircle className="h-4 w-4 shrink-0" /> : <AlertTriangle className="h-4 w-4 shrink-0" />}
                      {autoReplyFeedback.message}
                    </div>
                  )}

                  <div className="p-3 bg-blue-50 border border-blue-200 rounded-lg text-blue-900 text-xs flex items-start gap-2">
                    <Info className="h-4 w-4 shrink-0 mt-0.5 text-blue-600" />
                    <div>
                      <strong>Smart Vacation Filter (RFC 5230):</strong> Auto-replies are rate-limited to at most once per 24 hours per unique sender, and automated mailings, newsletters, and mailing lists (List-Id, Precedence: bulk) are automatically excluded to prevent mail loops.
                    </div>
                  </div>

                  <div className="space-y-2">
                    <label className="block text-xs font-medium text-gray-700">Subject</label>
                    <Input
                      type="text"
                      value={autoReplySubject}
                      onChange={(e) => setAutoReplySubject(e.target.value)}
                      placeholder="Out of Office: Automatic Reply"
                      disabled={!autoReplyEnabled}
                      maxLength={255}
                      required={autoReplyEnabled}
                    />
                  </div>

                  <div className="space-y-2">
                    <div className="flex justify-between items-center text-xs">
                      <label className="font-medium text-gray-700">Message Body</label>
                      <span className="text-gray-400">{autoReplyBody.length} / 5000</span>
                    </div>
                    <textarea
                      rows={4}
                      value={autoReplyBody}
                      onChange={(e) => setAutoReplyBody(e.target.value)}
                      placeholder="Thank you for reaching out. I am currently out of the office and will have limited access to email..."
                      disabled={!autoReplyEnabled}
                      maxLength={5000}
                      required={autoReplyEnabled}
                      className="w-full text-sm rounded-md border border-gray-300 p-2.5 focus:border-indigo-500 focus:ring-indigo-500 disabled:bg-gray-100 disabled:text-gray-400"
                    />
                  </div>

                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-1">
                    <div className="space-y-1.5">
                      <label className="block text-xs font-medium text-gray-700 flex items-center gap-1.5">
                        <Calendar className="h-3.5 w-3.5 text-gray-500" />
                        Start Date & Time (Optional)
                      </label>
                      <Input
                        type="datetime-local"
                        value={autoReplyStartAt}
                        onChange={(e) => setAutoReplyStartAt(e.target.value)}
                        disabled={!autoReplyEnabled}
                        className="text-xs"
                      />
                    </div>

                    <div className="space-y-1.5">
                      <label className="block text-xs font-medium text-gray-700 flex items-center gap-1.5">
                        <Calendar className="h-3.5 w-3.5 text-gray-500" />
                        End Date & Time (Optional)
                      </label>
                      <Input
                        type="datetime-local"
                        value={autoReplyEndAt}
                        onChange={(e) => setAutoReplyEndAt(e.target.value)}
                        disabled={!autoReplyEnabled}
                        className="text-xs"
                      />
                    </div>
                  </div>

                  <div className="pt-2 flex justify-end">
                    <Button type="submit" disabled={isSavingAutoReply} className="flex items-center gap-2">
                      {isSavingAutoReply && <Spinner className="h-4 w-4" />}
                      Save Auto-Reply Rules
                    </Button>
                  </div>
                </form>
              )}

              {/* TAB 3: SIEVE & MTA DIAGNOSTICS */}
              {activeTab === 'diagnostics' && (
                <div className="space-y-4">
                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                    <div className="p-3 bg-gray-50 rounded-lg border">
                      <span className="text-gray-500 block">Postfix Forwarding Driver</span>
                      <span className="font-mono font-semibold text-gray-800">{rules?.mta.map_query}</span>
                      <span className="text-gray-400 block mt-1">Status: {rules?.mta.status}</span>
                    </div>

                    <div className="p-3 bg-gray-50 rounded-lg border">
                      <span className="text-gray-500 block">Dovecot Sieve Delivery</span>
                      <span className="font-mono font-semibold text-gray-800">{rules?.sieve.driver}</span>
                      <span className="text-gray-400 block mt-1">Mode: {rules?.sieve.environment_mode}</span>
                    </div>
                  </div>

                  {rules?.sieve.environment_mode === 'local_simulation' && (
                    <div className="p-3 bg-amber-50 border border-amber-200 rounded-lg text-amber-800 text-xs flex items-start gap-2">
                      <Info className="h-4 w-4 shrink-0 mt-0.5 text-amber-600" />
                      <div>
                        <strong>Local Development Notice:</strong> Sieve scripts and Postfix forwarding maps are generated, validated, and stored in the database. End-to-end socket delivery executes on a Linux server running Dovecot Pigeonhole.
                      </div>
                    </div>
                  )}

                  <div className="space-y-1.5">
                    <div className="flex justify-between items-center text-xs">
                      <span className="font-medium text-gray-700">Compiled Sieve Filter Script (.dovecot.sieve)</span>
                      <Badge variant="outline" className="text-[10px] font-mono">RFC 5228 / 5230</Badge>
                    </div>
                    <pre className="p-3 bg-gray-900 text-gray-100 rounded-lg text-xs font-mono overflow-x-auto max-h-56 leading-relaxed">
                      {rules?.sieve.script_preview}
                    </pre>
                  </div>
                </div>
              )}
            </>
          )}
        </div>

        {/* Modal Footer */}
        <div className="px-6 py-3 border-t border-gray-100 bg-gray-50 flex items-center justify-between text-xs text-gray-500">
          <span>Rules updated in real-time</span>
          <Button variant="outline" size="sm" onClick={onClose}>
            Close
          </Button>
        </div>
      </div>
    </div>
  );
}
