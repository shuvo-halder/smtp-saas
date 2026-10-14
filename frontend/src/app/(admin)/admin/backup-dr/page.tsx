'use client';

import { useState } from 'react';
import { useTranslations } from 'next-intl';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { 
  Database, 
  ShieldCheck, 
  Lock, 
  HardDrive, 
  RefreshCw, 
  AlertTriangle, 
  CheckCircle2, 
  Clock, 
  Server, 
  Archive, 
  FileCheck, 
  Terminal, 
  FileText,
  AlertOctagon,
  ArrowRight
} from 'lucide-react';

export default function BackupDrHealthPage() {
  const t = useTranslations('Admin.backup_dr');

  const [isRefreshing, setIsRefreshing] = useState(false);
  const [lastRefreshed, setLastRefreshed] = useState<string>(() => new Date().toISOString());

  const handleRefresh = () => {
    setIsRefreshing(true);
    setTimeout(() => {
      setLastRefreshed(new Date().toISOString());
      setIsRefreshing(false);
    }, 600);
  };

  const getStatusBadge = (status: string) => {
    switch (status) {
      case 'VERIFIED':
        return (
          <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-green-100 text-green-800 border border-green-200">
            <CheckCircle2 className="w-3 h-3 mr-1 text-green-600" />
            {t('statuses.verified')}
          </span>
        );
      case 'PARTIALLY VERIFIED':
        return (
          <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-100 text-amber-800 border border-amber-300">
            <AlertTriangle className="w-3 h-3 mr-1 text-amber-600" />
            {t('statuses.partially_verified')}
          </span>
        );
      case 'PENDING INFRASTRUCTURE':
        return (
          <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800 border border-blue-200">
            <Clock className="w-3 h-3 mr-1 text-blue-600" />
            {t('statuses.pending_infrastructure')}
          </span>
        );
      case 'NOT IMPLEMENTED':
        return (
          <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-700 border border-gray-300">
            {t('statuses.not_implemented')}
          </span>
        );
      default:
        return <Badge variant="outline">{status}</Badge>;
    }
  };

  return (
    <div className="space-y-6 max-w-7xl mx-auto">
      {/* Page Header */}
      <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
          <h1 className="text-2xl font-bold text-gray-900 flex items-center">
            <ShieldCheck className="w-7 h-7 text-indigo-600 mr-2.5" />
            {t('title')}
          </h1>
          <p className="text-sm text-gray-500 mt-1">{t('subtitle')}</p>
        </div>

        <div className="flex items-center space-x-3">
          <span className="text-xs text-gray-400">
            Checked: {new Date(lastRefreshed).toLocaleTimeString()}
          </span>
          <button
            onClick={handleRefresh}
            disabled={isRefreshing}
            className="flex items-center px-3 py-1.5 text-xs font-medium text-gray-700 bg-white hover:bg-gray-50 border rounded-md shadow-sm transition-colors disabled:opacity-50"
          >
            <RefreshCw className={`w-3.5 h-3.5 mr-1.5 ${isRefreshing ? 'animate-spin' : ''}`} />
            {t('refresh')}
          </button>
        </div>
      </div>

      {/* DR Health API Gap Alert Banner (Sections 5 & 29) */}
      <div className="bg-amber-50/80 border border-amber-200 rounded-xl p-5 shadow-sm space-y-3">
        <div className="flex items-start justify-between">
          <div className="flex items-start space-x-3">
            <AlertOctagon className="w-5 h-5 text-amber-600 shrink-0 mt-0.5" />
            <div>
              <h2 className="text-sm font-bold text-amber-900">
                {t('gap_banner.title')}
              </h2>
              <p className="text-xs text-amber-800 mt-1 leading-relaxed">
                {t('gap_banner.description')}
              </p>
            </div>
          </div>
          <span className="inline-flex items-center px-2.5 py-1 rounded-md text-[11px] font-bold bg-amber-200 text-amber-900 border border-amber-300 shrink-0">
            {t('gap_banner.badge')}
          </span>
        </div>

        {/* Minimum Read-Only API Contract Specification */}
        <div className="bg-white/80 rounded-lg p-3.5 border border-amber-200 text-xs font-mono space-y-1.5 text-gray-700">
          <p className="font-semibold text-gray-900 font-sans">{t('gap_spec.title')}</p>
          <p className="text-indigo-700">{t('gap_spec.endpoint')}</p>
          <p className="text-gray-600">{t('gap_spec.middleware')}</p>
          <p className="text-gray-500 font-sans italic text-[11px] pt-1 border-t border-amber-100">
            {t('gap_spec.security')}
          </p>
        </div>
      </div>

      {/* Verified Posture Cards Grid */}
      <div>
        <h2 className="text-base font-bold text-gray-900 mb-3">
          {t('posture.title')}
        </h2>
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
          {/* Database Backup Card */}
          <Card className="border-indigo-100 bg-white">
            <CardHeader className="flex flex-row items-center justify-between pb-2">
              <CardTitle className="text-sm font-bold text-gray-900 flex items-center">
                <Database className="w-4 h-4 mr-2 text-indigo-600" />
                {t('posture.backup_db')}
              </CardTitle>
              {getStatusBadge('VERIFIED')}
            </CardHeader>
            <CardContent className="space-y-2 text-xs text-gray-600">
              <p>{t('posture.backup_db_desc')}</p>
              <div className="pt-2 border-t space-y-1 font-mono text-[11px] text-gray-500">
                <p>Schedule: Daily at 02:00 UTC</p>
                <p>Command: php artisan backup:database</p>
                <p>Format: .sql.gz.enc (with SHA-256)</p>
              </div>
            </CardContent>
          </Card>

          {/* Mail Storage Backup Card */}
          <Card className="border-indigo-100 bg-white">
            <CardHeader className="flex flex-row items-center justify-between pb-2">
              <CardTitle className="text-sm font-bold text-gray-900 flex items-center">
                <HardDrive className="w-4 h-4 mr-2 text-indigo-600" />
                {t('posture.backup_vmail')}
              </CardTitle>
              {getStatusBadge('VERIFIED')}
            </CardHeader>
            <CardContent className="space-y-2 text-xs text-gray-600">
              <p>{t('posture.backup_vmail_desc')}</p>
              <div className="pt-2 border-t space-y-1 font-mono text-[11px] text-gray-500">
                <p>Schedule: Weekly (Sundays at 03:00 UTC)</p>
                <p>Command: php artisan backup:vmail</p>
                <p>Permissions: vmail:vmail (5000:5000)</p>
              </div>
            </CardContent>
          </Card>

          {/* Cryptographic Protection Card */}
          <Card className="border-indigo-100 bg-white">
            <CardHeader className="flex flex-row items-center justify-between pb-2">
              <CardTitle className="text-sm font-bold text-gray-900 flex items-center">
                <Lock className="w-4 h-4 mr-2 text-indigo-600" />
                {t('posture.encryption')}
              </CardTitle>
              {getStatusBadge('VERIFIED')}
            </CardHeader>
            <CardContent className="space-y-2 text-xs text-gray-600">
              <p>{t('posture.encryption_desc')}</p>
              <div className="pt-2 border-t space-y-1 font-mono text-[11px] text-gray-500">
                <p>Cipher: AES-256-CBC (PBKDF2 10k iter)</p>
                <p>Key Isolation: /etc/emailsaas/backup.key</p>
                <p>Invariant: Fail-closed if key absent</p>
              </div>
            </CardContent>
          </Card>

          {/* Provider-Neutral Offsite Card */}
          <Card className="border-indigo-100 bg-white">
            <CardHeader className="flex flex-row items-center justify-between pb-2">
              <CardTitle className="text-sm font-bold text-gray-900 flex items-center">
                <Server className="w-4 h-4 mr-2 text-indigo-600" />
                {t('posture.offsite')}
              </CardTitle>
              {getStatusBadge('PENDING INFRASTRUCTURE')}
            </CardHeader>
            <CardContent className="space-y-2 text-xs text-gray-600">
              <p>{t('posture.offsite_desc')}</p>
              <div className="pt-2 border-t space-y-1 font-mono text-[11px] text-gray-500">
                <p>Transports: rsync, scp, local, custom</p>
                <p>Pre-flight: Local verification required</p>
                <p>Failure Safety: Fail-safe local preservation</p>
              </div>
            </CardContent>
          </Card>

          {/* GFS Retention Card */}
          <Card className="border-indigo-100 bg-white">
            <CardHeader className="flex flex-row items-center justify-between pb-2">
              <CardTitle className="text-sm font-bold text-gray-900 flex items-center">
                <Archive className="w-4 h-4 mr-2 text-indigo-600" />
                {t('posture.gfs_retention')}
              </CardTitle>
              {getStatusBadge('VERIFIED')}
            </CardHeader>
            <CardContent className="space-y-2 text-xs text-gray-600">
              <p>{t('posture.gfs_retention_desc')}</p>
              <div className="pt-2 border-t space-y-1 font-mono text-[11px] text-gray-500">
                <p>Buckets: 7 Daily, 4 Weekly, 3 Monthly</p>
                <p>Invariants: Newest & single never pruned</p>
                <p>Housekeeping: Orphan .tmp reaped</p>
              </div>
            </CardContent>
          </Card>

          {/* Scheduler Cron Card */}
          <Card className="border-indigo-100 bg-white">
            <CardHeader className="flex flex-row items-center justify-between pb-2">
              <CardTitle className="text-sm font-bold text-gray-900 flex items-center">
                <Clock className="w-4 h-4 mr-2 text-indigo-600" />
                Scheduler Provisioning
              </CardTitle>
              {getStatusBadge('VERIFIED')}
            </CardHeader>
            <CardContent className="space-y-2 text-xs text-gray-600">
              <p>Automated crontab configured under www-data via Step 19 install.sh script.</p>
              <div className="pt-2 border-t space-y-1 font-mono text-[11px] text-gray-500">
                <p>Crontab: * * * * * artisan schedule:run</p>
                <p>Locking: withoutOverlapping() active</p>
                <p>Log: storage/logs/backup.log</p>
              </div>
            </CardContent>
          </Card>
        </div>
      </div>

      {/* Restore Verification Matrix */}
      <Card>
        <CardHeader className="pb-3 border-b">
          <CardTitle className="text-base font-bold text-gray-900 flex items-center">
            <FileCheck className="w-5 h-5 text-indigo-600 mr-2" />
            {t('matrix.title')}
          </CardTitle>
        </CardHeader>
        <CardContent className="p-0">
          <div className="overflow-x-auto">
            <table className="w-full text-sm text-left text-gray-500">
              <thead className="text-xs text-gray-700 uppercase bg-gray-50 border-b">
                <tr>
                  <th className="px-5 py-3">Capability / Verification Target</th>
                  <th className="px-5 py-3">Technical Verification Scope</th>
                  <th className="px-5 py-3 text-right">Verification Status</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200 text-xs">
                {/* 1. Archive Integrity */}
                <tr className="hover:bg-gray-50">
                  <td className="px-5 py-3.5 font-medium text-gray-900">
                    {t('matrix.archive_integrity')}
                  </td>
                  <td className="px-5 py-3.5 text-gray-600">
                    {t('matrix.archive_integrity_desc')}
                  </td>
                  <td className="px-5 py-3.5 text-right whitespace-nowrap">
                    {getStatusBadge('VERIFIED')}
                  </td>
                </tr>

                {/* 2. Isolated Database Schema Restore */}
                <tr className="hover:bg-gray-50">
                  <td className="px-5 py-3.5 font-medium text-gray-900">
                    {t('matrix.isolated_schema')}
                  </td>
                  <td className="px-5 py-3.5 text-gray-600">
                    {t('matrix.isolated_schema_desc')}
                  </td>
                  <td className="px-5 py-3.5 text-right whitespace-nowrap">
                    {getStatusBadge('VERIFIED')}
                  </td>
                </tr>

                {/* 3. Isolated Maildir Restore */}
                <tr className="hover:bg-gray-50">
                  <td className="px-5 py-3.5 font-medium text-gray-900">
                    {t('matrix.isolated_mail')}
                  </td>
                  <td className="px-5 py-3.5 text-gray-600">
                    {t('matrix.isolated_mail_desc')}
                  </td>
                  <td className="px-5 py-3.5 text-right whitespace-nowrap">
                    {getStatusBadge('VERIFIED')}
                  </td>
                </tr>

                {/* 4. Live MariaDB Daemon Restore */}
                <tr className="hover:bg-gray-50">
                  <td className="px-5 py-3.5 font-medium text-gray-900">
                    {t('matrix.live_mariadb')}
                  </td>
                  <td className="px-5 py-3.5 text-gray-600">
                    {t('matrix.live_mariadb_desc')}
                  </td>
                  <td className="px-5 py-3.5 text-right whitespace-nowrap">
                    {getStatusBadge('PARTIALLY VERIFIED')}
                  </td>
                </tr>

                {/* 5. Live Dovecot MDA Restore */}
                <tr className="hover:bg-gray-50">
                  <td className="px-5 py-3.5 font-medium text-gray-900">
                    {t('matrix.live_dovecot')}
                  </td>
                  <td className="px-5 py-3.5 text-gray-600">
                    {t('matrix.live_dovecot_desc')}
                  </td>
                  <td className="px-5 py-3.5 text-right whitespace-nowrap">
                    {getStatusBadge('PARTIALLY VERIFIED')}
                  </td>
                </tr>

                {/* 6. PITR */}
                <tr className="hover:bg-gray-50">
                  <td className="px-5 py-3.5 font-medium text-gray-900">
                    {t('matrix.pitr')}
                  </td>
                  <td className="px-5 py-3.5 text-gray-600">
                    {t('matrix.pitr_desc')}
                  </td>
                  <td className="px-5 py-3.5 text-right whitespace-nowrap">
                    {getStatusBadge('NOT IMPLEMENTED')}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </CardContent>
      </Card>

      {/* Operational Runbook Links */}
      <Card className="bg-slate-900 text-slate-100 border-slate-800">
        <CardHeader className="pb-2">
          <CardTitle className="text-sm font-bold flex items-center text-white">
            <Terminal className="w-4 h-4 mr-2 text-indigo-400" />
            {t('runbook.title')}
          </CardTitle>
        </CardHeader>
        <CardContent className="space-y-3 text-xs">
          <p className="text-slate-300">{t('runbook.desc')}</p>
          <div className="grid grid-cols-1 md:grid-cols-3 gap-3 pt-2 font-mono text-[11px]">
            <div className="bg-slate-800 p-2.5 rounded border border-slate-700">
              <span className="text-indigo-400 font-bold block mb-1">Scenario A: Database Corruption</span>
              <p className="text-slate-300">scripts/restore_db.sh</p>
              <p className="text-slate-400 text-[10px] mt-0.5">Stream decrypts directly into MariaDB</p>
            </div>
            <div className="bg-slate-800 p-2.5 rounded border border-slate-700">
              <span className="text-indigo-400 font-bold block mb-1">Scenario B: Mail Storage Loss</span>
              <p className="text-slate-300">scripts/restore_vmail.sh</p>
              <p className="text-slate-400 text-[10px] mt-0.5">Extracts into /var/vmail (5000:5000)</p>
            </div>
            <div className="bg-slate-800 p-2.5 rounded border border-slate-700">
              <span className="text-indigo-400 font-bold block mb-1">Scenario C: Host Destruction</span>
              <p className="text-slate-300">scripts/disaster_recovery.sh --run-migrations</p>
              <p className="text-slate-400 text-[10px] mt-0.5">Full bare-metal restore orchestrator</p>
            </div>
          </div>
        </CardContent>
      </Card>
    </div>
  );
}
