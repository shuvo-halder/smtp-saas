'use client';

import { useState } from 'react';
import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { useTranslations } from 'next-intl';
import { LayoutDashboard, Globe, Mail, CreditCard, Inbox, LogOut, Loader2, AlertCircle } from 'lucide-react';
import { cn } from '@/lib/utils';
import { useAuth } from '@/lib/auth';
import api from '@/lib/api';

export function TenantSidebar() {
  const t = useTranslations('Tenant.sidebar');
  const pathname = usePathname();
  const { logout } = useAuth();
  
  const [isSsoLoading, setIsSsoLoading] = useState(false);
  const [ssoError, setSsoError] = useState<string | null>(null);

  const links = [
    { href: '/dashboard', label: t('dashboard'), icon: LayoutDashboard },
    { href: '/domains', label: t('domains'), icon: Globe },
    { href: '/mailboxes', label: t('mailboxes'), icon: Mail },
    { href: '/billing', label: t('billing'), icon: CreditCard },
  ];

  const handleWebmailSSO = async () => {
    setIsSsoLoading(true);
    setSsoError(null);
    try {
      const response = await api.post('/api/webmail/sso');
      // Redirect seamlessly to the generated one-time URL
      window.open(response.data.url, '_blank', 'noopener,noreferrer');
    } catch (error: any) {
      // Gracefully handle standard errors (e.g. no mailbox exists)
      const errorMessage = error.response?.data?.message || 'Failed to authenticate webmail.';
      setSsoError(errorMessage);
      
      // Auto-clear the error after 5 seconds
      setTimeout(() => setSsoError(null), 5000);
    } finally {
      setIsSsoLoading(false);
    }
  };

  return (
    <aside className="w-64 border-r bg-white hidden md:flex flex-col flex-shrink-0 h-screen sticky top-0 shadow-sm z-10">
      <div className="h-16 flex items-center px-6 border-b font-bold text-lg text-indigo-600 tracking-tight">
        EmailSaaS
      </div>
      
      <nav className="flex-1 py-6 px-3 space-y-1 overflow-y-auto">
        {links.map((link) => {
          const Icon = link.icon;
          const isActive = pathname?.includes(link.href);
              
          return (
            <Link
              key={link.href}
              href={link.href}
              className={cn(
                'flex items-center px-3 py-2 text-sm font-medium rounded-md transition-colors',
                isActive 
                  ? 'bg-indigo-50 text-indigo-700' 
                  : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'
              )}
            >
              <Icon className={cn("mr-3 h-5 w-5", isActive ? "text-indigo-600" : "text-gray-400")} />
              {link.label}
            </Link>
          );
        })}
      </nav>
      
      <div className="p-4 border-t space-y-3 bg-gray-50/50">
        
        {/* Inline Shadcn-style error notification for SSO failures */}
        {ssoError && (
          <div className="flex items-center p-2 text-xs font-medium text-red-600 bg-red-50 border border-red-100 rounded-md shadow-sm animate-in slide-in-from-bottom-2">
            <AlertCircle className="w-4 h-4 mr-2 flex-shrink-0" />
            <span>{ssoError}</span>
          </div>
        )}

        <button 
          onClick={handleWebmailSSO}
          disabled={isSsoLoading}
          className={cn(
            "flex w-full items-center px-3 py-2 text-sm font-medium text-white bg-indigo-600 rounded-md hover:bg-indigo-700 shadow-sm transition-colors disabled:opacity-70 disabled:cursor-not-allowed"
          )}
        >
          {isSsoLoading ? (
            <Loader2 className="mr-3 h-5 w-5 animate-spin" />
          ) : (
            <Inbox className="mr-3 h-5 w-5" />
          )}
          {isSsoLoading ? 'Connecting...' : t('webmail')}
        </button>
        
        <button 
          onClick={() => logout()}
          className="flex w-full items-center px-3 py-2 text-sm font-medium text-gray-700 rounded-md hover:bg-gray-100 transition-colors"
        >
          <LogOut className="mr-3 h-5 w-5 text-gray-400" />
          Logout
        </button>
      </div>
    </aside>
  );
}
