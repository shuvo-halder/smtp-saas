'use client';

import { useParams, useRouter } from 'next/navigation';
import Link from 'next/link';
import { useDomain } from '@/hooks/useDomains';
import api from '@/lib/api';
import { Spinner } from '@/components/ui/spinner';
import { Button, buttonVariants } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { DnsRecordTable } from '@/components/domains/DnsRecordTable';
import { AlertCircle, ChevronRight, Settings, Trash2, Mail } from 'lucide-react';
import { cn } from '@/lib/utils';
import { useState } from 'react';

export default function DomainDetailPage() {
  const params = useParams();
  const router = useRouter();
  const id = params.id as string;
  
  const { domain, isLoading, error } = useDomain(id);
  const [isDeleting, setIsDeleting] = useState(false);

  const handleDelete = async () => {
    if (!confirm('Are you sure you want to delete this domain? All associated mailboxes and aliases will be permanently deleted. This action cannot be undone.')) {
      return;
    }
    
    setIsDeleting(true);
    try {
      await api.delete(`/domains/${id}`);
      router.push('/domains');
    } catch (err) {
      alert('Failed to delete domain');
      setIsDeleting(false);
    }
  };

  if (isLoading || !domain) {
    return <div className="flex justify-center p-8"><Spinner className="h-8 w-8 text-primary" /></div>;
  }

  if (error) {
    return <div className="text-red-500 flex items-center gap-2"><AlertCircle className="h-5 w-5" /> Failed to load domain.</div>;
  }

  return (
    <div className="space-y-8">
      <nav className="flex text-sm text-muted-foreground" aria-label="Breadcrumb">
        <ol className="inline-flex items-center space-x-1 md:space-x-3">
          <li className="inline-flex items-center">
            <Link href="/domains" className="hover:text-foreground">Domains</Link>
          </li>
          <li>
            <div className="flex items-center">
              <ChevronRight className="w-4 h-4 mx-1" />
              <span className="text-foreground font-medium">{domain.domain_name}</span>
            </div>
          </li>
        </ol>
      </nav>

      <div className="flex flex-col sm:flex-row sm:items-start justify-between gap-4 border-b pb-6">
        <div>
          <div className="flex items-center gap-3">
            <h1 className="text-2xl font-bold tracking-tight">{domain.domain_name}</h1>
            <Badge variant={domain.status === 'active' ? 'success' : domain.status === 'failed' ? 'destructive' : 'warning'}>
              {domain.status}
            </Badge>
          </div>
          <p className="text-sm text-muted-foreground mt-1">Added on {new Date(domain.created_at).toLocaleDateString()}</p>
        </div>
        <div className="flex items-center gap-2">
          {domain.status !== 'active' && (
            <Link href={`/domains/${domain.id}/setup`} className={cn(buttonVariants({ variant: "outline" }))}>
              <Settings className="mr-2 h-4 w-4" />
              Verify DNS
            </Link>
          )}
          <Button variant="destructive" onClick={handleDelete} disabled={isDeleting}>
            {isDeleting ? <Spinner className="mr-2 h-4 w-4" /> : <Trash2 className="mr-2 h-4 w-4" />}
            Delete
          </Button>
        </div>
      </div>

      <div className="space-y-6">
        <div className="flex items-center justify-between">
          <h2 className="text-xl font-semibold tracking-tight">Mailboxes</h2>
          <Button disabled={domain.status !== 'active'}>
            <Mail className="mr-2 h-4 w-4" />
            Add Mailbox
          </Button>
        </div>
        
        {domain.status !== 'active' && (
          <div className="bg-yellow-50 border-l-4 border-yellow-400 p-4 rounded-md">
            <div className="flex">
              <div className="flex-shrink-0">
                <AlertCircle className="h-5 w-5 text-yellow-400" />
              </div>
              <div className="ml-3">
                <p className="text-sm text-yellow-700">
                  Domain verification is required before you can create mailboxes. <Link href={`/domains/${domain.id}/setup`} className="font-medium underline hover:text-yellow-600">Complete DNS setup</Link>.
                </p>
              </div>
            </div>
          </div>
        )}

        <div className="bg-white border rounded-md shadow-sm p-8 text-center text-muted-foreground">
          Mailbox management will be available once the domain is verified.
        </div>
      </div>

      <div className="space-y-6">
        <div className="flex items-center justify-between">
          <h2 className="text-xl font-semibold tracking-tight">DNS Records</h2>
          {domain.status === 'active' && (
            <Link href={`/domains/${domain.id}/setup`} className={cn(buttonVariants({ variant: "outline", size: "sm" }))}>
              View Details
            </Link>
          )}
        </div>
        <DnsRecordTable records={domain.required_dns_records} />
      </div>
    </div>
  );
}
