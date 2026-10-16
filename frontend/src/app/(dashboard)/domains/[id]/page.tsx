'use client';

import { useParams, useRouter } from 'next/navigation';
import Link from 'next/link';
import { useDomain } from '@/hooks/useDomains';
import { useMailboxes } from '@/hooks/useMailboxes';
import api from '@/lib/api';
import { Spinner } from '@/components/ui/spinner';
import { Button, buttonVariants } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { DnsRecordTable } from '@/components/domains/DnsRecordTable';
import MailboxRow from '@/components/mailboxes/MailboxRow';
import CreateMailboxModal from '@/components/mailboxes/CreateMailboxModal';
import { AlertCircle, ChevronRight, Settings, Trash2, Mail, Plus } from 'lucide-react';
import { cn } from '@/lib/utils';
import { useState } from 'react';

export default function DomainDetailPage() {
  const params = useParams();
  const router = useRouter();
  const id = params.id as string;
  
  const { domain, isLoading, error, mutate: mutateDomain } = useDomain(id);
  const { mailboxes, isLoading: mailboxesLoading, mutate: mutateMailboxes } = useMailboxes(id);
  const [isDeleting, setIsDeleting] = useState(false);
  const [isAddMailboxOpen, setIsAddMailboxOpen] = useState(false);

  const handleDelete = async () => {
    if (!confirm('Are you sure you want to delete this domain? All associated mailboxes and aliases will be permanently deleted. This action cannot be undone.')) {
      return;
    }
    
    setIsDeleting(true);
    try {
      await api.delete(`/domains/${id}`);
      router.push('/domains');
    } catch (err: any) {
      alert(err.response?.data?.message || 'Failed to delete domain');
      setIsDeleting(false);
    }
  };

  if (isLoading || !domain) {
    return <div className="flex justify-center p-8"><Spinner className="h-8 w-8 text-primary" /></div>;
  }

  if (error) {
    return <div className="text-red-500 flex items-center gap-2"><AlertCircle className="h-5 w-5" /> Failed to load domain.</div>;
  }

  const isVerified = domain.status === 'active';

  return (
    <div className="space-y-8">
      {/* Breadcrumbs */}
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

      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-start justify-between gap-4 border-b pb-6">
        <div>
          <div className="flex items-center gap-3">
            <h1 className="text-2xl font-bold tracking-tight">{domain.domain_name}</h1>
            <Badge variant={domain.status === 'active' ? 'success' : domain.status === 'failed' ? 'destructive' : 'warning'}>
              {domain.status}
            </Badge>
          </div>
          <p className="text-sm text-muted-foreground mt-1">
            Added on {new Date(domain.created_at).toLocaleDateString()}
            {domain.activated_at && ` • Verified on ${new Date(domain.activated_at).toLocaleDateString()}`}
          </p>
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
            Delete Domain
          </Button>
        </div>
      </div>

      {/* Mailboxes Section */}
      <div className="space-y-6">
        <div className="flex items-center justify-between">
          <div>
            <h2 className="text-xl font-semibold tracking-tight">Mailboxes</h2>
            <p className="text-sm text-muted-foreground">Email addresses configured under @{domain.domain_name}</p>
          </div>
          <Button
            disabled={!isVerified}
            onClick={() => setIsAddMailboxOpen(true)}
            className="flex items-center gap-2"
          >
            <Plus className="h-4 w-4" />
            Add Mailbox
          </Button>
        </div>
        
        {!isVerified ? (
          <div className="bg-yellow-50 border-l-4 border-yellow-400 p-4 rounded-md">
            <div className="flex items-start">
              <div className="flex-shrink-0">
                <AlertCircle className="h-5 w-5 text-yellow-500 mt-0.5" />
              </div>
              <div className="ml-3">
                <h4 className="text-sm font-semibold text-yellow-800">Domain Verification Required</h4>
                <p className="text-sm text-yellow-700 mt-1">
                  Mailbox creation is locked until domain DNS records are verified. Please complete DNS setup to enable mailboxes.
                </p>
                <div className="mt-3">
                  <Link
                    href={`/domains/${domain.id}/setup`}
                    className="inline-flex items-center px-3 py-1.5 text-xs font-medium rounded-md bg-yellow-100 text-yellow-900 hover:bg-yellow-200"
                  >
                    Complete DNS Setup →
                  </Link>
                </div>
              </div>
            </div>
          </div>
        ) : mailboxesLoading && !mailboxes ? (
          <div className="flex justify-center p-8 bg-white rounded-md border">
            <Spinner className="h-6 w-6 text-primary" />
          </div>
        ) : mailboxes?.data.length === 0 ? (
          <div className="bg-white border rounded-lg p-8 text-center">
            <div className="mx-auto w-10 h-10 rounded-full bg-indigo-50 flex items-center justify-center text-indigo-600 mb-3">
              <Mail className="h-5 w-5" />
            </div>
            <h3 className="text-sm font-semibold text-gray-900">No mailboxes on this domain</h3>
            <p className="text-sm text-muted-foreground mt-1">
              Create your first mailbox to start sending and receiving email with @{domain.domain_name}.
            </p>
            <div className="mt-4">
              <Button size="sm" onClick={() => setIsAddMailboxOpen(true)}>
                <Plus className="h-4 w-4 mr-1.5" />
                Add Mailbox
              </Button>
            </div>
          </div>
        ) : (
          <div className="bg-white border rounded-lg shadow-sm overflow-hidden">
            <div className="overflow-x-auto">
              <table className="w-full text-left border-collapse">
                <thead>
                  <tr className="bg-gray-50 border-b text-xs font-semibold uppercase tracking-wider text-gray-500">
                    <th className="py-3 px-4">Mailbox</th>
                    <th className="py-3 px-4">Storage & Quota</th>
                    <th className="py-3 px-4">Status</th>
                    <th className="py-3 px-4">Last Login</th>
                    <th className="py-3 px-4">Webmail</th>
                    <th className="py-3 px-4 text-right">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-200">
                  {mailboxes?.data.map((mailbox) => (
                    <MailboxRow
                      key={mailbox.id}
                      mailbox={mailbox}
                      showDomain={false}
                      onRefresh={() => {
                        mutateMailboxes();
                        mutateDomain();
                      }}
                    />
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        )}
      </div>

      {/* DNS Records Section */}
      <div className="space-y-6 pt-4 border-t">
        <div className="flex items-center justify-between">
          <div>
            <h2 className="text-xl font-semibold tracking-tight">DNS Records</h2>
            <p className="text-sm text-muted-foreground">Required DNS records for email delivery and authentication</p>
          </div>
          <Link href={`/domains/${domain.id}/setup`} className={cn(buttonVariants({ variant: "outline", size: "sm" }))}>
            {domain.status === 'active' ? 'Inspect DNS Setup' : 'Configure DNS'}
          </Link>
        </div>
        <DnsRecordTable records={domain.required_dns_records} />
      </div>

      {/* Add Mailbox Modal */}
      {isAddMailboxOpen && (
        <CreateMailboxModal
          domainId={domain.id}
          domainName={domain.domain_name}
          onClose={() => setIsAddMailboxOpen(false)}
          onSuccess={() => {
            setIsAddMailboxOpen(false);
            mutateMailboxes();
            mutateDomain();
          }}
        />
      )}
    </div>
  );
}
