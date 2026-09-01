'use client';

import { useState } from 'react';
import { useParams, useRouter } from 'next/navigation';
import Link from 'next/link';
import { useDomain } from '@/hooks/useDomains';
import api from '@/lib/api';
import { Spinner } from '@/components/ui/spinner';
import { Button } from '@/components/ui/button';
import { DnsRecordTable } from '@/components/domains/DnsRecordTable';
import { AlertCircle, ChevronRight, CheckCircle2, ArrowLeft } from 'lucide-react';

export default function DomainSetupPage() {
  const params = useParams();
  const router = useRouter();
  const id = params.id as string;
  
  const { domain, isLoading, error, mutate } = useDomain(id);
  const [isVerifying, setIsVerifying] = useState(false);
  const [verificationResult, setVerificationResult] = useState<{success: boolean, message: string} | null>(null);

  const handleVerify = async () => {
    setIsVerifying(true);
    setVerificationResult(null);
    try {
      await api.post(`/domains/${id}/verify`);
      await mutate();
      setVerificationResult({
        success: true,
        message: 'DNS verification initiated. Changes may take some time to propagate.'
      });
    } catch (err: any) {
      setVerificationResult({
        success: false,
        message: err.response?.data?.message || 'Verification failed. Please check your DNS records.'
      });
    } finally {
      setIsVerifying(false);
    }
  };

  if (isLoading || !domain) {
    return <div className="flex justify-center p-8"><Spinner className="h-8 w-8 text-primary" /></div>;
  }

  if (error) {
    return <div className="text-red-500 flex items-center gap-2"><AlertCircle className="h-5 w-5" /> Failed to load domain.</div>;
  }

  return (
    <div className="space-y-6 max-w-5xl">
      <nav className="flex text-sm text-muted-foreground" aria-label="Breadcrumb">
        <ol className="inline-flex items-center space-x-1 md:space-x-3">
          <li className="inline-flex items-center">
            <Link href="/domains" className="hover:text-foreground">Domains</Link>
          </li>
          <li>
            <div className="flex items-center">
              <ChevronRight className="w-4 h-4 mx-1" />
              <Link href={`/domains/${domain.id}`} className="hover:text-foreground">{domain.domain_name}</Link>
            </div>
          </li>
          <li>
            <div className="flex items-center">
              <ChevronRight className="w-4 h-4 mx-1" />
              <span className="text-foreground font-medium">DNS Setup</span>
            </div>
          </li>
        </ol>
      </nav>

      <div>
        <h1 className="text-2xl font-bold tracking-tight">Configure DNS Records</h1>
        <p className="text-muted-foreground mt-1">
          Complete these steps to verify your domain and enable email delivery.
        </p>
      </div>

      <div className="bg-blue-50 border border-blue-200 rounded-lg p-4">
        <h3 className="font-semibold text-blue-900 flex items-center gap-2">
          <AlertCircle className="h-5 w-5" />
          DNS Propagation
        </h3>
        <p className="text-sm text-blue-800 mt-2">
          After adding these records to your domain registrar (e.g., Namecheap, GoDaddy, Route53), it can take anywhere from <strong>15 minutes to 48 hours</strong> for the changes to propagate globally. You can click "Verify DNS Records" multiple times until it succeeds.
        </p>
      </div>

      {verificationResult && (
        <div className={`p-4 rounded-md flex items-start gap-3 border ${verificationResult.success ? 'bg-green-50 border-green-200 text-green-800' : 'bg-red-50 border-red-200 text-red-800'}`}>
          {verificationResult.success ? <CheckCircle2 className="h-5 w-5 mt-0.5" /> : <AlertCircle className="h-5 w-5 mt-0.5" />}
          <div>
            <h4 className="font-medium">{verificationResult.success ? 'Verification Check Started' : 'Verification Failed'}</h4>
            <p className="text-sm mt-1">{verificationResult.message}</p>
          </div>
        </div>
      )}

      <div className="space-y-4">
        <div className="flex items-center justify-between">
          <h2 className="text-lg font-semibold">Required Records</h2>
          <Button onClick={handleVerify} disabled={isVerifying || domain.status === 'active'}>
            {isVerifying ? <Spinner className="mr-2 h-4 w-4" /> : null}
            Verify DNS Records
          </Button>
        </div>
        
        <DnsRecordTable records={domain.required_dns_records} />
      </div>

      <div className="pt-4">
        <Button variant="outline" onClick={() => router.push(`/domains/${domain.id}`)}>
          <ArrowLeft className="mr-2 h-4 w-4" />
          Back to Domain
        </Button>
      </div>
    </div>
  );
}
