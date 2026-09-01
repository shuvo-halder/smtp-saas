import Link from 'next/link';
import { AddDomainForm } from '@/components/domains/AddDomainForm';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ChevronRight, Info } from 'lucide-react';

export default function NewDomainPage() {
  return (
    <div className="space-y-6 max-w-3xl">
      <nav className="flex text-sm text-muted-foreground" aria-label="Breadcrumb">
        <ol className="inline-flex items-center space-x-1 md:space-x-3">
          <li className="inline-flex items-center">
            <Link href="/domains" className="hover:text-foreground">Domains</Link>
          </li>
          <li>
            <div className="flex items-center">
              <ChevronRight className="w-4 h-4 mx-1" />
              <span className="text-foreground font-medium">Add New</span>
            </div>
          </li>
        </ol>
      </nav>

      <div>
        <h1 className="text-2xl font-bold tracking-tight">Add a Domain</h1>
        <p className="text-muted-foreground mt-1">
          Add your custom domain to start creating email addresses.
        </p>
      </div>

      <div className="grid gap-6 md:grid-cols-2">
        <div>
          <AddDomainForm />
        </div>
        
        <div>
          <Card className="bg-indigo-50 border-indigo-100">
            <CardHeader>
              <CardTitle className="text-indigo-800 text-base flex items-center gap-2">
                <Info className="h-5 w-5" />
                What happens next?
              </CardTitle>
            </CardHeader>
            <CardContent className="text-sm text-indigo-800/80 space-y-4">
              <p>
                After adding your domain, you'll need to verify ownership and configure email delivery by adding specific DNS records to your domain registrar (like GoDaddy, Namecheap, or Cloudflare).
              </p>
              <ul className="list-disc pl-5 space-y-1">
                <li>We'll provide the exact TXT, MX, and CNAME records you need.</li>
                <li>DNS changes can take anywhere from a few minutes to 48 hours to propagate globally.</li>
                <li>Once verified, you can immediately start creating mailboxes and aliases.</li>
              </ul>
            </CardContent>
          </Card>
        </div>
      </div>
    </div>
  );
}
