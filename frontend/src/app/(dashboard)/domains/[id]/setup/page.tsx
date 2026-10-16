'use client';

import { useState } from 'react';
import { useParams, useRouter } from 'next/navigation';
import Link from 'next/link';
import { useDomain, useDomainDnsWizard } from '@/hooks/useDomains';
import { Spinner } from '@/components/ui/spinner';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { DnsRecordTable } from '@/components/domains/DnsRecordTable';
import { 
  AlertCircle, 
  ChevronRight, 
  CheckCircle2, 
  ArrowLeft, 
  RefreshCw, 
  ShieldCheck, 
  Mail, 
  Server, 
  Globe, 
  AlertTriangle,
  ExternalLink,
  ShieldAlert
} from 'lucide-react';

export default function DomainSetupPage() {
  const params = useParams();
  const router = useRouter();
  const id = params.id as string;
  
  const { domain, isLoading: domainLoading, mutate: mutateDomain } = useDomain(id);
  const { wizard, isLoading: wizardLoading, error: wizardError, verifyDns } = useDomainDnsWizard(id);

  const [isVerifying, setIsVerifying] = useState(false);
  const [verificationFeedback, setVerificationFeedback] = useState<{
    success: boolean;
    message: string;
  } | null>(null);

  const handleVerify = async () => {
    setIsVerifying(true);
    setVerificationFeedback(null);
    try {
      const res = await verifyDns();
      await mutateDomain();
      if (res.verified) {
        setVerificationFeedback({
          success: true,
          message: 'All critical DNS records (MX, SPF, DKIM) verified successfully! Domain is active and ready for mailboxes.'
        });
      } else {
        setVerificationFeedback({
          success: false,
          message: res.message || 'DNS verification incomplete. Please make sure required records have propagated on authoritative nameservers.'
        });
      }
    } catch (err: any) {
      setVerificationFeedback({
        success: false,
        message: err.response?.data?.message || 'Verification check failed. Please check your network and try again.'
      });
    } finally {
      setIsVerifying(false);
    }
  };

  if (domainLoading || wizardLoading) {
    return (
      <div className="flex flex-col items-center justify-center min-h-[350px] space-y-3">
        <Spinner className="h-8 w-8 text-primary" />
        <p className="text-sm text-muted-foreground">Loading DNS setup wizard and deliverability diagnostics...</p>
      </div>
    );
  }

  if (wizardError || !domain) {
    return (
      <div className="rounded-lg border border-red-200 bg-red-50 p-6 text-red-800 space-y-3">
        <div className="flex items-center gap-2 font-semibold">
          <AlertCircle className="h-5 w-5 text-red-600" />
          Failed to load domain DNS diagnostics
        </div>
        <p className="text-sm">Unable to retrieve DNS records for this domain. Please ensure you have permission to view this domain.</p>
        <Button variant="outline" size="sm" onClick={() => router.push('/domains')}>
          Back to Domains
        </Button>
      </div>
    );
  }

  const score = wizard?.deliverability_score;
  const inbound = wizard?.inbound_routing;
  const outbound = wizard?.outbound_authentication;
  const infra = wizard?.infrastructure_status;
  const recommendations = wizard?.recommendations || [];
  const records = wizard?.records || domain.required_dns_records || [];

  return (
    <div className="space-y-8 max-w-6xl pb-12">
      {/* Breadcrumb Navigation */}
      <nav className="flex text-sm text-muted-foreground" aria-label="Breadcrumb">
        <ol className="inline-flex items-center space-x-1 md:space-x-2">
          <li className="inline-flex items-center">
            <Link href="/domains" className="hover:text-foreground transition-colors">Domains</Link>
          </li>
          <li>
            <div className="flex items-center">
              <ChevronRight className="w-4 h-4 mx-1 text-gray-400" />
              <Link href={`/domains/${domain.id}`} className="hover:text-foreground transition-colors">{domain.domain_name}</Link>
            </div>
          </li>
          <li>
            <div className="flex items-center">
              <ChevronRight className="w-4 h-4 mx-1 text-gray-400" />
              <span className="text-foreground font-medium">DNS Setup Wizard</span>
            </div>
          </li>
        </ol>
      </nav>

      {/* Header & Verification Trigger */}
      <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b pb-6">
        <div>
          <div className="flex items-center gap-3">
            <h1 className="text-2xl font-bold tracking-tight text-gray-900">
              DNS Setup & Deliverability Diagnostics
            </h1>
            <Badge variant={domain.status === 'active' ? 'success' : domain.status === 'failed' ? 'destructive' : 'warning'}>
              {domain.status}
            </Badge>
          </div>
          <p className="text-sm text-muted-foreground mt-1">
            Domain: <strong className="font-mono text-gray-800">@{domain.domain_name}</strong>
            {domain.last_dns_check_at && (
              <span> • Last checked: {new Date(domain.last_dns_check_at).toLocaleString()}</span>
            )}
          </p>
        </div>

        <div className="flex items-center gap-2 shrink-0">
          <Button 
            onClick={handleVerify} 
            disabled={isVerifying}
            className="flex items-center gap-2 shadow-sm"
          >
            {isVerifying ? (
              <Spinner className="h-4 w-4" />
            ) : (
              <RefreshCw className="h-4 w-4" />
            )}
            {isVerifying ? 'Checking DNS...' : 'Check DNS Records Now'}
          </Button>
          <Button variant="outline" onClick={() => router.push(`/domains/${domain.id}`)}>
            <ArrowLeft className="mr-1.5 h-4 w-4" />
            Domain Details
          </Button>
        </div>
      </div>

      {/* Verification Feedback Alert */}
      {verificationFeedback && (
        <div className={`p-4 rounded-lg flex items-start gap-3 border shadow-sm transition-all ${
          verificationFeedback.success 
            ? 'bg-green-50 border-green-200 text-green-900' 
            : 'bg-amber-50 border-amber-200 text-amber-900'
        }`}>
          {verificationFeedback.success ? (
            <CheckCircle2 className="h-5 w-5 mt-0.5 text-green-600 shrink-0" />
          ) : (
            <AlertTriangle className="h-5 w-5 mt-0.5 text-amber-600 shrink-0" />
          )}
          <div>
            <h4 className="font-semibold text-sm">
              {verificationFeedback.success ? 'DNS Verification Complete' : 'Verification Update'}
            </h4>
            <p className="text-sm mt-0.5">{verificationFeedback.message}</p>
          </div>
        </div>
      )}

      {/* Deliverability Health Score & Diagnostic Cards */}
      <div className="grid grid-cols-1 md:grid-cols-3 gap-5">
        {/* Deliverability Score Card */}
        <div className="rounded-xl border bg-white p-5 shadow-sm space-y-3">
          <div className="flex items-center justify-between">
            <span className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">Deliverability Health</span>
            <ShieldCheck className={`h-5 w-5 ${
              score?.status === 'ready' ? 'text-green-500' : score?.status === 'needs_attention' ? 'text-amber-500' : 'text-red-500'
            }`} />
          </div>
          <div className="flex items-baseline gap-2">
            <span className="text-3xl font-extrabold text-gray-900">{score?.score_percent ?? 0}%</span>
            <span className={`text-xs font-semibold px-2 py-0.5 rounded-full ${
              score?.status === 'ready' 
                ? 'bg-green-100 text-green-800' 
                : score?.status === 'needs_attention' 
                ? 'bg-amber-100 text-amber-800' 
                : 'bg-red-100 text-red-800'
            }`}>
              {score?.status_label ?? 'Incomplete'}
            </span>
          </div>
          {/* Progress bar */}
          <div className="w-full bg-gray-100 h-2 rounded-full overflow-hidden">
            <div 
              className={`h-full transition-all duration-500 rounded-full ${
                score?.status === 'ready' ? 'bg-green-500' : score?.status === 'needs_attention' ? 'bg-amber-500' : 'bg-red-500'
              }`}
              style={{ width: `${score?.score_percent ?? 0}%` }}
            />
          </div>
          <p className="text-xs text-muted-foreground leading-relaxed">{score?.summary}</p>
        </div>

        {/* Inbound Mail Routing Card */}
        <div className="rounded-xl border bg-white p-5 shadow-sm space-y-3">
          <div className="flex items-center justify-between">
            <span className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">Inbound Mail Routing</span>
            <Mail className="h-5 w-5 text-indigo-500" />
          </div>
          <div className="flex items-center gap-2">
            <span className={`text-xs font-semibold px-2 py-0.5 rounded-full ${
              inbound?.status === 'operational' 
                ? 'bg-green-100 text-green-800' 
                : inbound?.status === 'misconfigured'
                ? 'bg-amber-100 text-amber-800'
                : 'bg-red-100 text-red-800'
            }`}>
              {inbound?.status === 'operational' ? 'MX Verified' : inbound?.status === 'misconfigured' ? 'MX Conflict' : 'MX Missing'}
            </span>
            <span className="text-xs font-mono text-gray-600 truncate">{inbound?.mx_target}</span>
          </div>
          <p className="text-xs text-muted-foreground leading-relaxed">
            {inbound?.details}
          </p>
        </div>

        {/* Outbound Authentication Card */}
        <div className="rounded-xl border bg-white p-5 shadow-sm space-y-3">
          <div className="flex items-center justify-between">
            <span className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">Outbound Authentication</span>
            <ShieldAlert className="h-5 w-5 text-blue-500" />
          </div>
          <div className="flex flex-wrap gap-1.5">
            <span className={`text-[11px] font-mono px-2 py-0.5 rounded ${
              outbound?.spf_status === 'VERIFIED' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'
            }`}>
              SPF: {outbound?.spf_status}
            </span>
            <span className={`text-[11px] font-mono px-2 py-0.5 rounded ${
              outbound?.dkim_status === 'VERIFIED' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'
            }`}>
              DKIM: {outbound?.dkim_status}
            </span>
            <span className={`text-[11px] font-mono px-2 py-0.5 rounded ${
              outbound?.dmarc_status === 'VERIFIED' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-700'
            }`}>
              DMARC: {outbound?.dmarc_status}
            </span>
          </div>
          <p className="text-xs text-muted-foreground leading-relaxed">
            {outbound?.details}
          </p>
        </div>
      </div>

      {/* Multi-Layer Environment & Infrastructure Banner */}
      <div className="rounded-xl border bg-slate-50 p-4 space-y-2 text-xs">
        <div className="font-semibold text-slate-800 flex items-center gap-2">
          <Server className="h-4 w-4 text-slate-600" />
          Multi-Layer Environment Diagnostics
        </div>
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 pt-1">
          <div className="bg-white p-2.5 rounded border border-slate-200">
            <span className="text-[11px] text-muted-foreground block">DNS Publication:</span>
            <strong className="font-medium text-slate-900 capitalize">{infra?.dns_publication}</strong>
          </div>
          <div className="bg-white p-2.5 rounded border border-slate-200">
            <span className="text-[11px] text-muted-foreground block">Application Posture:</span>
            <strong className="font-medium text-slate-900 capitalize">{infra?.application_config}</strong>
          </div>
          <div className="bg-white p-2.5 rounded border border-slate-200">
            <span className="text-[11px] text-muted-foreground block">Mail Server MTA:</span>
            <strong className="font-medium text-slate-900 capitalize">{infra?.mail_server_mta?.replace('_', ' ')}</strong>
          </div>
        </div>
        <p className="text-[11px] text-slate-500 pt-1 italic">
          {infra?.mta_note}
        </p>
      </div>

      {/* Actionable Recommendations Callout */}
      {recommendations.length > 0 && (
        <div className="rounded-xl border border-blue-200 bg-blue-50/70 p-5 space-y-2.5">
          <h3 className="font-semibold text-sm text-blue-950 flex items-center gap-2">
            <Globe className="h-4 w-4 text-blue-700" />
            Recommended DNS Actions
          </h3>
          <ul className="space-y-1.5 text-xs text-blue-900">
            {recommendations.map((rec, index) => (
              <li key={index} className="flex items-start gap-2">
                <span className="h-1.5 w-1.5 rounded-full bg-blue-600 mt-1.5 shrink-0" />
                <span>{rec}</span>
              </li>
            ))}
          </ul>
          <p className="text-[11px] text-blue-700 pt-1">
            DNS updates usually take <strong>15 minutes to 2 hours</strong> to propagate worldwide depending on your DNS host's TTL settings. You can re-verify at any time.
          </p>
        </div>
      )}

      {/* DNS Records Wizard Table */}
      <div className="space-y-4">
        <div className="flex items-center justify-between">
          <div>
            <h2 className="text-lg font-semibold tracking-tight text-gray-900">
              DNS Records Configuration
            </h2>
            <p className="text-xs text-muted-foreground mt-0.5">
              Copy and paste these exact host and value settings into your domain registrar (Cloudflare, Namecheap, Route53, GoDaddy, etc.).
            </p>
          </div>
        </div>

        <DnsRecordTable records={records} showTroubleshooting={true} />
      </div>

      {/* Bottom Navigation */}
      <div className="pt-4 flex items-center justify-between">
        <Button variant="outline" onClick={() => router.push(`/domains/${domain.id}`)}>
          <ArrowLeft className="mr-2 h-4 w-4" />
          Back to Domain
        </Button>
        <Link 
          href={`/mailboxes`}
          className="text-xs text-primary hover:underline flex items-center gap-1"
        >
          Manage Mailboxes
          <ChevronRight className="h-3 w-3" />
        </Link>
      </div>
    </div>
  );
}
