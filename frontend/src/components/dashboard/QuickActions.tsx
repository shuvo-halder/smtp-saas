import Link from "next/link";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { PlusCircle, Mail, CreditCard, ExternalLink } from "lucide-react";

export function QuickActions() {
  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-lg">Quick Actions</CardTitle>
      </CardHeader>
      <CardContent className="grid grid-cols-2 gap-4 sm:grid-cols-4">
        <Link href="/domains/new" className="flex flex-col items-center justify-center gap-2 h-24 rounded-md border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground text-sm font-medium transition-colors">
          <PlusCircle className="h-6 w-6 text-primary" />
          <span>Add Domain</span>
        </Link>
        <Link href="/domains" className="flex flex-col items-center justify-center gap-2 h-24 rounded-md border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground text-sm font-medium transition-colors">
          <Mail className="h-6 w-6 text-primary" />
          <span>Create Mailbox</span>
        </Link>
        <Link href="/billing" className="flex flex-col items-center justify-center gap-2 h-24 rounded-md border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground text-sm font-medium transition-colors">
          <CreditCard className="h-6 w-6 text-primary" />
          <span>View Plans</span>
        </Link>
        <a href={process.env.NEXT_PUBLIC_WEBMAIL_URL || '#'} target="_blank" rel="noopener noreferrer" className="flex flex-col items-center justify-center gap-2 h-24 rounded-md border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground text-sm font-medium transition-colors">
          <ExternalLink className="h-6 w-6 text-primary" />
          <span>Open Webmail</span>
        </a>
      </CardContent>
    </Card>
  );
}
