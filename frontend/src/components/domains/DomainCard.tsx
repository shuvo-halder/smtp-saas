import Link from 'next/link';
import { Domain } from '@/types';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { CheckCircle2, XCircle, ChevronRight, Mail } from 'lucide-react';

interface DomainCardProps {
  domain: Domain;
}

export function DomainCard({ domain }: DomainCardProps) {
  const getStatusColor = (status: string) => {
    switch (status) {
      case 'active': return 'success';
      case 'pending':
      case 'verifying': return 'warning';
      case 'failed':
      case 'suspended': return 'destructive';
      default: return 'secondary';
    }
  };

  const StatusIcon = ({ verified, label }: { verified: boolean, label: string }) => (
    <div className="flex items-center gap-1 text-xs" title={label}>
      <span className="font-medium text-muted-foreground">{label}:</span>
      {verified ? (
        <CheckCircle2 className="h-4 w-4 text-green-500" />
      ) : (
        <XCircle className="h-4 w-4 text-red-500" />
      )}
    </div>
  );

  return (
    <Link href={`/domains/${domain.id}`} className="block group">
      <Card className="hover:border-primary transition-colors h-full flex flex-col">
        <CardContent className="p-6 flex-1 flex flex-col">
          <div className="flex justify-between items-start mb-4">
            <h3 className="text-lg font-semibold truncate pr-4 group-hover:text-primary transition-colors">
              {domain.domain_name}
            </h3>
            <Badge variant={getStatusColor(domain.status)}>
              {domain.status}
            </Badge>
          </div>
          
          <div className="flex-1 flex flex-col justify-end space-y-4">
            <div className="flex items-center gap-2 text-sm text-muted-foreground">
              <Mail className="h-4 w-4" />
              <span>{domain.mailboxes_count} Mailboxes</span>
            </div>
            
            <div className="bg-gray-50 rounded-md p-3 flex flex-wrap gap-x-4 gap-y-2">
              <StatusIcon verified={domain.mx_verified} label="MX" />
              <StatusIcon verified={domain.spf_verified} label="SPF" />
              <StatusIcon verified={domain.dkim_verified} label="DKIM" />
            </div>

            <div className="flex justify-end mt-2">
              <span className="text-sm font-medium text-primary flex items-center">
                Manage <ChevronRight className="h-4 w-4 ml-1" />
              </span>
            </div>
          </div>
        </CardContent>
      </Card>
    </Link>
  );
}
