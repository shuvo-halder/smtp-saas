'use client';

import { useState } from 'react';
import {
  useSmtpSettings,
  useSmtpUsage,
  useSmtpCredentials,
  useSmtpActivity,
  useSmtpDiagnostics,
} from '@/hooks/useTenantSmtp';
import { SmtpCredential } from '@/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import {
  Server,
  KeyRound,
  BarChart3,
  ListOrdered,
  Stethoscope,
  Copy,
  Check,
  AlertCircle,
  CheckCircle2,
  AlertTriangle,
  RefreshCw,
  Search,
  ChevronLeft,
  ChevronRight,
  ShieldCheck,
  Terminal,
  HelpCircle,
  ExternalLink,
} from 'lucide-react';
import { cn } from '@/lib/utils';

type TabType = 'settings' | 'usage' | 'credentials' | 'activity' | 'troubleshooting';
type SnippetLang = 'nodejs' | 'python' | 'php' | 'env';

export default function TenantSmtpPage() {
  const [activeTab, setActiveTab] = useState<TabType>('settings');
  const [snippetLang, setSnippetLang] = useState<SnippetLang>('nodejs');
  const [copiedKey, setCopiedKey] = useState<string | null>(null);

  // Activity logs filters
  const [activityPage, setActivityPage] = useState(1);
  const [activityStatus, setActivityStatus] = useState('');
  const [activitySearch, setActivitySearch] = useState('');

  // Password rotation modal state
  const [rotatingMailbox, setRotatingMailbox] = useState<SmtpCredential | null>(null);
  const [isRotating, setIsRotating] = useState(false);
  const [rotatedResult, setRotatedResult] = useState<{ password: string; email: string } | null>(null);
  const [rotateError, setRotateError] = useState<string | null>(null);

  // SWR Data Hooks
  const { settings, isLoading: settingsLoading } = useSmtpSettings();
  const { usage, isLoading: usageLoading, mutate: refreshUsage } = useSmtpUsage();
  const { credentials, isLoading: credsLoading, rotatePassword } = useSmtpCredentials();
  const { logs, isLoading: logsLoading, mutate: refreshLogs } = useSmtpActivity({
    page: activityPage,
    per_page: 15,
    status: activityStatus,
    search: activitySearch,
  });
  const { diagnostics, isLoading: diagLoading, runDiagnostics } = useSmtpDiagnostics();

  const handleCopy = (text: string, key: string) => {
    navigator.clipboard.writeText(text);
    setCopiedKey(key);
    setTimeout(() => setCopiedKey(null), 2500);
  };

  const handleRotatePassword = async () => {
    if (!rotatingMailbox) return;
    setIsRotating(true);
    setRotateError(null);
    try {
      const res = await rotatePassword(rotatingMailbox.id);
      setRotatedResult({
        email: res.email,
        password: res.generated_password,
      });
    } catch (err: any) {
      setRotateError(err.response?.data?.message || 'Failed to rotate SMTP password.');
    } finally {
      setIsRotating(false);
    }
  };

  const closeRotateModal = () => {
    setRotatingMailbox(null);
    setRotatedResult(null);
    setRotateError(null);
  };

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-gray-900">
            SMTP Access & Deliverability
          </h1>
          <p className="text-sm text-muted-foreground mt-1">
            Connect external applications, monitor outbound sending quotas, inspect delivery activity, and troubleshoot issues.
          </p>
        </div>
        <div className="flex items-center gap-2">
          {diagnostics && (
            <div
              className={cn(
                'flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold border',
                diagnostics.status === 'healthy'
                  ? 'bg-emerald-50 text-emerald-700 border-emerald-200'
                  : diagnostics.status === 'warning'
                  ? 'bg-amber-50 text-amber-700 border-amber-200'
                  : 'bg-rose-50 text-rose-700 border-rose-200'
              )}
            >
              {diagnostics.status === 'healthy' ? (
                <CheckCircle2 className="h-4 w-4 text-emerald-600" />
              ) : (
                <AlertTriangle className="h-4 w-4 text-amber-600" />
              )}
              <span>System Status: {diagnostics.status.toUpperCase()}</span>
            </div>
          )}
        </div>
      </div>

      {/* Tabs Bar */}
      <div className="flex border-b border-gray-200 overflow-x-auto space-x-1 sm:space-x-4">
        <button
          onClick={() => setActiveTab('settings')}
          className={cn(
            'flex items-center gap-2 py-3 px-3 text-sm font-medium border-b-2 whitespace-nowrap transition-colors',
            activeTab === 'settings'
              ? 'border-indigo-600 text-indigo-600'
              : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
          )}
        >
          <Server className="h-4 w-4" />
          Settings & Code
        </button>

        <button
          onClick={() => setActiveTab('usage')}
          className={cn(
            'flex items-center gap-2 py-3 px-3 text-sm font-medium border-b-2 whitespace-nowrap transition-colors',
            activeTab === 'usage'
              ? 'border-indigo-600 text-indigo-600'
              : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
          )}
        >
          <BarChart3 className="h-4 w-4" />
          Quota & Usage
          {usage && usage.daily_limit > 0 && (
            <span className="ml-1 px-1.5 py-0.5 text-xs bg-gray-100 rounded-full text-gray-600">
              {usage.percent_used}%
            </span>
          )}
        </button>

        <button
          onClick={() => setActiveTab('credentials')}
          className={cn(
            'flex items-center gap-2 py-3 px-3 text-sm font-medium border-b-2 whitespace-nowrap transition-colors',
            activeTab === 'credentials'
              ? 'border-indigo-600 text-indigo-600'
              : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
          )}
        >
          <KeyRound className="h-4 w-4" />
          Credentials
        </button>

        <button
          onClick={() => setActiveTab('activity')}
          className={cn(
            'flex items-center gap-2 py-3 px-3 text-sm font-medium border-b-2 whitespace-nowrap transition-colors',
            activeTab === 'activity'
              ? 'border-indigo-600 text-indigo-600'
              : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
          )}
        >
          <ListOrdered className="h-4 w-4" />
          Activity Logs
        </button>

        <button
          onClick={() => setActiveTab('troubleshooting')}
          className={cn(
            'flex items-center gap-2 py-3 px-3 text-sm font-medium border-b-2 whitespace-nowrap transition-colors',
            activeTab === 'troubleshooting'
              ? 'border-indigo-600 text-indigo-600'
              : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
          )}
        >
          <Stethoscope className="h-4 w-4" />
          Diagnostics & Help
        </button>
      </div>

      {/* ─────────────────── TAB 1: SETTINGS & CODE ─────────────────── */}
      {activeTab === 'settings' && (
        <div className="space-y-6">
          {settingsLoading ? (
            <div className="py-12 flex justify-center">
              <Spinner className="h-8 w-8 text-indigo-600" />
            </div>
          ) : settings ? (
            <>
              {/* Hostname & Specs Grid */}
              <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                <Card className="md:col-span-1">
                  <CardHeader className="pb-3">
                    <CardTitle className="text-sm font-medium text-gray-500">
                      SMTP Hostname
                    </CardTitle>
                  </CardHeader>
                  <CardContent>
                    <div className="flex items-center justify-between bg-gray-50 p-2.5 rounded-lg border">
                      <span className="font-mono text-base font-semibold text-gray-900">
                        {settings.hostname}
                      </span>
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => handleCopy(settings.hostname, 'host')}
                        className="h-8 px-2 text-gray-600"
                      >
                        {copiedKey === 'host' ? (
                          <Check className="h-4 w-4 text-emerald-600" />
                        ) : (
                          <Copy className="h-4 w-4" />
                        )}
                      </Button>
                    </div>
                    <p className="text-xs text-muted-foreground mt-2">
                      Use this endpoint for all external client connections.
                    </p>
                  </CardContent>
                </Card>

                <Card className="md:col-span-1">
                  <CardHeader className="pb-3">
                    <CardTitle className="text-sm font-medium text-gray-500">
                      Authentication
                    </CardTitle>
                  </CardHeader>
                  <CardContent>
                    <div className="text-base font-semibold text-gray-900">
                      {settings.authentication}
                    </div>
                    <p className="text-xs text-muted-foreground mt-2">
                      Username: Full email address (<span className="font-mono">{settings.username_format}</span>)
                    </p>
                  </CardContent>
                </Card>

                <Card className="md:col-span-1">
                  <CardHeader className="pb-3">
                    <CardTitle className="text-sm font-medium text-gray-500">
                      Security & Encryption
                    </CardTitle>
                  </CardHeader>
                  <CardContent>
                    <div className="flex items-center gap-2">
                      <ShieldCheck className="h-5 w-5 text-emerald-600" />
                      <span className="text-base font-semibold text-gray-900">
                        {settings.tls_minimum_version} Enforced
                      </span>
                    </div>
                    <p className="text-xs text-muted-foreground mt-2">
                      Cleartext auth without TLS is rejected by the server.
                    </p>
                  </CardContent>
                </Card>
              </div>

              {/* Supported Ports Table */}
              <Card>
                <CardHeader>
                  <CardTitle className="text-lg">Supported Connection Ports</CardTitle>
                  <CardDescription>
                    Configure your mail clients or backend services with the appropriate port.
                  </CardDescription>
                </CardHeader>
                <CardContent>
                  <div className="overflow-x-auto">
                    <table className="w-full text-sm text-left border rounded-lg overflow-hidden">
                      <thead className="bg-gray-50 border-b text-xs font-semibold uppercase text-gray-600">
                        <tr>
                          <th className="px-4 py-3">Port</th>
                          <th className="px-4 py-3">Protocol / Encryption</th>
                          <th className="px-4 py-3">Recommended Use</th>
                          <th className="px-4 py-3">Status</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y text-gray-800">
                        {settings.ports.map((p) => (
                          <tr key={p.port} className="hover:bg-gray-50/50">
                            <td className="px-4 py-3.5 font-mono font-bold text-gray-900">
                              {p.port}
                            </td>
                            <td className="px-4 py-3.5 font-medium">{p.protocol}</td>
                            <td className="px-4 py-3.5 text-gray-600">{p.description}</td>
                            <td className="px-4 py-3.5">
                              {p.recommended ? (
                                <Badge className="bg-emerald-100 text-emerald-800 border-emerald-200">
                                  Recommended
                                </Badge>
                              ) : (
                                <Badge variant="outline" className="text-gray-600">
                                  Supported
                                </Badge>
                              )}
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                </CardContent>
              </Card>

              {/* Code Snippets Card */}
              <Card>
                <CardHeader>
                  <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <div>
                      <CardTitle className="text-lg flex items-center gap-2">
                        <Terminal className="h-5 w-5 text-indigo-600" />
                        Code Integration Snippets
                      </CardTitle>
                      <CardDescription>
                        Drop-in configurations for popular languages and frameworks.
                      </CardDescription>
                    </div>

                    <div className="flex items-center gap-1 bg-gray-100 p-1 rounded-lg">
                      {(['nodejs', 'python', 'php', 'env'] as SnippetLang[]).map((lang) => (
                        <button
                          key={lang}
                          onClick={() => setSnippetLang(lang)}
                          className={cn(
                            'px-3 py-1 text-xs font-medium rounded-md transition-colors',
                            snippetLang === lang
                              ? 'bg-white text-gray-900 shadow-sm'
                              : 'text-gray-600 hover:text-gray-900'
                          )}
                        >
                          {lang === 'nodejs'
                            ? 'Node.js'
                            : lang === 'python'
                            ? 'Python'
                            : lang === 'php'
                            ? 'PHP'
                            : '.ENV'}
                        </button>
                      ))}
                    </div>
                  </div>
                </CardHeader>
                <CardContent>
                  <div className="relative">
                    <pre className="bg-gray-900 text-gray-100 p-4 rounded-lg font-mono text-xs overflow-x-auto leading-relaxed border border-gray-800">
                      {settings.code_snippets[snippetLang]}
                    </pre>
                    <Button
                      variant="ghost"
                      size="sm"
                      onClick={() =>
                        handleCopy(settings.code_snippets[snippetLang], `snippet-${snippetLang}`)
                      }
                      className="absolute top-3 right-3 bg-gray-800/80 hover:bg-gray-700 text-gray-200 h-8 px-2.5 text-xs"
                    >
                      {copiedKey === `snippet-${snippetLang}` ? (
                        <>
                          <Check className="h-3.5 w-3.5 mr-1 text-emerald-400" />
                          Copied
                        </>
                      ) : (
                        <>
                          <Copy className="h-3.5 w-3.5 mr-1" />
                          Copy
                        </>
                      )}
                    </Button>
                  </div>
                </CardContent>
              </Card>
            </>
          ) : (
            <div className="text-center py-8 text-gray-500">Failed to load SMTP settings.</div>
          )}
        </div>
      )}

      {/* ─────────────────── TAB 2: QUOTA & USAGE ─────────────────── */}
      {activeTab === 'usage' && (
        <div className="space-y-6">
          {usageLoading ? (
            <div className="py-12 flex justify-center">
              <Spinner className="h-8 w-8 text-indigo-600" />
            </div>
          ) : usage ? (
            <>
              {/* Stat Cards */}
              <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <Card>
                  <CardHeader className="pb-2">
                    <CardTitle className="text-xs font-semibold text-gray-500 uppercase">
                      Sent Today (Recipients)
                    </CardTitle>
                  </CardHeader>
                  <CardContent>
                    <div className="text-2xl font-bold text-gray-900">
                      {usage.used_today.toLocaleString()}
                    </div>
                    <p className="text-xs text-muted-foreground mt-1">
                      {usage.is_unlimited
                        ? 'Unlimited Daily Plan'
                        : `of ${usage.daily_limit.toLocaleString()} max recipients`}
                    </p>
                  </CardContent>
                </Card>

                <Card>
                  <CardHeader className="pb-2">
                    <CardTitle className="text-xs font-semibold text-gray-500 uppercase">
                      Remaining Allowance
                    </CardTitle>
                  </CardHeader>
                  <CardContent>
                    <div className="text-2xl font-bold text-emerald-600">
                      {usage.remaining_today !== null
                        ? usage.remaining_today.toLocaleString()
                        : 'Unlimited'}
                    </div>
                    <p className="text-xs text-muted-foreground mt-1">
                      Resets automatically at 00:00 UTC
                    </p>
                  </CardContent>
                </Card>

                <Card>
                  <CardHeader className="pb-2">
                    <CardTitle className="text-xs font-semibold text-gray-500 uppercase">
                      Mailbox Daily Limit
                    </CardTitle>
                  </CardHeader>
                  <CardContent>
                    <div className="text-2xl font-bold text-gray-900">
                      {usage.mailbox_daily_limit > 0
                        ? usage.mailbox_daily_limit.toLocaleString()
                        : 'Unlimited'}
                    </div>
                    <p className="text-xs text-muted-foreground mt-1">
                      Per individual sender identity
                    </p>
                  </CardContent>
                </Card>

                <Card>
                  <CardHeader className="pb-2">
                    <CardTitle className="text-xs font-semibold text-gray-500 uppercase">
                      Plan Tier
                    </CardTitle>
                  </CardHeader>
                  <CardContent>
                    <div className="text-2xl font-bold text-indigo-600">
                      {usage.plan_name}
                    </div>
                    <p className="text-xs text-muted-foreground mt-1">
                      Subscription active
                    </p>
                  </CardContent>
                </Card>
              </div>

              {/* Progress Bar Card */}
              {!usage.is_unlimited && (
                <Card>
                  <CardHeader className="pb-2">
                    <div className="flex justify-between items-center">
                      <CardTitle className="text-sm font-semibold text-gray-700">
                        Daily Recipient Utilization
                      </CardTitle>
                      <span className="text-sm font-bold text-gray-900">
                        {usage.percent_used}%
                      </span>
                    </div>
                  </CardHeader>
                  <CardContent>
                    <div className="w-full bg-gray-100 rounded-full h-3 overflow-hidden">
                      <div
                        className={cn(
                          'h-full rounded-full transition-all duration-500',
                          usage.percent_used >= 90
                            ? 'bg-rose-500'
                            : usage.percent_used >= 70
                            ? 'bg-amber-500'
                            : 'bg-indigo-600'
                        )}
                        style={{ width: `${Math.min(100, usage.percent_used)}%` }}
                      />
                    </div>
                    <div className="flex justify-between text-xs text-muted-foreground mt-2">
                      <span>0 used</span>
                      <span>{usage.daily_limit.toLocaleString()} max</span>
                    </div>
                  </CardContent>
                </Card>
              )}

              {/* 14-Day History Table */}
              <Card>
                <CardHeader>
                  <CardTitle className="text-lg">14-Day Sending Volume History</CardTitle>
                  <CardDescription>
                    Aggregated daily recipient deliveries tracked from MariaDB and real-time Redis telemetry.
                  </CardDescription>
                </CardHeader>
                <CardContent>
                  <div className="overflow-x-auto">
                    <table className="w-full text-sm text-left border rounded-lg overflow-hidden">
                      <thead className="bg-gray-50 border-b text-xs font-semibold uppercase text-gray-600">
                        <tr>
                          <th className="px-4 py-3">Date</th>
                          <th className="px-4 py-3">Recipients Sent</th>
                          <th className="px-4 py-3">Status</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y text-gray-800">
                        {usage.history.map((h) => (
                          <tr
                            key={h.date}
                            className={cn('hover:bg-gray-50/50', h.is_today && 'bg-indigo-50/40')}
                          >
                            <td className="px-4 py-3 font-medium">
                              {h.date} {h.is_today && <span className="text-xs text-indigo-600 font-bold ml-1">(Today - Live)</span>}
                            </td>
                            <td className="px-4 py-3 font-mono font-semibold">
                              {h.recipient_count.toLocaleString()}
                            </td>
                            <td className="px-4 py-3">
                              {h.recipient_count > 0 ? (
                                <Badge variant="outline" className="text-emerald-700 bg-emerald-50 border-emerald-200">
                                  Active
                                </Badge>
                              ) : (
                                <span className="text-xs text-gray-400">Idle</span>
                              )}
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                </CardContent>
              </Card>

              {/* Mailbox Breakdown Table */}
              <Card>
                <CardHeader>
                  <CardTitle className="text-lg">Mailbox Sending Breakdown Today</CardTitle>
                  <CardDescription>
                    Individual recipient usage per authenticated mailbox.
                  </CardDescription>
                </CardHeader>
                <CardContent>
                  {usage.mailboxes_breakdown.length === 0 ? (
                    <div className="text-center py-6 text-gray-500 text-sm">
                      No mailboxes found. Create mailboxes in the Mailboxes section.
                    </div>
                  ) : (
                    <div className="overflow-x-auto">
                      <table className="w-full text-sm text-left border rounded-lg overflow-hidden">
                        <thead className="bg-gray-50 border-b text-xs font-semibold uppercase text-gray-600">
                          <tr>
                            <th className="px-4 py-3">Mailbox Email</th>
                            <th className="px-4 py-3">Display Name</th>
                            <th className="px-4 py-3">Sent Today</th>
                            <th className="px-4 py-3">Mailbox Cap</th>
                            <th className="px-4 py-3">Status</th>
                          </tr>
                        </thead>
                        <tbody className="divide-y text-gray-800">
                          {usage.mailboxes_breakdown.map((mb) => (
                            <tr key={mb.id} className="hover:bg-gray-50/50">
                              <td className="px-4 py-3 font-medium font-mono text-gray-900">
                                {mb.email}
                              </td>
                              <td className="px-4 py-3 text-gray-600">
                                {mb.display_name || '—'}
                              </td>
                              <td className="px-4 py-3 font-mono font-bold">
                                {mb.used_today}
                              </td>
                              <td className="px-4 py-3 text-gray-600">
                                {mb.mailbox_daily_limit > 0 ? mb.mailbox_daily_limit : 'Unlimited'}
                              </td>
                              <td className="px-4 py-3">
                                {mb.is_active ? (
                                  <Badge className="bg-emerald-100 text-emerald-800 border-emerald-200">
                                    Active
                                  </Badge>
                                ) : (
                                  <Badge variant="outline" className="text-gray-500">
                                    Disabled
                                  </Badge>
                                )}
                              </td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  )}
                </CardContent>
              </Card>
            </>
          ) : (
            <div className="text-center py-8 text-gray-500">Failed to load quota telemetry.</div>
          )}
        </div>
      )}

      {/* ─────────────────── TAB 3: CREDENTIALS ─────────────────── */}
      {activeTab === 'credentials' && (
        <div className="space-y-6">
          <Card>
            <CardHeader>
              <CardTitle className="text-lg">Authorized SMTP Sender Identities</CardTitle>
              <CardDescription>
                In the EmailSaaS architecture, your mailbox email is your canonical SMTP username. You can rotate passwords securely below.
              </CardDescription>
            </CardHeader>
            <CardContent>
              {credsLoading ? (
                <div className="py-12 flex justify-center">
                  <Spinner className="h-8 w-8 text-indigo-600" />
                </div>
              ) : credentials && credentials.length > 0 ? (
                <div className="overflow-x-auto">
                  <table className="w-full text-sm text-left border rounded-lg overflow-hidden">
                    <thead className="bg-gray-50 border-b text-xs font-semibold uppercase text-gray-600">
                      <tr>
                        <th className="px-4 py-3">SMTP Username (Email)</th>
                        <th className="px-4 py-3">Domain</th>
                        <th className="px-4 py-3">Display Name</th>
                        <th className="px-4 py-3">Status</th>
                        <th className="px-4 py-3 text-right">Actions</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y text-gray-800">
                      {credentials.map((cred) => (
                        <tr key={cred.id} className="hover:bg-gray-50/50">
                          <td className="px-4 py-3 font-mono font-medium text-gray-900">
                            {cred.email}
                          </td>
                          <td className="px-4 py-3 text-gray-600">{cred.domain_name}</td>
                          <td className="px-4 py-3 text-gray-600">{cred.display_name || '—'}</td>
                          <td className="px-4 py-3">
                            {cred.is_active ? (
                              <Badge className="bg-emerald-100 text-emerald-800 border-emerald-200">
                                Active
                              </Badge>
                            ) : (
                              <Badge variant="outline" className="text-gray-500">
                                Disabled
                              </Badge>
                            )}
                          </td>
                          <td className="px-4 py-3 text-right">
                            <Button
                              variant="outline"
                              size="sm"
                              onClick={() => setRotatingMailbox(cred)}
                              className="text-xs h-8"
                            >
                              <KeyRound className="h-3.5 w-3.5 mr-1 text-indigo-600" />
                              Rotate Password
                            </Button>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              ) : (
                <div className="text-center py-10 bg-gray-50 border rounded-lg">
                  <AlertCircle className="h-8 w-8 text-gray-400 mx-auto mb-2" />
                  <p className="text-sm font-medium text-gray-700">No active mailboxes available</p>
                  <p className="text-xs text-gray-500 mt-1">
                    Create a mailbox under a verified domain to use as an SMTP credential.
                  </p>
                </div>
              )}
            </CardContent>
          </Card>
        </div>
      )}

      {/* ─────────────────── TAB 4: ACTIVITY LOGS ─────────────────── */}
      {activeTab === 'activity' && (
        <div className="space-y-6">
          {/* Filter Bar */}
          <div className="bg-white p-4 rounded-lg border shadow-sm flex flex-col md:flex-row gap-3">
            <div className="relative flex-1">
              <Search className="absolute left-3 top-2.5 h-4 w-4 text-gray-400" />
              <Input
                placeholder="Search by recipient, sender, or Queue ID..."
                value={activitySearch}
                onChange={(e) => {
                  setActivitySearch(e.target.value);
                  setActivityPage(1);
                }}
                className="pl-9 text-sm"
              />
            </div>

            <div className="flex gap-2">
              <select
                value={activityStatus}
                onChange={(e) => {
                  setActivityStatus(e.target.value);
                  setActivityPage(1);
                }}
                className="rounded-md border border-gray-300 bg-white py-2 px-3 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-indigo-500"
              >
                <option value="">All Statuses</option>
                <option value="delivered">Delivered (250 OK)</option>
                <option value="deferred">Deferred (Retry)</option>
                <option value="bounced">Bounced (Hard Bounce)</option>
                <option value="rejected">Rejected (Policy / Quota)</option>
              </select>

              <Button
                variant="outline"
                size="sm"
                onClick={() => refreshLogs()}
                className="h-9 px-3 flex items-center gap-1.5"
              >
                <RefreshCw className="h-4 w-4 text-gray-600" />
                Refresh
              </Button>
            </div>
          </div>

          {/* Logs Table */}
          <Card>
            <CardHeader className="pb-3">
              <CardTitle className="text-lg">Delivery Activity Ledger</CardTitle>
              <CardDescription>
                Chronological audit of outbound messages routed through Postfix and downstream destination servers.
              </CardDescription>
            </CardHeader>
            <CardContent>
              {logsLoading ? (
                <div className="py-12 flex justify-center">
                  <Spinner className="h-8 w-8 text-indigo-600" />
                </div>
              ) : logs && logs.data.length > 0 ? (
                <>
                  <div className="overflow-x-auto">
                    <table className="w-full text-sm text-left border rounded-lg overflow-hidden">
                      <thead className="bg-gray-50 border-b text-xs font-semibold uppercase text-gray-600">
                        <tr>
                          <th className="px-4 py-3">Status</th>
                          <th className="px-4 py-3">Recipient</th>
                          <th className="px-4 py-3">Sender</th>
                          <th className="px-4 py-3">Codes / Queue</th>
                          <th className="px-4 py-3">Response Snippet</th>
                          <th className="px-4 py-3">Timestamp</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y text-gray-800">
                        {logs.data.map((log) => (
                          <tr key={log.id} className="hover:bg-gray-50/50">
                            <td className="px-4 py-3.5">
                              {log.status === 'delivered' ? (
                                <Badge className="bg-emerald-100 text-emerald-800 border-emerald-200">
                                  Delivered
                                </Badge>
                              ) : log.status === 'deferred' ? (
                                <Badge className="bg-amber-100 text-amber-800 border-amber-200">
                                  Deferred
                                </Badge>
                              ) : log.status === 'bounced' ? (
                                <Badge className="bg-rose-100 text-rose-800 border-rose-200">
                                  Bounced
                                </Badge>
                              ) : (
                                <Badge className="bg-red-100 text-red-800 border-red-200">
                                  Rejected
                                </Badge>
                              )}
                            </td>
                            <td className="px-4 py-3.5 font-mono text-xs font-semibold text-gray-900">
                              {log.recipient_email}
                            </td>
                            <td className="px-4 py-3.5 font-mono text-xs text-gray-600">
                              {log.sender_email}
                            </td>
                            <td className="px-4 py-3.5 font-mono text-xs text-gray-600">
                              <div>{log.queue_id || '—'}</div>
                              {log.dsn_code && (
                                <div className="text-gray-400 text-[11px]">
                                  DSN: {log.dsn_code} {log.smtp_code ? `(${log.smtp_code})` : ''}
                                </div>
                              )}
                            </td>
                            <td className="px-4 py-3.5 text-xs text-gray-600 max-w-xs truncate" title={log.response_text || ''}>
                              {log.response_text || '—'}
                            </td>
                            <td className="px-4 py-3.5 text-xs text-gray-500 whitespace-nowrap">
                              {log.occurred_at ? new Date(log.occurred_at).toLocaleString() : '—'}
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>

                  {/* Pagination */}
                  <div className="flex items-center justify-between border-t border-gray-200 pt-4 mt-4">
                    <div className="text-xs text-muted-foreground">
                      Showing page {logs.meta.current_page} of {logs.meta.last_page} ({logs.meta.total} total logs)
                    </div>
                    <div className="flex items-center gap-2">
                      <Button
                        variant="outline"
                        size="sm"
                        disabled={activityPage <= 1}
                        onClick={() => setActivityPage((prev) => Math.max(1, prev - 1))}
                        className="h-8 px-2.5 text-xs"
                      >
                        <ChevronLeft className="h-4 w-4 mr-1" />
                        Previous
                      </Button>
                      <Button
                        variant="outline"
                        size="sm"
                        disabled={activityPage >= logs.meta.last_page}
                        onClick={() => setActivityPage((prev) => prev + 1)}
                        className="h-8 px-2.5 text-xs"
                      >
                        Next
                        <ChevronRight className="h-4 w-4 ml-1" />
                      </Button>
                    </div>
                  </div>
                </>
              ) : (
                <div className="text-center py-12 bg-gray-50 border rounded-lg">
                  <ListOrdered className="h-8 w-8 text-gray-400 mx-auto mb-2" />
                  <p className="text-sm font-medium text-gray-700">No delivery logs found</p>
                  <p className="text-xs text-gray-500 mt-1">
                    Activity will appear here as your client sends outbound emails.
                  </p>
                </div>
              )}
            </CardContent>
          </Card>
        </div>
      )}

      {/* ─────────────────── TAB 5: TROUBLESHOOTING & DIAGNOSTICS ─────────────────── */}
      {activeTab === 'troubleshooting' && (
        <div className="space-y-6">
          {/* Diagnostics Card */}
          <Card>
            <CardHeader>
              <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div>
                  <CardTitle className="text-lg flex items-center gap-2">
                    <Stethoscope className="h-5 w-5 text-indigo-600" />
                    Account Configuration Diagnostics
                  </CardTitle>
                  <CardDescription>
                    Self-service validation of subscription, DNS, identities, and quota health.
                  </CardDescription>
                </div>
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => runDiagnostics()}
                  disabled={diagLoading}
                  className="h-9 px-3 flex items-center gap-1.5"
                >
                  <RefreshCw className={cn('h-4 w-4 text-gray-600', diagLoading && 'animate-spin')} />
                  Re-run Diagnostics
                </Button>
              </div>
            </CardHeader>
            <CardContent>
              {diagLoading ? (
                <div className="py-12 flex justify-center">
                  <Spinner className="h-8 w-8 text-indigo-600" />
                </div>
              ) : diagnostics ? (
                <div className="space-y-3">
                  {diagnostics.checks.map((check) => (
                    <div
                      key={check.key}
                      className={cn(
                        'p-4 rounded-lg border flex flex-col sm:flex-row sm:items-center justify-between gap-3',
                        check.status === 'pass'
                          ? 'bg-emerald-50/40 border-emerald-200'
                          : check.status === 'warning'
                          ? 'bg-amber-50/40 border-amber-200'
                          : 'bg-rose-50/40 border-rose-200'
                      )}
                    >
                      <div className="space-y-1">
                        <div className="flex items-center gap-2">
                          {check.status === 'pass' ? (
                            <CheckCircle2 className="h-4 w-4 text-emerald-600 shrink-0" />
                          ) : check.status === 'warning' ? (
                            <AlertTriangle className="h-4 w-4 text-amber-600 shrink-0" />
                          ) : (
                            <AlertCircle className="h-4 w-4 text-rose-600 shrink-0" />
                          )}
                          <span className="font-semibold text-sm text-gray-900">{check.title}</span>
                          <Badge
                            className={cn(
                              'text-[10px] uppercase font-bold px-1.5 py-0.5',
                              check.status === 'pass'
                                ? 'bg-emerald-100 text-emerald-800 border-emerald-200'
                                : check.status === 'warning'
                                ? 'bg-amber-100 text-amber-800 border-amber-200'
                                : 'bg-rose-100 text-rose-800 border-rose-200'
                            )}
                          >
                            {check.status}
                          </Badge>
                        </div>
                        <p className="text-xs text-gray-700 pl-6">{check.summary}</p>
                        {check.remediation && (
                          <p className="text-xs text-rose-700 font-medium pl-6">
                            Fix: {check.remediation}
                          </p>
                        )}
                      </div>
                    </div>
                  ))}
                </div>
              ) : (
                <div className="text-center py-6 text-gray-500">Failed to load diagnostics.</div>
              )}
            </CardContent>
          </Card>

          {/* Common SMTP Error Reference Table */}
          <Card>
            <CardHeader>
              <CardTitle className="text-lg flex items-center gap-2">
                <HelpCircle className="h-5 w-5 text-gray-600" />
                Common SMTP Errors & Resolutions
              </CardTitle>
              <CardDescription>
                Reference guide for standard RFC SMTP response codes and recommended fixes.
              </CardDescription>
            </CardHeader>
            <CardContent>
              <div className="overflow-x-auto">
                <table className="w-full text-sm text-left border rounded-lg overflow-hidden">
                  <thead className="bg-gray-50 border-b text-xs font-semibold uppercase text-gray-600">
                    <tr>
                      <th className="px-4 py-3">Error Code</th>
                      <th className="px-4 py-3">Probable Cause</th>
                      <th className="px-4 py-3">Recommended Action</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y text-gray-800">
                    {diagnostics?.common_errors ? (
                      diagnostics.common_errors.map((err, idx) => (
                        <tr key={idx} className="hover:bg-gray-50/50">
                          <td className="px-4 py-3 font-mono font-semibold text-rose-700 text-xs whitespace-nowrap">
                            {err.code}
                          </td>
                          <td className="px-4 py-3 text-xs text-gray-700">{err.cause}</td>
                          <td className="px-4 py-3 text-xs text-gray-900 font-medium">
                            {err.remediation}
                          </td>
                        </tr>
                      ))
                    ) : (
                      <tr>
                        <td colSpan={3} className="text-center py-4 text-gray-500 text-xs">
                          Loading error references...
                        </td>
                      </tr>
                    )}
                  </tbody>
                </table>
              </div>
            </CardContent>
          </Card>
        </div>
      )}

      {/* ─────────────────── ROTATE PASSWORD MODAL ─────────────────── */}
      {rotatingMailbox && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
          <div className="bg-white rounded-xl shadow-xl max-w-md w-full p-6 border space-y-4">
            <div className="flex items-center justify-between border-b pb-3">
              <h3 className="text-lg font-bold text-gray-900 flex items-center gap-2">
                <KeyRound className="h-5 w-5 text-indigo-600" />
                Rotate SMTP Password
              </h3>
              <button
                onClick={closeRotateModal}
                className="text-gray-400 hover:text-gray-600 text-lg font-bold"
              >
                &times;
              </button>
            </div>

            {!rotatedResult ? (
              <div className="space-y-4">
                <p className="text-sm text-gray-600">
                  Are you sure you want to regenerate the password for{' '}
                  <span className="font-mono font-semibold text-gray-900">
                    {rotatingMailbox.email}
                  </span>
                  ?
                </p>
                <div className="bg-amber-50 border border-amber-200 p-3 rounded-lg text-xs text-amber-800 space-y-1">
                  <p className="font-semibold flex items-center gap-1">
                    <AlertTriangle className="h-4 w-4 text-amber-600" />
                    Important Notice:
                  </p>
                  <p>
                    Rotating this password will immediately invalidate existing SMTP sessions and mail client logins for this mailbox.
                  </p>
                </div>

                {rotateError && (
                  <div className="bg-rose-50 border border-rose-200 p-3 rounded-lg text-xs text-rose-800">
                    {rotateError}
                  </div>
                )}

                <div className="flex justify-end gap-2 pt-2">
                  <Button variant="outline" size="sm" onClick={closeRotateModal} disabled={isRotating}>
                    Cancel
                  </Button>
                  <Button
                    size="sm"
                    onClick={handleRotatePassword}
                    disabled={isRotating}
                    className="bg-indigo-600 hover:bg-indigo-700 text-white"
                  >
                    {isRotating ? (
                      <>
                        <Spinner className="h-4 w-4 mr-2" />
                        Generating...
                      </>
                    ) : (
                      'Generate New Password'
                    )}
                  </Button>
                </div>
              </div>
            ) : (
              <div className="space-y-4">
                <div className="bg-emerald-50 border border-emerald-200 p-3 rounded-lg text-xs text-emerald-800 flex items-center gap-2">
                  <CheckCircle2 className="h-5 w-5 text-emerald-600 shrink-0" />
                  <span>Password regenerated and hashed with Dovecot SHA512-CRYPT!</span>
                </div>

                <div className="space-y-1.5">
                  <label className="text-xs font-semibold text-gray-700 uppercase">
                    Your New SMTP Password
                  </label>
                  <div className="flex items-center justify-between bg-gray-900 text-emerald-400 p-3 rounded-lg font-mono text-sm border border-gray-800">
                    <span className="select-all break-all">{rotatedResult.password}</span>
                    <Button
                      variant="ghost"
                      size="sm"
                      onClick={() => handleCopy(rotatedResult.password, 'modal-password')}
                      className="text-gray-300 hover:text-white h-7 px-2 ml-2"
                    >
                      {copiedKey === 'modal-password' ? (
                        <Check className="h-4 w-4 text-emerald-400" />
                      ) : (
                        <Copy className="h-4 w-4" />
                      )}
                    </Button>
                  </div>
                </div>

                <div className="bg-rose-50 border border-rose-200 p-3 rounded-lg text-xs text-rose-800 font-medium">
                  ⚠️ This password is shown only once and cannot be retrieved later. Copy and save it securely now!
                </div>

                <div className="flex justify-end pt-2">
                  <Button size="sm" onClick={closeRotateModal} className="bg-gray-900 hover:bg-gray-800 text-white">
                    I Have Saved My Password
                  </Button>
                </div>
              </div>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
