'use client';

import { useState } from 'react';
import Link from 'next/link';
import { useDashboard } from '@/hooks/useDashboard';
import { StatsCard } from '@/components/dashboard/StatsCard';
import { QuickActions } from '@/components/dashboard/QuickActions';
import { Spinner } from '@/components/ui/spinner';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDate } from '@/lib/utils';
import { 
  Globe, 
  Mail, 
  Box, 
  ShieldCheck, 
  AlertCircle, 
  AlertTriangle, 
  RefreshCw, 
  CheckCircle2, 
  XCircle, 
  ExternalLink, 
  Send, 
  ShieldAlert, 
  FileText, 
  Activity, 
  Clock, 
  Info,
  Server
} from 'lucide-react';

export default function DashboardPage() {
  const { stats, isLoading, error, mutate } = useDashboard();
  const [isRefreshing, setIsRefreshing] = useState(false);

  const handleRefresh = async () => {
    setIsRefreshing(true);
    try {
      await mutate();
    } finally {
      setIsRefreshing(false);
    }
  };

  if (isLoading) {
    return (
      <div className="flex flex-col items-center justify-center min-h-[350px] space-y-3">
        <Spinner className="h-8 w-8 text-primary" />
        <p className="text-sm text-muted-foreground">Loading security and deliverability dashboard...</p>
      </div>
    );
  }

  if (error || !stats) {
    return (
      <div className="rounded-lg border border-red-200 bg-red-50 p-6 text-red-800 space-y-3">
        <div className="flex items-center gap-2 font-semibold">
          <AlertCircle className="h-5 w-5 text-red-600" />
          Failed to load dashboard data
        </div>
        <p className="text-sm">Unable to retrieve security and deliverability metrics. Please check your connection and try again.</p>
        <Button variant="outline" size="sm" onClick={handleRefresh}>
          Retry
        </Button>
      </div>
    );
  }

  const user = stats.user;
  const domainSec = stats.domain_security;
  const smtpUsage = stats.smtp_usage;
  const recentActivity = stats.recent_activity;
  const abuse = stats.abuse_and_security;
  const dmarc = stats.dmarc_reporting;

  const totalDomains = stats.domains_count ?? stats.domain_count ?? 0;
  const totalMailboxes = stats.mailboxes_count ?? stats.mailbox_count ?? 0;
  const avgDeliverabilityScore = domainSec?.summary?.average_deliverability_score ?? 0;
  const domainsWithDrift = domainSec?.summary?.domains_with_drift ?? 0;
  const openIncidentsCount = abuse?.open_incidents_count ?? 0;

  return (
    <div className="space-y-8 max-w-7xl pb-12">
      {/* Dashboard Header */}
      <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b pb-5">
        <div>
          <div className="flex items-center gap-3">
            <h1 className="text-2xl font-bold tracking-tight text-gray-900">
              Security & Deliverability Dashboard
            </h1>
            <Badge variant={user?.status === 'active' ? 'success' : user?.status === 'pending' ? 'warning' : 'destructive'}>
              {user?.status ? user.status.toUpperCase() : 'ACTIVE'}
            </Badge>
          </div>
          <p className="text-sm text-muted-foreground mt-1">
            Account: <strong className="text-gray-800">{user?.email}</strong>
            {user?.plan && (
              <span> • Plan: <strong className="text-gray-800">{user.plan.name}</strong></span>
            )}
            {user?.plan_expires_at && (
              <span> • Renews/Expires: {formatDate(user.plan_expires_at)}</span>
            )}
          </p>
        </div>

        <div className="flex items-center gap-2 shrink-0">
          <Button 
            variant="outline" 
            size="sm" 
            onClick={handleRefresh} 
            disabled={isRefreshing}
            className="flex items-center gap-2 shadow-sm"
          >
            <RefreshCw className={`h-4 w-4 ${isRefreshing ? 'animate-spin' : ''}`} />
            {isRefreshing ? 'Refreshing...' : 'Refresh Telemetry'}
          </Button>
        </div>
      </div>

      {/* Alert Banners */}
      {/* 1. DNS Drift Warning Banner */}
      {domainsWithDrift > 0 && (
        <div className="rounded-xl border border-amber-300 bg-amber-50 p-4 text-amber-900 shadow-sm flex items-start gap-3">
          <AlertTriangle className="h-5 w-5 text-amber-600 mt-0.5 shrink-0" />
          <div className="space-y-1">
            <h4 className="font-semibold text-sm">DNS Drift Detected on {domainsWithDrift} Domain(s)</h4>
            <p className="text-xs text-amber-800 leading-relaxed">
              Authoritative DNS checks indicate that one or more required DNS records (MX, SPF, or DKIM) have been removed, modified, or are no longer resolving for your active domains. Mail delivery or cryptographic signing may fail until these records are restored.
            </p>
          </div>
        </div>
      )}

      {/* 2. Abuse Incidents Alert Banner */}
      {openIncidentsCount > 0 && (
        <div className="rounded-xl border border-red-300 bg-red-50 p-4 text-red-900 shadow-sm flex items-start gap-3">
          <ShieldAlert className="h-5 w-5 text-red-600 mt-0.5 shrink-0" />
          <div className="space-y-1">
            <h4 className="font-semibold text-sm">Active Reputation Alert ({openIncidentsCount} Open Incident{openIncidentsCount > 1 ? 's' : ''})</h4>
            <p className="text-xs text-red-800 leading-relaxed">
              Deliverability anomalies or bounce rate thresholds have been triggered on your account. Review the incident details and remediation steps below to protect your sending reputation.
            </p>
          </div>
        </div>
      )}

      {/* 3. Quota Warning Banner */}
      {smtpUsage?.quota_warning && (
        <div className="rounded-xl border border-orange-300 bg-orange-50 p-4 text-orange-900 shadow-sm flex items-start gap-3">
          <AlertCircle className="h-5 w-5 text-orange-600 mt-0.5 shrink-0" />
          <div className="space-y-1">
            <h4 className="font-semibold text-sm">Outbound Sending Quota Notice</h4>
            <p className="text-xs text-orange-800 leading-relaxed">
              {smtpUsage.quota_warning}
            </p>
          </div>
        </div>
      )}

      {/* Top Stat KPI Cards */}
      <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
        <StatsCard
          title="Sender Domains"
          value={totalDomains}
          icon={Globe}
          description={
            domainsWithDrift > 0 
              ? `${domainsWithDrift} domain(s) have DNS drift` 
              : `${domainSec?.summary?.active_domains ?? totalDomains} active & configured`
          }
        />
        <StatsCard
          title="Active Mailboxes"
          value={totalMailboxes}
          icon={Mail}
          description="Provisioned sending identities"
        />
        <StatsCard
          title="Deliverability Score"
          value={`${avgDeliverabilityScore}%`}
          icon={ShieldCheck}
          description={
            avgDeliverabilityScore >= 90
              ? 'Excellent authentication posture'
              : avgDeliverabilityScore >= 70
              ? 'Good, partial records configured'
              : 'Setup incomplete; action required'
          }
        />
        <StatsCard
          title="Daily Quota (Today)"
          value={
            smtpUsage?.is_unlimited 
              ? 'Unlimited' 
              : `${smtpUsage?.used_today ?? 0} / ${smtpUsage?.daily_limit ?? 0}`
          }
          icon={Send}
          description={
            smtpUsage?.is_unlimited 
              ? 'No recipient limits' 
              : `${smtpUsage?.remaining_today ?? 0} recipients remaining today`
          }
        />
      </div>

      {/* Section 1: Domain Authentication & Deliverability Posture */}
      <div className="rounded-xl border bg-white shadow-sm overflow-hidden">
        <div className="p-5 border-b flex flex-col sm:flex-row sm:items-center justify-between gap-3">
          <div className="flex items-center gap-2.5">
            <ShieldCheck className="h-5 w-5 text-indigo-600" />
            <div>
              <h2 className="text-base font-semibold text-gray-900">Domain Authentication Posture</h2>
              <p className="text-xs text-muted-foreground">
                Cryptographic authentication and inbound routing readiness across all sender domains
              </p>
            </div>
          </div>
          <div className="flex items-center gap-2">
            <span className="text-xs font-mono px-2 py-0.5 rounded bg-slate-100 text-slate-700">
              {domainSec?.summary?.fully_authenticated_domains ?? 0} / {totalDomains} Fully Verified
            </span>
            <Link href="/domains/new">
              <Button size="sm" variant="outline" className="text-xs">
                Add Domain
              </Button>
            </Link>
          </div>
        </div>

        <div className="p-5 space-y-4">
          {(!domainSec?.domains || domainSec.domains.length === 0) ? (
            <div className="text-center py-8 space-y-2">
              <Globe className="h-8 w-8 text-slate-300 mx-auto" />
              <p className="text-sm font-medium text-slate-700">No domains added yet</p>
              <p className="text-xs text-muted-foreground">Add your first domain to configure MX, SPF, DKIM, and DMARC authentication.</p>
              <Link href="/domains/new">
                <Button size="sm" className="mt-2">Add Domain Now</Button>
              </Link>
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-xs text-left border-collapse">
                <thead>
                  <tr className="border-b bg-slate-50 text-slate-600">
                    <th className="py-2.5 px-3 font-semibold">Domain</th>
                    <th className="py-2.5 px-3 font-semibold">Status</th>
                    <th className="py-2.5 px-3 font-semibold text-center">MX</th>
                    <th className="py-2.5 px-3 font-semibold text-center">SPF</th>
                    <th className="py-2.5 px-3 font-semibold text-center">DKIM</th>
                    <th className="py-2.5 px-3 font-semibold text-center">DMARC</th>
                    <th className="py-2.5 px-3 font-semibold">Health Score</th>
                    <th className="py-2.5 px-3 font-semibold">Drift</th>
                    <th className="py-2.5 px-3 font-semibold">Last Checked</th>
                    <th className="py-2.5 px-3 font-semibold text-right">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {domainSec.domains.map((d) => (
                    <tr key={d.id} className="hover:bg-slate-50/60 transition-colors">
                      <td className="py-3 px-3 font-medium text-gray-900">
                        <Link href={`/domains/${d.id}`} className="hover:underline text-indigo-600">
                          {d.domain_name}
                        </Link>
                        <span className="text-[11px] text-muted-foreground block">
                          {d.mailboxes_count} mailbox{d.mailboxes_count === 1 ? '' : 'es'}
                        </span>
                      </td>
                      <td className="py-3 px-3">
                        <Badge variant={d.status === 'active' ? 'success' : d.status === 'failed' ? 'destructive' : 'warning'}>
                          {d.status}
                        </Badge>
                      </td>
                      <td className="py-3 px-3 text-center">
                        {d.mx_verified ? (
                          <span className="inline-flex items-center text-green-600 text-xs font-mono" title="MX Verified">
                            <CheckCircle2 className="h-4 w-4" />
                          </span>
                        ) : (
                          <span className="inline-flex items-center text-red-500 text-xs font-mono" title="MX Missing">
                            <XCircle className="h-4 w-4" />
                          </span>
                        )}
                      </td>
                      <td className="py-3 px-3 text-center">
                        {d.spf_verified ? (
                          <span className="inline-flex items-center text-green-600 text-xs font-mono" title="SPF Verified">
                            <CheckCircle2 className="h-4 w-4" />
                          </span>
                        ) : (
                          <span className="inline-flex items-center text-red-500 text-xs font-mono" title="SPF Missing">
                            <XCircle className="h-4 w-4" />
                          </span>
                        )}
                      </td>
                      <td className="py-3 px-3 text-center">
                        {d.dkim_verified ? (
                          <span className="inline-flex items-center text-green-600 text-xs font-mono" title="DKIM Verified">
                            <CheckCircle2 className="h-4 w-4" />
                          </span>
                        ) : (
                          <span className="inline-flex items-center text-red-500 text-xs font-mono" title="DKIM Missing">
                            <XCircle className="h-4 w-4" />
                          </span>
                        )}
                      </td>
                      <td className="py-3 px-3 text-center">
                        {d.dmarc_verified ? (
                          <span className="inline-flex items-center text-green-600 text-xs font-mono" title="DMARC Verified">
                            <CheckCircle2 className="h-4 w-4" />
                          </span>
                        ) : (
                          <span className="inline-flex items-center text-slate-400 text-xs font-mono" title="DMARC Missing">
                            <XCircle className="h-4 w-4" />
                          </span>
                        )}
                      </td>
                      <td className="py-3 px-3">
                        <div className="flex items-center gap-2">
                          <span className={`font-bold ${d.score >= 80 ? 'text-green-600' : d.score >= 50 ? 'text-amber-600' : 'text-red-600'}`}>
                            {d.score}%
                          </span>
                          <span className="text-[10px] text-muted-foreground hidden sm:inline">
                            {d.score_status_label}
                          </span>
                        </div>
                      </td>
                      <td className="py-3 px-3">
                        {d.dns_drift_detected ? (
                          <span className="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-amber-100 text-amber-800">
                            Drift
                          </span>
                        ) : (
                          <span className="text-slate-400">—</span>
                        )}
                      </td>
                      <td className="py-3 px-3 text-slate-500 font-mono text-[11px]">
                        {d.last_dns_check_at ? formatDate(d.last_dns_check_at) : 'Never'}
                      </td>
                      <td className="py-3 px-3 text-right">
                        <Link href={d.setup_url}>
                          <Button size="sm" variant="ghost" className="h-7 px-2 text-xs text-indigo-600 hover:text-indigo-800">
                            Setup & Diagnostics
                          </Button>
                        </Link>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          {domainSec?.recommendations && domainSec.recommendations.length > 0 && (
            <div className="rounded-lg border border-blue-100 bg-blue-50/60 p-3 text-xs text-blue-900 space-y-1">
              <span className="font-semibold block">Authentication Recommendations:</span>
              <ul className="list-disc list-inside space-y-0.5 text-blue-800">
                {domainSec.recommendations.map((rec, idx) => (
                  <li key={idx}>{rec}</li>
                ))}
              </ul>
            </div>
          )}
        </div>
      </div>

      {/* Section 2: SMTP Outbound Activity & Quota Telemetry */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Left Column: Daily Quota & Volume Trend */}
        <div className="rounded-xl border bg-white p-5 shadow-sm space-y-4">
          <div className="flex items-center justify-between border-b pb-3">
            <div className="flex items-center gap-2">
              <Activity className="h-5 w-5 text-indigo-600" />
              <h3 className="font-semibold text-sm text-gray-900">SMTP Quota & Volume</h3>
            </div>
            <span className="text-[11px] font-mono text-muted-foreground">
              UTC Daily Window
            </span>
          </div>

          {/* Quota Progress Bar */}
          <div className="space-y-2">
            <div className="flex justify-between text-xs">
              <span className="text-muted-foreground">Daily Recipient Quota:</span>
              <span className="font-semibold text-gray-900">
                {smtpUsage?.is_unlimited ? 'Unlimited' : `${smtpUsage?.used_today ?? 0} / ${smtpUsage?.daily_limit ?? 0}`}
              </span>
            </div>
            {!smtpUsage?.is_unlimited && (
              <div className="w-full bg-slate-100 h-2.5 rounded-full overflow-hidden">
                <div 
                  className={`h-full transition-all duration-500 rounded-full ${
                    (smtpUsage?.percent_used ?? 0) >= 100 
                      ? 'bg-red-500' 
                      : (smtpUsage?.percent_used ?? 0) >= 85 
                      ? 'bg-amber-500' 
                      : 'bg-indigo-600'
                  }`}
                  style={{ width: `${Math.min(100, smtpUsage?.percent_used ?? 0)}%` }}
                />
              </div>
            )}
            <div className="flex justify-between text-[11px] text-slate-500 pt-0.5">
              <span>Used: {smtpUsage?.percent_used ?? 0}%</span>
              <span>Remaining: {smtpUsage?.is_unlimited ? 'Unlimited' : `${smtpUsage?.remaining_today ?? 0} recipients`}</span>
            </div>
          </div>

          {/* Delivery Log Event Summary Pills */}
          <div className="pt-2 border-t">
            <span className="text-xs font-semibold text-gray-700 block mb-2">Delivery Event Totals (Last 30 Days)</span>
            <div className="grid grid-cols-2 gap-2 text-center">
              <div className="bg-green-50 p-2 rounded border border-green-100">
                <span className="text-[10px] text-green-700 uppercase block font-semibold">Delivered</span>
                <span className="text-base font-bold text-green-800">
                  {smtpUsage?.delivery_summary?.delivered ?? 0}
                </span>
              </div>
              <div className="bg-amber-50 p-2 rounded border border-amber-100">
                <span className="text-[10px] text-amber-700 uppercase block font-semibold">Deferred</span>
                <span className="text-base font-bold text-amber-800">
                  {smtpUsage?.delivery_summary?.deferred ?? 0}
                </span>
              </div>
              <div className="bg-red-50 p-2 rounded border border-red-100">
                <span className="text-[10px] text-red-700 uppercase block font-semibold">Bounced</span>
                <span className="text-base font-bold text-red-800">
                  {smtpUsage?.delivery_summary?.bounced ?? 0}
                </span>
              </div>
              <div className="bg-slate-50 p-2 rounded border border-slate-200">
                <span className="text-[10px] text-slate-700 uppercase block font-semibold">Rejected</span>
                <span className="text-base font-bold text-slate-800">
                  {smtpUsage?.delivery_summary?.rejected ?? 0}
                </span>
              </div>
            </div>
          </div>

          {/* 14-day history mini-trend */}
          {smtpUsage?.history && smtpUsage.history.length > 0 && (
            <div className="pt-2 border-t space-y-1.5">
              <span className="text-xs font-semibold text-gray-700 block">14-Day Outbound Volume Trend</span>
              <div className="flex items-end gap-1 h-14 pt-2">
                {smtpUsage.history.map((h, i) => {
                  const maxCount = Math.max(1, ...smtpUsage.history.map(x => x.recipient_count));
                  const heightPercent = Math.max(8, Math.round((h.recipient_count / maxCount) * 100));
                  return (
                    <div 
                      key={i} 
                      className="flex-1 flex flex-col items-center group relative cursor-pointer"
                      title={`${h.date}: ${h.recipient_count} recipients`}
                    >
                      <div 
                        className={`w-full rounded-t transition-all ${
                          h.is_today ? 'bg-indigo-600' : 'bg-slate-300 hover:bg-slate-400'
                        }`}
                        style={{ height: `${heightPercent}%` }}
                      />
                    </div>
                  );
                })}
              </div>
              <div className="flex justify-between text-[10px] text-slate-400 pt-0.5">
                <span>14 days ago</span>
                <span>Today</span>
              </div>
            </div>
          )}

          <div className="pt-1 text-[11px] text-slate-400">
            Source: {smtpUsage?.data_source || 'Redis + MariaDB'}
          </div>
        </div>

        {/* Right Column: Recent Outbound Activity Table */}
        <div className="lg:col-span-2 rounded-xl border bg-white p-5 shadow-sm space-y-4">
          <div className="flex items-center justify-between border-b pb-3">
            <div className="flex items-center gap-2">
              <Clock className="h-5 w-5 text-indigo-600" />
              <h3 className="font-semibold text-sm text-gray-900">Recent Outbound Message Activity</h3>
            </div>
            <Link href="/smtp" className="text-xs text-indigo-600 hover:underline">
              View All Logs
            </Link>
          </div>

          {(!recentActivity?.events || recentActivity.events.length === 0) ? (
            <div className="text-center py-10 space-y-2">
              <Send className="h-8 w-8 text-slate-300 mx-auto" />
              <p className="text-sm font-medium text-slate-700">No outbound messages sent yet</p>
              <p className="text-xs text-muted-foreground">Deliveries through your authorized SMTP mailboxes will appear here in real-time.</p>
              <Link href="/smtp">
                <Button size="sm" variant="outline" className="mt-2 text-xs">
                  View SMTP Credentials
                </Button>
              </Link>
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-xs text-left border-collapse">
                <thead>
                  <tr className="border-b bg-slate-50 text-slate-600">
                    <th className="py-2 px-3 font-semibold">Recipient</th>
                    <th className="py-2 px-3 font-semibold">Sender</th>
                    <th className="py-2 px-3 font-semibold">Status</th>
                    <th className="py-2 px-3 font-semibold">Queue ID</th>
                    <th className="py-2 px-3 font-semibold">Time</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {recentActivity.events.map((ev) => (
                    <tr key={ev.id} className="hover:bg-slate-50 transition-colors">
                      <td className="py-2 px-3 font-mono font-medium text-gray-900 truncate max-w-[200px]">
                        {ev.recipient_email}
                      </td>
                      <td className="py-2 px-3 font-mono text-slate-600 truncate max-w-[150px]">
                        {ev.sender_email}
                      </td>
                      <td className="py-2 px-3">
                        <span className={`px-2 py-0.5 rounded text-[10px] font-semibold uppercase ${
                          ev.status === 'delivered'
                            ? 'bg-green-100 text-green-800'
                            : ev.status === 'deferred'
                            ? 'bg-amber-100 text-amber-800'
                            : ev.status === 'bounced'
                            ? 'bg-red-100 text-red-800'
                            : 'bg-slate-100 text-slate-800'
                        }`}>
                          {ev.status}
                        </span>
                      </td>
                      <td className="py-2 px-3 font-mono text-[11px] text-slate-500">
                        {ev.queue_id || '—'}
                      </td>
                      <td className="py-2 px-3 text-slate-500 text-[11px]">
                        {ev.occurred_at ? formatDate(ev.occurred_at) : '—'}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      </div>

      {/* Section 3: Abuse & Security Events */}
      <div className="rounded-xl border bg-white shadow-sm overflow-hidden">
        <div className="p-5 border-b flex items-center justify-between">
          <div className="flex items-center gap-2.5">
            <ShieldAlert className="h-5 w-5 text-indigo-600" />
            <div>
              <h3 className="font-semibold text-sm text-gray-900">Security & Deliverability Events</h3>
              <p className="text-xs text-muted-foreground">
                Automated reputation monitoring, bounce rate threshold alerts, and incident ledger
              </p>
            </div>
          </div>
          <span className={`text-xs font-semibold px-2.5 py-0.5 rounded-full ${
            openIncidentsCount > 0 
              ? 'bg-red-100 text-red-800' 
              : 'bg-green-100 text-green-800'
          }`}>
            {openIncidentsCount > 0 ? `${openIncidentsCount} Open Incident(s)` : 'Clean Reputation'}
          </span>
        </div>

        <div className="p-5">
          {(!abuse?.incidents || abuse.incidents.length === 0) ? (
            <div className="rounded-lg border border-green-200 bg-green-50/60 p-4 text-xs text-green-900 flex items-start gap-3">
              <CheckCircle2 className="h-5 w-5 text-green-600 shrink-0 mt-0.5" />
              <div>
                <strong className="font-semibold block">No Security or Deliverability Incidents Detected</strong>
                <p className="text-green-800 mt-0.5 leading-relaxed">
                  Your tenant account has zero open abuse alerts. Hard bounce rates and outbound SMTP delivery policies are well within acceptable parameters.
                </p>
              </div>
            </div>
          ) : (
            <div className="space-y-3">
              {abuse.incidents.map((inc) => (
                <div 
                  key={inc.id} 
                  className={`p-4 rounded-lg border text-xs space-y-2 ${
                    inc.status === 'open' 
                      ? 'border-red-200 bg-red-50/40 text-red-950' 
                      : 'border-slate-200 bg-slate-50 text-slate-800'
                  }`}
                >
                  <div className="flex items-center justify-between">
                    <div className="flex items-center gap-2">
                      <span className={`px-2 py-0.5 rounded text-[10px] font-bold uppercase ${
                        inc.severity === 'critical' 
                          ? 'bg-red-200 text-red-900' 
                          : inc.severity === 'high' 
                          ? 'bg-orange-200 text-orange-900' 
                          : 'bg-amber-100 text-amber-800'
                      }`}>
                        {inc.severity}
                      </span>
                      <strong className="font-semibold text-sm">{inc.summary}</strong>
                    </div>
                    <span className="text-[11px] text-slate-500 font-mono">
                      {inc.occurred_at ? formatDate(inc.occurred_at) : '—'}
                    </span>
                  </div>

                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-2 text-[11px] text-slate-600">
                    <div>
                      <span>Incident Type: </span>
                      <strong className="font-mono">{inc.incident_type}</strong>
                    </div>
                    {inc.mailbox_email && (
                      <div>
                        <span>Mailbox: </span>
                        <strong className="font-mono">{inc.mailbox_email}</strong>
                      </div>
                    )}
                  </div>

                  {inc.suggested_remediation && (
                    <div className="bg-white p-2.5 rounded border border-slate-200 text-slate-700 text-[11px] mt-1">
                      <strong className="text-slate-900 font-semibold block">Suggested Remediation:</strong>
                      {inc.suggested_remediation}
                    </div>
                  )}
                </div>
              ))}
            </div>
          )}
        </div>
      </div>

      {/* Section 4: DMARC Reporting Capability Posture */}
      <div className="rounded-xl border bg-slate-50/70 p-5 shadow-sm space-y-3">
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
          <div className="flex items-center gap-2.5">
            <FileText className="h-5 w-5 text-indigo-600" />
            <div>
              <h3 className="font-semibold text-sm text-gray-900">DMARC Reporting Capability Posture</h3>
              <p className="text-xs text-muted-foreground">
                RFC 7489 aggregate report analysis & automated mailbox ingestion status
              </p>
            </div>
          </div>
          <div className="flex items-center gap-2">
            <span className="px-2 py-0.5 rounded text-[11px] font-medium bg-green-100 text-green-800 border border-green-200">
              XML Parser Available
            </span>
            <span className="px-2 py-0.5 rounded text-[11px] font-medium bg-amber-100 text-amber-800 border border-amber-200">
              Inbound Pipe: Pending Infra
            </span>
          </div>
        </div>

        <p className="text-xs text-slate-600 leading-relaxed">
          {dmarc?.automated_collection_note || 'Automated ingestion via inbound IMAP mailbox polling or Postfix mail pipe daemon requires dedicated mail-routing infrastructure approval. Manual XML aggregate report analysis is fully supported via Domain Setup.'}
        </p>

        <div className="pt-1 flex items-center justify-between">
          <span className="text-[11px] text-slate-500 font-mono">
            Supported formats: {dmarc?.parser_format || 'RFC 7489 XML (up to 5MB)'}
          </span>
          <Link href="/domains">
            <Button size="sm" variant="outline" className="text-xs h-7">
              Inspect Reports on Domain Setup
            </Button>
          </Link>
        </div>
      </div>

      {/* Quick Actions & Recent Invoices */}
      <QuickActions />

      <div className="grid gap-4 md:grid-cols-2">
        {/* Recent Domains */}
        <Card>
          <CardHeader>
            <CardTitle className="text-base">Recent Domains</CardTitle>
          </CardHeader>
          <CardContent>
            {stats.recent_domains.length === 0 ? (
              <p className="text-sm text-muted-foreground text-center py-4">No domains added yet.</p>
            ) : (
              <div className="space-y-4">
                {stats.recent_domains.map((domain) => (
                  <div key={domain.id} className="flex items-center justify-between border-b pb-2 last:border-0 last:pb-0">
                    <div>
                      <p className="font-medium text-sm">{domain.domain_name}</p>
                      <p className="text-xs text-muted-foreground">{formatDate(domain.created_at)}</p>
                    </div>
                    <div className="flex items-center gap-3">
                      <span className="text-xs text-muted-foreground">{domain.mailboxes_count} mailboxes</span>
                      <Badge variant={domain.status === 'active' ? 'success' : domain.status === 'failed' ? 'destructive' : 'secondary'}>
                        {domain.status}
                      </Badge>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </CardContent>
        </Card>

        {/* Recent Invoices */}
        <Card>
          <CardHeader>
            <CardTitle className="text-base">Recent Invoices</CardTitle>
          </CardHeader>
          <CardContent>
            {stats.recent_invoices.length === 0 ? (
              <p className="text-sm text-muted-foreground text-center py-4">No invoices yet.</p>
            ) : (
              <div className="space-y-4">
                {stats.recent_invoices.map((invoice) => (
                  <div key={invoice.id} className="flex items-center justify-between border-b pb-2 last:border-0 last:pb-0">
                    <div>
                      <p className="font-medium text-sm">{invoice.invoice_number}</p>
                      <p className="text-xs text-muted-foreground">{formatDate(invoice.created_at)}</p>
                    </div>
                    <div className="flex items-center gap-3">
                      <span className="font-medium text-xs">{invoice.formatted_total}</span>
                      <Badge variant={invoice.status === 'paid' ? 'success' : invoice.status === 'failed' ? 'destructive' : 'secondary'}>
                        {invoice.status}
                      </Badge>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </CardContent>
        </Card>
      </div>
    </div>
  );
}
