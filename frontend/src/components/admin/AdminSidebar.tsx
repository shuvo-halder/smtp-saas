'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { useTranslations } from 'next-intl';
import { LayoutDashboard, Users, Globe, Mail, CreditCard, LogOut, Package } from 'lucide-react';
import { cn } from '@/lib/utils';
import { useAuth } from '@/lib/auth';

export function AdminSidebar() {
  const t = useTranslations('Admin.sidebar');
  const pathname = usePathname();
  const { logout } = useAuth();
  
  const links = [
    { href: '/admin/dashboard', label: t('dashboard'), icon: LayoutDashboard },
    { href: '/admin/tenants', label: t('tenants'), icon: Users },
    { href: '/admin/domains', label: t('domains'), icon: Globe },
    { href: '/admin/mailboxes', label: t('mailboxes'), icon: Mail },
    { href: '/admin/plans', label: t('plans'), icon: Package },
    { href: '/admin/invoices', label: t('invoices'), icon: CreditCard },
  ];

  return (
    <aside className="w-64 border-r bg-white hidden md:flex flex-col flex-shrink-0 h-screen sticky top-0 shadow-sm z-10">
      <div className="h-16 flex items-center px-6 border-b font-bold text-lg text-indigo-600 tracking-tight">
        EmailSaaS Admin
      </div>
      
      <nav className="flex-1 py-6 px-3 space-y-1 overflow-y-auto">
        {links.map((link) => {
          const Icon = link.icon;
          // Exact match for dashboard, partial match for others
          const isActive = link.href === '/admin/dashboard' 
            ? pathname === link.href 
            : pathname?.startsWith(link.href);
              
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
      
      <div className="p-4 border-t bg-gray-50/50">
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
