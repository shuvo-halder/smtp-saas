'use client';

import { useState } from 'react';
import { useParams, useRouter } from 'next/navigation';
import Link from 'next/link';
import { 
  useDomain, 
  useDomainDnsWizard, 
  useDomainDeliverabilityReport,
  parseDomainDmarcReport
} from '@/hooks/useDomains';
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
  ShieldAlert,
  Info,
  History,
  FileText,
  UploadCloud,
  Check,
  XCircle,
  HelpCircle,
  ChevronDown,
  ChevronUp
} from 'lucide-react';
import { DmarcReportParserResponse } from '@/types';

export default function DomainSetupPage() {
  const params = useParams();
  const router = useRouter();
  const id = params.id as string;
  
  const { domain, isLoading: domainLoading, mutate: mutateDomain } = useDomain(id);
  const { wizard, isLoading: wizardLoading, error: wizardError, verifyDns } = useDomainDnsWizard(id);
  const { report, isLoading: reportLoading, mutate: mutateReport, refreshReport } = useDomainDeliverabilityReport(id);

  const [isVerifying, setIsVerifying] = useState(false);
  const [verificationFeedback, setVerificationFeedback] = useState<{
    success: boolean;
    message: string;
  } | null>(null);

  // DMARC XML Parser state
  const [showDmarcParser, setShowDmarcParser] = useState(false);
  const [xmlFile, setXmlFile] = useState<File | null>(null);
  const [xmlContent, setXmlContent] = useState('');
  const [isParsingReport, setIsParsingReport] = useState(false);
  const [parsedReportResult, setParsedReportResult] = useState<DmarcReportParserResponse | null>(null);
  const [parseError, setParseError] = useState<string | null>(null);

  const handleVerify = async () => {
    setIsVerifying(true);
    setVerificationFeedback(null);
    try {
      const res = await verifyDns();
      await mutateDomain();
      await refreshReport();
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

  const handleParseDmarcReport = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!domain) return;
    setIsParsingReport(true);
    setParseError(null);
    setParsedReportResult(null);

    try {
      let res;
      if (xmlFile) {
        const formData = new FormData();
        formData.append('report_file', xmlFile);
        res = await parseDomainDmarcReport(domain.id, formData);
      } else if (xmlContent.trim()) {
        res = await parseDomainDmarcReport(domain.id, { xml_content: xmlContent.trim() });
      } else {
        setParseError('Please upload an XML report file or paste raw XML content.');
        setIsParsingReport(false);
        return;
      }
      setParsedReportResult(res.data);
    } catch (err: any) {
      setParseError(err.response?.data?.message || err.message || 'Failed to parse DMARC report.');
    } finally {
      setIsParsingReport(false);
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

  const score = report?.deliverability_score || wizard?.deliverability_score;
  const inbound = report?.inbound_routing || wizard?.inbound_routing;
  const outbound = report?.outbound_authentication || wizard?.outbound_authentication;
  const infra = report?.infrastructure_status || wizard?.infrastructure_status;
  const advisor = report?.dmarc_policy_advisor || wizard?.dmarc_policy_advisor;
  const verificationHistory = report?.verification_history || wizard?.verification_history || [];
  const recommendations = report?.recommendations || wizard?.recommendations || [];
  const records = wizard?.records || domain.required_dns_records || [];
  const isDriftDetected = domain.dns_drift_detected || report?.domain?.dns_drift_detected;

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

      {/* DNS Drift Alert Banner */}
      {isDriftDetected && (
        <div className="rounded-xl border border-amber-300 bg-amber-50 p-4 text-amber-900 shadow-sm flex items-start gap-3">
          <AlertTriangle className="h-5 w-5 text-amber-600 mt-0.5 shrink-0" />
          <div className="space-y-1">
            <h4 className="font-semibold text-sm">DNS Drift Detected</h4>
            <p className="text-xs text-amber-800 leading-relaxed">
              This domain is marked active in your tenant account, but authoritative DNS checks indicate that one or more required records (MX, SPF, or DKIM) have been removed, modified, or are no longer resolving. Mail delivery or cryptographic signing may fail until the expected records are restored.
            </p>
          </div>
        </div>
      )}

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

          {/* 4 Pillars Breakdown */}
          {score?.breakdown && (
            <div className="grid grid-cols-4 gap-1.5 pt-1 text-center">
              <div className="bg-slate-50 p-1.5 rounded border border-slate-100">
                <span className="text-[10px] text-muted-foreground block font-mono">MX (40%)</span>
                <span className={`text-xs font-bold ${score.breakdown.mx.earned > 0 ? 'text-green-600' : 'text-red-600'}`}>
                  {score.breakdown.mx.earned}%
                </span>
              </div>
              <div className="bg-slate-50 p-1.5 rounded border border-slate-100">
                <span className="text-[10px] text-muted-foreground block font-mono">SPF (25%)</span>
                <span className={`text-xs font-bold ${score.breakdown.spf.earned > 0 ? 'text-green-600' : 'text-red-600'}`}>
                  {score.breakdown.spf.earned}%
                </span>
              </div>
              <div className="bg-slate-50 p-1.5 rounded border border-slate-100">
                <span className="text-[10px] text-muted-foreground block font-mono">DKIM (25%)</span>
                <span className={`text-xs font-bold ${score.breakdown.dkim.earned > 0 ? 'text-green-600' : 'text-red-600'}`}>
                  {score.breakdown.dkim.earned}%
                </span>
              </div>
              <div className="bg-slate-50 p-1.5 rounded border border-slate-100">
                <span className="text-[10px] text-muted-foreground block font-mono">DMARC (10%)</span>
                <span className={`text-xs font-bold ${score.breakdown.dmarc.earned > 0 ? 'text-green-600' : 'text-red-600'}`}>
                  {score.breakdown.dmarc.earned}%
                </span>
              </div>
            </div>
          )}

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

      {/* Health Score Limitations Disclaimer Banner */}
      <div className="rounded-lg border border-slate-200 bg-slate-50/80 p-3 text-xs text-slate-600 flex items-start gap-2.5">
        <Info className="h-4 w-4 text-slate-500 mt-0.5 shrink-0" />
        <div className="space-y-0.5">
          <strong className="text-slate-800 font-medium">Deliverability Score Notice:</strong>{' '}
          {score?.disclaimer || 'DNS verification reflects the published cryptographic keys and routing records on authoritative nameservers. It does not guarantee external inbox placement, spam filter bypass, or recipient reputation.'}
        </div>
      </div>

      {/* DMARC Policy Advisor Card */}
      {advisor && (
        <div className="rounded-xl border bg-white p-6 shadow-sm space-y-4">
          <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b pb-4">
            <div>
              <div className="flex items-center gap-2">
                <ShieldCheck className="h-5 w-5 text-indigo-600" />
                <h3 className="font-semibold text-base text-gray-900">DMARC Policy Advisor</h3>
                <span className="text-xs font-mono px-2 py-0.5 rounded bg-indigo-50 text-indigo-700 border border-indigo-200">
                  Current: p={advisor.current_policy}
                </span>
              </div>
              <p className="text-xs text-muted-foreground mt-1">
                {advisor.current_policy_explanation}
              </p>
            </div>
            <span className="text-xs text-slate-500 font-medium">
              RFC 7489 Alignment Protection
            </span>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            {/* p=none */}
            <div className={`p-4 rounded-lg border transition-all ${
              advisor.current_policy === 'none' 
                ? 'bg-blue-50/70 border-blue-300 ring-1 ring-blue-300' 
                : 'bg-white border-slate-200'
            }`}>
              <div className="flex items-center justify-between mb-2">
                <span className="font-mono text-xs font-bold text-gray-900">p=none</span>
                <span className={`text-[10px] font-semibold px-2 py-0.5 rounded-full ${
                  advisor.current_policy === 'none' ? 'bg-blue-200 text-blue-900' : 'bg-slate-100 text-slate-600'
                }`}>
                  {advisor.current_policy === 'none' ? 'Active Policy' : 'Monitoring'}
                </span>
              </div>
              <p className="text-xs text-gray-600 leading-relaxed mb-2">
                {advisor.policies.none.description}
              </p>
              <div className="text-[11px] text-slate-500 border-t pt-2 mt-2">
                <strong>Implication:</strong> {advisor.policies.none.implication}
              </div>
            </div>

            {/* p=quarantine */}
            <div className={`p-4 rounded-lg border transition-all ${
              advisor.current_policy === 'quarantine' 
                ? 'bg-amber-50/70 border-amber-300 ring-1 ring-amber-300' 
                : 'bg-white border-slate-200'
            }`}>
              <div className="flex items-center justify-between mb-2">
                <span className="font-mono text-xs font-bold text-gray-900">p=quarantine</span>
                <span className={`text-[10px] font-semibold px-2 py-0.5 rounded-full ${
                  advisor.current_policy === 'quarantine' ? 'bg-amber-200 text-amber-900' : 'bg-slate-100 text-slate-600'
                }`}>
                  {advisor.current_policy === 'quarantine' ? 'Active Policy' : 'Spam Filter'}
                </span>
              </div>
              <p className="text-xs text-gray-600 leading-relaxed mb-2">
                {advisor.policies.quarantine.description}
              </p>
              <div className="text-[11px] text-slate-500 border-t pt-2 mt-2">
                <strong>Implication:</strong> {advisor.policies.quarantine.implication}
              </div>
            </div>

            {/* p=reject */}
            <div className={`p-4 rounded-lg border transition-all ${
              advisor.current_policy === 'reject' 
                ? 'bg-red-50/70 border-red-300 ring-1 ring-red-300' 
                : 'bg-white border-slate-200'
            }`}>
              <div className="flex items-center justify-between mb-2">
                <span className="font-mono text-xs font-bold text-gray-900">p=reject</span>
                <span className={`text-[10px] font-semibold px-2 py-0.5 rounded-full ${
                  advisor.current_policy === 'reject' ? 'bg-red-200 text-red-900' : 'bg-slate-100 text-slate-600'
                }`}>
                  {advisor.current_policy === 'reject' ? 'Active Policy' : 'Strict Block'}
                </span>
              </div>
              <p className="text-xs text-gray-600 leading-relaxed mb-2">
                {advisor.policies.reject.description}
              </p>
              <div className="text-[11px] text-slate-500 border-t pt-2 mt-2">
                <strong>Implication:</strong> {advisor.policies.reject.implication}
              </div>
            </div>
          </div>

          <div className="bg-slate-50 p-3 rounded-lg border border-slate-200 text-xs text-slate-700 flex items-start gap-2">
            <Info className="h-4 w-4 text-indigo-500 mt-0.5 shrink-0" />
            <div>
              <strong>Escalation Rule:</strong> {advisor.policy_escalation_rule}
            </div>
          </div>
        </div>
      )}

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

      {/* Verification History Section */}
      <div className="rounded-xl border bg-white p-6 shadow-sm space-y-4">
        <div className="flex items-center justify-between border-b pb-3">
          <div className="flex items-center gap-2">
            <History className="h-5 w-5 text-gray-600" />
            <h3 className="font-semibold text-base text-gray-900">Verification History</h3>
          </div>
          <span className="text-xs text-muted-foreground">
            Audit-logged verification events ({verificationHistory.length})
          </span>
        </div>

        {verificationHistory.length === 0 ? (
          <p className="text-xs text-muted-foreground py-4 text-center">
            No verification history recorded yet. Click "Check DNS Records Now" above to run an audit.
          </p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-xs text-left border-collapse">
              <thead>
                <tr className="border-b bg-slate-50 text-slate-600">
                  <th className="py-2.5 px-3 font-semibold">Timestamp</th>
                  <th className="py-2.5 px-3 font-semibold">Score</th>
                  <th className="py-2.5 px-3 font-semibold">Domain Status</th>
                  <th className="py-2.5 px-3 font-semibold">MX</th>
                  <th className="py-2.5 px-3 font-semibold">SPF</th>
                  <th className="py-2.5 px-3 font-semibold">DKIM</th>
                  <th className="py-2.5 px-3 font-semibold">DMARC</th>
                  <th className="py-2.5 px-3 font-semibold">Drift</th>
                  <th className="py-2.5 px-3 font-semibold">Verified By</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {verificationHistory.map((item) => (
                  <tr key={item.id} className="hover:bg-slate-50/60 transition-colors">
                    <td className="py-2.5 px-3 font-mono text-slate-700">
                      {item.checked_at ? new Date(item.checked_at).toLocaleString() : 'N/A'}
                    </td>
                    <td className="py-2.5 px-3">
                      <span className={`font-bold ${item.score >= 80 ? 'text-green-600' : item.score >= 50 ? 'text-amber-600' : 'text-red-600'}`}>
                        {item.score}%
                      </span>
                    </td>
                    <td className="py-2.5 px-3 capitalize">
                      <span className="px-2 py-0.5 rounded text-[11px] font-medium bg-slate-100 text-slate-800">
                        {item.status}
                      </span>
                    </td>
                    <td className="py-2.5 px-3">
                      <span className={`px-1.5 py-0.5 rounded text-[10px] font-mono ${
                        item.mx_status === 'VERIFIED' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'
                      }`}>
                        {item.mx_status}
                      </span>
                    </td>
                    <td className="py-2.5 px-3">
                      <span className={`px-1.5 py-0.5 rounded text-[10px] font-mono ${
                        item.spf_status === 'VERIFIED' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'
                      }`}>
                        {item.spf_status}
                      </span>
                    </td>
                    <td className="py-2.5 px-3">
                      <span className={`px-1.5 py-0.5 rounded text-[10px] font-mono ${
                        item.dkim_status === 'VERIFIED' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'
                      }`}>
                        {item.dkim_status}
                      </span>
                    </td>
                    <td className="py-2.5 px-3">
                      <span className={`px-1.5 py-0.5 rounded text-[10px] font-mono ${
                        item.dmarc_status === 'VERIFIED' ? 'bg-green-100 text-green-800' : 'bg-slate-100 text-slate-700'
                      }`}>
                        {item.dmarc_status}
                      </span>
                    </td>
                    <td className="py-2.5 px-3">
                      {item.dns_drift_detected ? (
                        <span className="text-[10px] font-semibold text-amber-700 bg-amber-100 px-1.5 py-0.5 rounded">
                          Drift
                        </span>
                      ) : (
                        <span className="text-slate-400">—</span>
                      )}
                    </td>
                    <td className="py-2.5 px-3 text-slate-500 font-mono text-[11px] truncate max-w-[150px]">
                      {item.actor_email || 'System'}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* DMARC Aggregate Report Parser / Inspector Tool */}
      <div className="rounded-xl border bg-white shadow-sm overflow-hidden">
        <button
          onClick={() => setShowDmarcParser(!showDmarcParser)}
          className="w-full flex items-center justify-between p-5 text-left bg-slate-50/50 hover:bg-slate-50 transition-colors"
        >
          <div className="flex items-center gap-2.5">
            <FileText className="h-5 w-5 text-indigo-600" />
            <div>
              <h3 className="font-semibold text-sm text-gray-900">
                DMARC Aggregate Report Inspector (RFC 7489)
              </h3>
              <p className="text-xs text-muted-foreground">
                Upload or inspect XML feedback reports received from mailbox providers (Google, Yahoo, Microsoft)
              </p>
            </div>
          </div>
          {showDmarcParser ? (
            <ChevronUp className="h-5 w-5 text-gray-500" />
          ) : (
            <ChevronDown className="h-5 w-5 text-gray-500" />
          )}
        </button>

        {showDmarcParser && (
          <div className="p-6 border-t space-y-5">
            <div className="rounded-lg border border-indigo-100 bg-indigo-50/60 p-3 text-xs text-indigo-900 flex items-start gap-2">
              <Info className="h-4 w-4 text-indigo-600 mt-0.5 shrink-0" />
              <div>
                <strong>Inbound Collection Notice:</strong> Automated ingestion via an inbound IMAP/mail-pipe daemon is currently pending MTA infrastructure approval. You can manually inspect received aggregate XML reports below to check alignment, pass rates, and source IP authenticity.
              </div>
            </div>

            <form onSubmit={handleParseDmarcReport} className="space-y-4">
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                {/* File Upload Option */}
                <div className="space-y-2">
                  <label className="text-xs font-semibold text-gray-700 block">
                    Upload XML Report File
                  </label>
                  <input
                    type="file"
                    accept=".xml"
                    onChange={(e) => {
                      if (e.target.files && e.target.files[0]) {
                        setXmlFile(e.target.files[0]);
                      }
                    }}
                    className="block w-full text-xs text-slate-500 file:mr-3 file:py-2 file:px-3 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-primary file:text-white hover:file:bg-primary/90"
                  />
                  <p className="text-[11px] text-muted-foreground">
                    Accepts RFC 7489 compliant XML reports up to 5MB.
                  </p>
                </div>

                {/* Paste Option */}
                <div className="space-y-2">
                  <label className="text-xs font-semibold text-gray-700 block">
                    Or Paste Raw XML Content
                  </label>
                  <textarea
                    rows={3}
                    placeholder="<feedback>...</feedback>"
                    value={xmlContent}
                    onChange={(e) => setXmlContent(e.target.value)}
                    className="w-full text-xs font-mono p-2.5 rounded-md border border-slate-200 focus:outline-none focus:ring-1 focus:ring-primary"
                  />
                </div>
              </div>

              {parseError && (
                <div className="p-3 rounded-lg border border-red-200 bg-red-50 text-red-900 text-xs flex items-center gap-2">
                  <AlertCircle className="h-4 w-4 text-red-600 shrink-0" />
                  <span>{parseError}</span>
                </div>
              )}

              <Button
                type="submit"
                size="sm"
                disabled={isParsingReport || (!xmlFile && !xmlContent.trim())}
                className="flex items-center gap-2"
              >
                {isParsingReport ? <Spinner className="h-4 w-4" /> : <UploadCloud className="h-4 w-4" />}
                {isParsingReport ? 'Parsing Report...' : 'Analyze DMARC Report'}
              </Button>
            </form>

            {/* Parsed Output Details */}
            {parsedReportResult && (
              <div className="border rounded-xl p-5 bg-slate-50/50 space-y-4">
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b pb-3">
                  <div>
                    <h4 className="font-semibold text-sm text-gray-900">
                      Report Analysis: {parsedReportResult.report_metadata.org_name}
                    </h4>
                    <span className="text-xs text-muted-foreground">
                      Report ID: {parsedReportResult.report_metadata.report_id} • Range: {parsedReportResult.report_metadata.date_range_begin} to {parsedReportResult.report_metadata.date_range_end}
                    </span>
                  </div>
                  <span className="text-[10px] font-mono bg-slate-200 text-slate-800 px-2 py-0.5 rounded truncate max-w-[240px]" title={parsedReportResult.deduplication_digest}>
                    Digest: {parsedReportResult.deduplication_digest.substring(0, 16)}...
                  </span>
                </div>

                {/* Summary Metrics */}
                <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 text-center">
                  <div className="bg-white p-3 rounded-lg border">
                    <span className="text-[10px] text-muted-foreground uppercase font-semibold block">Total Volume</span>
                    <span className="text-lg font-bold text-gray-900">{parsedReportResult.summary.total_messages}</span>
                  </div>
                  <div className="bg-white p-3 rounded-lg border">
                    <span className="text-[10px] text-muted-foreground uppercase font-semibold block">Passed</span>
                    <span className="text-lg font-bold text-green-600">{parsedReportResult.summary.passed_messages}</span>
                  </div>
                  <div className="bg-white p-3 rounded-lg border">
                    <span className="text-[10px] text-muted-foreground uppercase font-semibold block">Failed</span>
                    <span className="text-lg font-bold text-red-600">{parsedReportResult.summary.failed_messages}</span>
                  </div>
                  <div className="bg-white p-3 rounded-lg border">
                    <span className="text-[10px] text-muted-foreground uppercase font-semibold block">Sources</span>
                    <span className="text-lg font-bold text-indigo-600">{parsedReportResult.summary.unique_source_ips}</span>
                  </div>
                </div>

                {/* Records Table */}
                <div className="space-y-2">
                  <span className="text-xs font-semibold text-gray-800">Source Delivery Records</span>
                  <div className="overflow-x-auto">
                    <table className="w-full text-xs text-left border-collapse bg-white rounded-lg border">
                      <thead>
                        <tr className="border-b bg-slate-100/70 text-slate-600">
                          <th className="py-2 px-3">Source IP</th>
                          <th className="py-2 px-3">Count</th>
                          <th className="py-2 px-3">Disposition</th>
                          <th className="py-2 px-3">DKIM Result</th>
                          <th className="py-2 px-3">SPF Result</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-slate-100">
                        {parsedReportResult.records.map((rec, i) => (
                          <tr key={i} className="hover:bg-slate-50">
                            <td className="py-2 px-3 font-mono">{rec.source_ip}</td>
                            <td className="py-2 px-3 font-bold">{rec.count}</td>
                            <td className="py-2 px-3 uppercase text-[10px] font-semibold">{rec.disposition}</td>
                            <td className="py-2 px-3">
                              <span className={`px-1.5 py-0.5 rounded text-[10px] font-mono ${rec.dkim_result === 'pass' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'}`}>
                                {rec.dkim_result}
                              </span>
                            </td>
                            <td className="py-2 px-3">
                              <span className={`px-1.5 py-0.5 rounded text-[10px] font-mono ${rec.spf_result === 'pass' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'}`}>
                                {rec.spf_result}
                              </span>
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>
            )}
          </div>
        )}
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