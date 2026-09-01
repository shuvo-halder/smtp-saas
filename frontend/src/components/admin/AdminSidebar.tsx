'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { useTranslations } from 'next-intl';
import { LayoutDashboard, Users, CreditCard, Settings, LogOut } from 'lucide-react';
import { cn } from '@/lib/utils';
import { useAuth } from '@/lib/auth';

export function AdminSidebar() {
  const t = useTranslations('Admin.sidebar');
  const pathname = usePathname();
  const { logout } = useAuth();

  const links = [
    { href: '/admin', label: t('dashboard'), icon: LayoutDashboard },
    { href: '/admin/tenants', label: t('tenants'), icon: Users },
    { href: '/admin/plans', label: t('plans'), icon: CreditCard },
  ];

  return (
    <aside className="w-64 border-r bg-gray-50/40 hidden md:block flex-shrink-0 h-screen sticky top-0">
      <div className="h-full flex flex-col">
        <div className="h-16 flex items-center px-6 border-b font-bold text-lg text-indigo-600">
          EmailSaaS Admin
        </div>
        <nav className="flex-1 py-6 px-3 space-y-1">
          {links.map((link) => {
            const Icon = link.icon;
            // Exact match for /admin, prefix match for others
            const isActive = link.href === '/admin' 
                ? pathname === '/admin' 
                : pathname?.startsWith(link.href);
                
            return (
              <Link
                key={link.href}
                href={link.href}
                className={cn(
                  'flex items-center px-3 py-2 text-sm font-medium rounded-md',
                  isActive 
                    ? 'bg-indigo-50 text-indigo-700' 
                    : 'text-gray-700 hover:bg-gray-100 hover:text-gray-900'
                )}
              >
                <Icon className="mr-3 h-5 w-5" />
                {link.label}
              </Link>
            );
          })}
        </nav>
        <div className="p-4 border-t">
          <button 
            onClick={() => logout()}
            className="flex w-full items-center px-3 py-2 text-sm font-medium text-gray-700 rounded-md hover:bg-gray-100"
          >
            <LogOut className="mr-3 h-5 w-5" />
            Logout
          </button>
        </div>
      </div>
    </aside>
  );
}
