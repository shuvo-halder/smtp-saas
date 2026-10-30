'use client';

import React, { useState } from 'react';
import useSWR from 'swr';
import { useTranslations } from 'next-intl';
import api from '@/lib/api';
import { SystemHealthResponse, SystemHealthStatus, ScheduledTaskRunItem } from '@/types';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Spinner } from '@/components/ui/spinner';
import {
  Activity,
  CheckCircle2,
  AlertTriangle,
  XCircle,
  HelpCircle,
  RefreshCw,
  Server,
  Database,
  Cpu,
  Clock,
  Layers,
  ShieldCheck,
  ShieldAlert,
  Terminal,
  AlertCircle,
  Bell,
  Mail,
  X
} from 'lucide-react';

export default function AdminSystemHealthPage() {
  const t = useTranslations('Admin.system_health');
  const [selectedFailedTask, setSelectedFailedTask] = useState<ScheduledTaskRunItem | null>(null);

  const fetcher = (url: string) => api.get<SystemHealthResponse>(url).then((res) => res.data);

  const { data, error, isLoading, isValidating, mutate } = useSWR<SystemHealthResponse>(
    '/api/admin/system/health',
    fetcher,
    {
      refreshInterval: 30000, // 30s conservative polling
      revalidateOnFocus: true,
      dedupingInterval: 5000,
    }
  );

  // Status badge styling helper
  const getStatusBadge = (status: SystemHealthStatus) => {
    switch (status) {
      case 'HEALTHY':
        return (
          <Badge className="bg-emerald-100 text-emerald-800 border-emerald-300 font-semibold flex items-center gap-1">
            <CheckCircle2 className="w-3.5 h-3.5 text-emerald-600" />
            {t('posture.HEALTHY')}
          </Badge>
        );
      case 'DEGRADED':
        return (
          <Badge className="bg-amber-100 text-amber-800 border-amber-300 font-semibold flex items-center gap-1">
            <AlertTriangle className="w-3.5 h-3.5 text-amber-600" />
            {t('posture.DEGRADED')}
          </Badge>
        );
      case 'UNHEALTHY':
        return (
          <Badge className="bg-rose-100 text-rose-800 border-rose-300 font-semibold flex items-center gap-1">
            <XCircle className="w-3.5 h-3.5 text-rose-600" />
            {t('posture.UNHEALTHY')}
          </Badge>
        );
      case 'NOT_CONFIGURED':
        return (
          <Badge className="bg-slate-100 text-slate-700 border-slate-300 font-semibold flex items-center gap-1">
            <AlertCircle className="w-3.5 h-3.5 text-slate-500" />
            {t('posture.NOT_CONFIGURED')}
          </Badge>
        );
      case 'UNKNOWN':
      default:
        return (
          <Badge className="bg-gray-100 text-gray-700 border-gray-300 font-semibold flex items-center gap-1">
            <HelpCircle className="w-3.5 h-3.5 text-gray-500" />
            {t('posture.UNKNOWN')}
          </Badge>
        );
    }
  };

  const getTaskStatusBadge = (status: ScheduledTaskRunItem['status']) => {
    switch (status) {
      case 'success':
        return <Badge className="bg-emerald-50 text-emerald-700 border-emerald-200">{t('tasks.status.success')}</Badge>;
      case 'failed':
        return <Badge className="bg-rose-50 text-rose-700 border-rose-200 font-bold">{t('tasks.status.failed')}</Badge>;
      case 'running':
        return <Badge className="bg-blue-50 text-blue-700 border-blue-200 animate-pulse">{t('tasks.status.running')}</Badge>;
      case 'skipped':
        return <Badge className="bg-amber-50 text-amber-700 border-amber-200">{t('tasks.status.skipped')}</Badge>;
      default:
        return <Badge className="bg-gray-50 text-gray-700 border-gray-200">{status}</Badge>;
    }
  };

  // 1. Loading State
  if (isLoading && !data) {
    return (
      <div className="flex flex-col items-center justify-center min-h-[60vh] space-y-4">
        <Spinner className="w-8 h-8 text-indigo-600" />
        <p className="text-sm text-gray-500 font-medium">Loading system health diagnostics...</p>
      </div>
    );
  }

  // 2. Forbidden State (403 RBAC)
  if (error?.response?.status === 403) {
    return (
      <div className="max-w-4xl mx-auto mt-8">
        <div className="rounded-lg border border-rose-200 bg-rose-50 p-6 text-rose-900 shadow-sm">
          <div className="flex items-start gap-3">
            <ShieldAlert className="w-6 h-6 text-rose-600 flex-shrink-0 mt-0.5" />
            <div>
              <h2 className="text-lg font-bold">{t('errors.forbidden_title')}</h2>
              <p className="text-sm text-rose-700 mt-1">{t('errors.forbidden_desc')}</p>
            </div>
          </div>
        </div>
      </div>
    );
  }

  // 3. Network or Server Error State
  if (error || !data) {
    return (
      <div className="max-w-4xl mx-auto mt-8">
        <div className="rounded-lg border border-amber-200 bg-amber-50 p-6 text-amber-900 shadow-sm">
          <div className="flex items-start gap-3">
            <AlertTriangle className="w-6 h-6 text-amber-600 flex-shrink-0 mt-0.5" />
            <div className="flex-1">
              <h2 className="text-lg font-bold">{t('errors.failed_title')}</h2>
              <p className="text-sm text-amber-700 mt-1">{t('errors.failed_desc')}</p>
              <button
                onClick={() => mutate()}
                className="mt-4 inline-flex items-center gap-2 px-3.5 py-1.5 text-xs font-semibold rounded-md bg-amber-600 text-white hover:bg-amber-700 transition-colors shadow-sm"
              >
                <RefreshCw className="w-3.5 h-3.5" />
                {t('errors.retry')}
              </button>
            </div>
          </div>
        </div>
      </div>
    );
  }

  const { components, recent_tasks, status: overallStatus, timestamp, environment } = data;

  return (
    <div className="space-y-6 max-w-7xl mx-auto pb-12">
      {/* Header Bar */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-2 border-b">
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-gray-900 flex items-center gap-2">
            <Activity className="h-6 w-6 text-indigo-600" />
            {t('title')}
          </h1>
          <p className="text-sm text-gray-500 mt-1">{t('subtitle')}</p>
        </div>

        <div className="flex items-center gap-3">
          <div className="text-xs text-gray-500 text-right hidden sm:block">
            <span>{t('last_checked')}: </span>
            <span className="font-mono font-medium text-gray-700">
              {new Date(timestamp).toLocaleTimeString()}
            </span>
          </div>

          <button
            onClick={() => mutate()}
            disabled={isValidating}
            className="inline-flex items-center gap-2 px-3.5 py-2 text-xs font-medium rounded-md border border-gray-300 bg-white text-gray-700 hover:bg-gray-50 hover:text-gray-900 transition-colors shadow-sm disabled:opacity-50"
          >
            <RefreshCw className={`w-3.5 h-3.5 ${isValidating ? 'animate-spin text-indigo-600' : 'text-gray-500'}`} />
            {t('refresh')}
          </button>
        </div>
      </div>

      {/* Overall Status Banner */}
      <div
        className={`rounded-lg border p-4 shadow-sm flex items-center justify-between ${
          overallStatus === 'HEALTHY'
            ? 'bg-emerald-50/80 border-emerald-200 text-emerald-950'
            : overallStatus === 'DEGRADED'
            ? 'bg-amber-50/80 border-amber-200 text-amber-950'
            : 'bg-rose-50/80 border-rose-200 text-rose-950'
        }`}
      >
        <div className="flex items-center gap-3">
          {overallStatus === 'HEALTHY' ? (
            <CheckCircle2 className="w-6 h-6 text-emerald-600 flex-shrink-0" />
          ) : overallStatus === 'DEGRADED' ? (
            <AlertTriangle className="w-6 h-6 text-amber-600 flex-shrink-0" />
          ) : (
            <XCircle className="w-6 h-6 text-rose-600 flex-shrink-0" />
          )}
          <div>
            <h2 className="text-base font-bold">
              {overallStatus === 'HEALTHY'
                ? t('overall_status.healthy')
                : overallStatus === 'DEGRADED'
                ? t('overall_status.degraded')
                : t('overall_status.unhealthy')}
            </h2>
            <p className="text-xs text-gray-600 mt-0.5">{t('auto_refresh_note')}</p>
          </div>
        </div>
        <div className="flex items-center gap-2">
          <Badge variant="outline" className="font-mono text-xs uppercase bg-white/70">
            {environment}
          </Badge>
          {getStatusBadge(overallStatus)}
        </div>
      </div>

      {/* Component Posture Grid (7 Cards) */}
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
        {/* 1. Application Core */}
        <Card className="shadow-sm border">
          <CardHeader className="flex flex-row items-center justify-between pb-2 space-y-0">
            <CardTitle className="text-sm font-semibold text-gray-900 flex items-center gap-2">
              <Server className="w-4 h-4 text-indigo-600" />
              {t('components.app.title')}
            </CardTitle>
            {getStatusBadge(components.app.status)}
          </CardHeader>
          <CardContent className="space-y-2 pt-2 text-xs">
            <p className="text-gray-500">{t('components.app.desc')}</p>
            <div className="space-y-1.5 pt-2 border-t">
              <div className="flex justify-between">
                <span className="text-gray-500">{t('labels.framework')}:</span>
                <span className="font-mono font-medium">{components.app.details.framework}</span>
              </div>
              <div className="flex justify-between">
                <span className="text-gray-500">{t('labels.php_version')}:</span>
                <span className="font-mono font-medium">{components.app.details.php_version}</span>
              </div>
              <div className="flex justify-between">
                <span className="text-gray-500">{t('labels.timezone')}:</span>
                <span className="font-mono font-medium">{components.app.details.timezone}</span>
              </div>
            </div>
          </CardContent>
        </Card>

        {/* 2. Database Engine */}
        <Card className="shadow-sm border">
          <CardHeader className="flex flex-row items-center justify-between pb-2 space-y-0">
            <CardTitle className="text-sm font-semibold text-gray-900 flex items-center gap-2">
              <Database className="w-4 h-4 text-indigo-600" />
              {t('components.database.title')}
            </CardTitle>
            {getStatusBadge(components.database.status)}
          </CardHeader>
          <CardContent className="space-y-2 pt-2 text-xs">
            <p className="text-gray-500">{t('components.database.desc')}</p>
            <div className="space-y-1.5 pt-2 border-t">
              <div className="flex justify-between">
                <span className="text-gray-500">{t('labels.connection')}:</span>
                <span className="font-mono font-medium">{components.database.details.connection}</span>
              </div>
              {components.database.details.latency_ms !== undefined && (
                <div className="flex justify-between">
                  <span className="text-gray-500">{t('labels.latency')}:</span>
                  <span className="font-mono font-medium text-emerald-600">
                    {components.database.details.latency_ms} ms
                  </span>
                </div>
              )}
              {components.database.details.error && (
                <p className="text-rose-600 font-medium">{components.database.details.error}</p>
              )}
            </div>
          </CardContent>
        </Card>

        {/* 3. Redis Telemetry & Quotas */}
        <Card className="shadow-sm border">
          <CardHeader className="flex flex-row items-center justify-between pb-2 space-y-0">
            <CardTitle className="text-sm font-semibold text-gray-900 flex items-center gap-2">
              <Cpu className="w-4 h-4 text-indigo-600" />
              {t('components.redis.title')}
            </CardTitle>
            {getStatusBadge(components.redis.status)}
          </CardHeader>
          <CardContent className="space-y-2 pt-2 text-xs">
            <p className="text-gray-500">{t('components.redis.desc')}</p>
            <div className="space-y-1.5 pt-2 border-t">
              {components.redis.status === 'NOT_CONFIGURED' ? (
                <p className="text-gray-500 italic">{components.redis.details.reason}</p>
              ) : components.redis.status === 'DEGRADED' ? (
                <p className="text-amber-700 font-medium">{components.redis.details.error}</p>
              ) : (
                <>
                  <div className="flex justify-between">
                    <span className="text-gray-500">{t('labels.client')}:</span>
                    <span className="font-mono font-medium">{components.redis.details.client}</span>
                  </div>
                  <div className="flex justify-between">
                    <span className="text-gray-500">{t('labels.latency')}:</span>
                    <span className="font-mono font-medium text-emerald-600">
                      {components.redis.details.latency_ms} ms
                    </span>
                  </div>
                </>
              )}
            </div>
          </CardContent>
        </Card>

        {/* 4. Scheduler Freshness */}
        <Card className="shadow-sm border">
          <CardHeader className="flex flex-row items-center justify-between pb-2 space-y-0">
            <CardTitle className="text-sm font-semibold text-gray-900 flex items-center gap-2">
              <Clock className="w-4 h-4 text-indigo-600" />
              {t('components.scheduler.title')}
            </CardTitle>
            {getStatusBadge(components.scheduler.status)}
          </CardHeader>
          <CardContent className="space-y-2 pt-2 text-xs">
            <p className="text-gray-500">{t('components.scheduler.desc')}</p>
            <div className="space-y-1.5 pt-2 border-t">
              <div className="flex justify-between">
                <span className="text-gray-500">{t('labels.freshness')}:</span>
                <span className="font-medium capitalize">
                  {components.scheduler.details.freshness === 'fresh'
                    ? t('labels.fresh')
                    : components.scheduler.details.freshness === 'delayed'
                    ? t('labels.delayed')
                    : components.scheduler.details.freshness === 'stale'
                    ? t('labels.stale')
                    : 'Unknown'}
                </span>
              </div>
              <div className="flex justify-between">
                <span className="text-gray-500">{t('labels.last_run')}:</span>
                <span className="font-mono font-medium">
                  {components.scheduler.details.seconds_since_last_run !== null
                    ? `${components.scheduler.details.seconds_since_last_run} ${t('labels.seconds_ago')}`
                    : 'None'}
                </span>
              </div>
              <div className="flex justify-between">
                <span className="text-gray-500">{t('labels.registered_tasks')}:</span>
                <span className="font-mono font-medium">
                  {components.scheduler.details.registered_tasks_count}
                </span>
              </div>
            </div>
          </CardContent>
        </Card>

        {/* 5. Scheduled Task Failures */}
        <Card className="shadow-sm border">
          <CardHeader className="flex flex-row items-center justify-between pb-2 space-y-0">
            <CardTitle className="text-sm font-semibold text-gray-900 flex items-center gap-2">
              <Terminal className="w-4 h-4 text-indigo-600" />
              {t('components.task_failures.title')}
            </CardTitle>
            {getStatusBadge(components.task_failures.status)}
          </CardHeader>
          <CardContent className="space-y-2 pt-2 text-xs">
            <p className="text-gray-500">{t('components.task_failures.desc')}</p>
            <div className="space-y-1.5 pt-2 border-t">
              <div className="flex justify-between">
                <span className="text-gray-500">{t('labels.failures_24h')}:</span>
                <span
                  className={`font-mono font-bold ${
                    components.task_failures.details.failures_24h > 0 ? 'text-rose-600' : 'text-emerald-600'
                  }`}
                >
                  {components.task_failures.details.failures_24h}
                </span>
              </div>
              {components.task_failures.details.last_failed_command && (
                <div className="flex justify-between">
                  <span className="text-gray-500">{t('labels.last_failed')}:</span>
                  <span className="font-mono text-rose-700 truncate max-w-[120px]">
                    {components.task_failures.details.last_failed_command}
                  </span>
                </div>
              )}
            </div>
          </CardContent>
        </Card>

        {/* 6. Queue Subsystem */}
        <Card className="shadow-sm border">
          <CardHeader className="flex flex-row items-center justify-between pb-2 space-y-0">
            <CardTitle className="text-sm font-semibold text-gray-900 flex items-center gap-2">
              <Layers className="w-4 h-4 text-indigo-600" />
              {t('components.queue.title')}
            </CardTitle>
            {getStatusBadge(components.queue.status)}
          </CardHeader>
          <CardContent className="space-y-2 pt-2 text-xs">
            <p className="text-gray-500">{t('components.queue.desc')}</p>
            <div className="space-y-1.5 pt-2 border-t">
              <div className="flex justify-between">
                <span className="text-gray-500">{t('labels.driver')}:</span>
                <span className="font-mono font-medium">{components.queue.details.driver}</span>
              </div>
              <div className="flex justify-between">
                <span className="text-gray-500">{t('labels.pending_jobs')}:</span>
                <span className="font-mono font-medium">{components.queue.details.pending_jobs}</span>
              </div>
              <div className="flex justify-between">
                <span className="text-gray-500">{t('labels.failed_jobs')}:</span>
                <span
                  className={`font-mono font-medium ${
                    components.queue.details.failed_jobs_24h > 0 ? 'text-rose-600' : 'text-gray-700'
                  }`}
                >
                  {components.queue.details.failed_jobs_24h}
                </span>
              </div>
            </div>
          </CardContent>
        </Card>

        {/* 7. Disaster Recovery Posture */}
        <Card className="shadow-sm border sm:col-span-2 lg:col-span-2 xl:col-span-2">
          <CardHeader className="flex flex-row items-center justify-between pb-2 space-y-0">
            <CardTitle className="text-sm font-semibold text-gray-900 flex items-center gap-2">
              <ShieldCheck className="w-4 h-4 text-indigo-600" />
              {t('components.backup.title')}
            </CardTitle>
            {getStatusBadge(components.backup.status)}
          </CardHeader>
          <CardContent className="space-y-2 pt-2 text-xs">
            <p className="text-gray-500">{t('components.backup.desc')}</p>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-3 pt-2 border-t">
              <div className="space-y-1">
                <span className="text-gray-500">{t('labels.latest_db')}:</span>
                <p className="font-mono text-gray-900 truncate">
                  {components.backup.details.latest_db_backup_at
                    ? new Date(components.backup.details.latest_db_backup_at).toLocaleString()
                    : 'N/A'}
                </p>
              </div>
              <div className="space-y-1">
                <span className="text-gray-500">{t('labels.latest_vmail')}:</span>
                <p className="font-mono text-gray-900 truncate">
                  {components.backup.details.latest_vmail_backup_at
                    ? new Date(components.backup.details.latest_vmail_backup_at).toLocaleString()
                    : 'N/A'}
                </p>
              </div>
            </div>
            <p className="text-[11px] text-gray-400 italic pt-1 border-t">
              {components.backup.details.note}
            </p>
          </CardContent>
        </Card>

        {/* 8. Tenant Notification Reliability & Pruning Posture */}
        {components.notifications && (
          <Card className="shadow-sm border sm:col-span-2 lg:col-span-2 xl:col-span-2">
            <CardHeader className="flex flex-row items-center justify-between pb-2 space-y-0">
              <CardTitle className="text-sm font-semibold text-gray-900 flex items-center gap-2">
                <Bell className="w-4 h-4 text-indigo-600" />
                {t('components.notifications.title')}
              </CardTitle>
              {getStatusBadge(components.notifications.status)}
            </CardHeader>
            <CardContent className="space-y-2 pt-2 text-xs">
              <p className="text-gray-500">{t('components.notifications.desc')}</p>
              <div className="grid grid-cols-2 sm:grid-cols-4 gap-2 pt-2 border-t">
                <div className="space-y-0.5">
                  <span className="text-gray-500">{t('labels.total_alerts')}:</span>
                  <p className="font-mono font-medium text-gray-900">{components.notifications.details.total_notifications ?? 0}</p>
                </div>
                <div className="space-y-0.5">
                  <span className="text-gray-500">{t('labels.active_unread')}:</span>
                  <p className="font-mono font-medium text-blue-600">{components.notifications.details.unread_notifications ?? 0}</p>
                </div>
                <div className="space-y-0.5">
                  <span className="text-gray-500">{t('labels.critical_unresolved')}:</span>
                  <p className={`font-mono font-medium ${components.notifications.details.unresolved_critical_alerts > 0 ? 'text-rose-600 font-bold' : 'text-emerald-600'}`}>
                    {components.notifications.details.unresolved_critical_alerts ?? 0}
                  </p>
                </div>
                <div className="space-y-0.5">
                  <span className="text-gray-500">{t('labels.prune_status')}:</span>
                  <p className="font-mono text-gray-700 capitalize">{components.notifications.details.last_prune_status ?? 'pending'}</p>
                </div>
              </div>
            </CardContent>
          </Card>
        )}

        {/* 9. Mail Delivery Queue & Posture */}
        {components.mail_delivery && (
          <Card className="shadow-sm border sm:col-span-2 lg:col-span-2 xl:col-span-2">
            <CardHeader className="flex flex-row items-center justify-between pb-2 space-y-0">
              <CardTitle className="text-sm font-semibold text-gray-900 flex items-center gap-2">
                <Mail className="w-4 h-4 text-indigo-600" />
                {t('components.mail_delivery.title')}
              </CardTitle>
              {getStatusBadge(components.mail_delivery.status)}
            </CardHeader>
            <CardContent className="space-y-2 pt-2 text-xs">
              <p className="text-gray-500">{t('components.mail_delivery.desc')}</p>
              <div className="grid grid-cols-2 sm:grid-cols-4 gap-2 pt-2 border-t">
                <div className="space-y-0.5">
                  <span className="text-gray-500">{t('labels.total_deliveries')}:</span>
                  <p className="font-mono font-medium text-gray-900">{components.mail_delivery.details.total_deliveries ?? 0}</p>
                </div>
                <div className="space-y-0.5">
                  <span className="text-gray-500">{t('labels.queued_deliveries')}:</span>
                  <p className="font-mono font-medium text-blue-600">
                    {(components.mail_delivery.details.queued ?? 0) + (components.mail_delivery.details.processing ?? 0)}
                  </p>
                </div>
                <div className="space-y-0.5">
                  <span className="text-gray-500">{t('labels.failed_deliveries')}:</span>
                  <p className={`font-mono font-medium ${components.mail_delivery.details.dead_letter_count > 0 ? 'text-rose-600 font-bold' : 'text-emerald-600'}`}>
                    {components.mail_delivery.details.dead_letter_count ?? 0}
                  </p>
                </div>
                <div className="space-y-0.5">
                  <span className="text-gray-500">{t('labels.dry_run')}:</span>
                  <p className="font-mono font-medium text-slate-700">
                    {components.mail_delivery.details.dry_run_mode ? t('labels.enabled') : t('labels.disabled')}
                  </p>
                </div>
              </div>
            </CardContent>
          </Card>
        )}
      </div>

      {/* Recent Scheduled Task Executions Table */}
      <Card className="shadow-sm border">
        <CardHeader className="border-b bg-gray-50/50">
          <CardTitle className="text-base font-bold text-gray-900 flex items-center gap-2">
            <Terminal className="w-5 h-5 text-indigo-600" />
            {t('tasks.title')}
          </CardTitle>
          <CardDescription className="text-xs">{t('tasks.subtitle')}</CardDescription>
        </CardHeader>
        <CardContent className="p-0">
          {recent_tasks.length === 0 ? (
            <div className="p-8 text-center text-sm text-gray-500">{t('tasks.no_tasks')}</div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-left text-xs">
                <thead className="bg-gray-50 text-gray-500 uppercase tracking-wider font-semibold border-b">
                  <tr>
                    <th className="px-4 py-3">{t('tasks.table.task_name')}</th>
                    <th className="px-4 py-3">{t('tasks.table.expression')}</th>
                    <th className="px-4 py-3">{t('tasks.table.status')}</th>
                    <th className="px-4 py-3">{t('tasks.table.duration')}</th>
                    <th className="px-4 py-3">{t('tasks.table.started_at')}</th>
                    <th className="px-4 py-3">{t('tasks.table.finished_at')}</th>
                    <th className="px-4 py-3 text-right">{t('tasks.table.actions')}</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-100 font-mono">
                  {recent_tasks.map((task) => (
                    <tr key={task.uuid} className="hover:bg-gray-50/50 transition-colors">
                      <td className="px-4 py-3 font-semibold text-gray-900">{task.task_name}</td>
                      <td className="px-4 py-3 text-gray-500">{task.expression || 'N/A'}</td>
                      <td className="px-4 py-3">{getTaskStatusBadge(task.status)}</td>
                      <td className="px-4 py-3 text-gray-700">
                        {task.duration_ms !== null ? `${task.duration_ms} ms` : '-'}
                      </td>
                      <td className="px-4 py-3 text-gray-500">
                        {task.started_at ? new Date(task.started_at).toLocaleTimeString() : '-'}
                      </td>
                      <td className="px-4 py-3 text-gray-500">
                        {task.finished_at ? new Date(task.finished_at).toLocaleTimeString() : '-'}
                      </td>
                      <td className="px-4 py-3 text-right">
                        {task.status === 'failed' && (
                          <button
                            onClick={() => setSelectedFailedTask(task)}
                            className="inline-flex items-center text-xs font-sans text-rose-600 hover:text-rose-800 hover:underline font-semibold"
                          >
                            {t('tasks.inspect')}
                          </button>
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

      {/* Task Failure Detail Modal */}
      {selectedFailedTask && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
          <div className="bg-white rounded-lg shadow-xl max-w-lg w-full p-6 space-y-4 border">
            <div className="flex items-center justify-between border-b pb-3">
              <h3 className="text-base font-bold text-gray-900 flex items-center gap-2">
                <AlertTriangle className="w-5 h-5 text-rose-600" />
                {t('tasks.modal_title')}
              </h3>
              <button
                onClick={() => setSelectedFailedTask(null)}
                className="text-gray-400 hover:text-gray-600"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <div className="space-y-2 text-xs">
              <div>
                <span className="text-gray-500 font-semibold">{t('tasks.task_identifier')}: </span>
                <span className="font-mono text-gray-900 font-bold">{selectedFailedTask.task_name}</span>
              </div>
              <div>
                <span className="text-gray-500 font-semibold">{t('tasks.execution_uuid')}: </span>
                <span className="font-mono text-gray-700">{selectedFailedTask.uuid}</span>
              </div>
              <div>
                <span className="text-gray-500 font-semibold">{t('tasks.started_time')}: </span>
                <span className="font-mono text-gray-700">{selectedFailedTask.started_at}</span>
              </div>
              <div>
                <span className="text-gray-500 font-semibold">{t('tasks.failure_summary')}:</span>
                <div className="mt-1 p-3 bg-rose-50 border border-rose-200 rounded text-rose-950 font-mono text-[11px] break-all whitespace-pre-wrap max-h-48 overflow-y-auto">
                  {selectedFailedTask.failure_summary || 'No summary available.'}
                </div>
              </div>
            </div>

            <div className="flex justify-end pt-2 border-t">
              <button
                onClick={() => setSelectedFailedTask(null)}
                className="px-4 py-2 text-xs font-semibold rounded-md border border-gray-300 bg-white text-gray-700 hover:bg-gray-50"
              >
                {t('tasks.close')}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
