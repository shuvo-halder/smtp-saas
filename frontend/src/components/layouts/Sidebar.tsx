'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { useAuth } from '@/lib/auth';
import { cn } from '@/lib/utils';
import {
  LayoutDashboard,
  Globe,
  CreditCard,
  Settings,
  Shield,
  Users,
  Box,
  FileText,
  Activity,
  LogOut,
  X
} from 'lucide-react';
import { Button } from '@/components/ui/button';

interface SidebarProps {
  isOpen: boolean;
  setIsOpen: (isOpen: boolean) => void;
}

export function Sidebar({ isOpen, setIsOpen }: SidebarProps) {
  const pathname = usePathname();
  const { user, logout } = useAuth();

  const navItems = [
    { name: 'Dashboard', href: '/dashboard', icon: LayoutDashboard },
    { name: 'Domains', href: '/domains', icon: Globe },
    { name: 'Billing', href: '/billing', icon: CreditCard },
    { name: 'Settings', href: '/settings', icon: Settings },
  ];

  const adminItems = [
    { name: 'Admin Dashboard', href: '/admin', icon: Shield },
    { name: 'Users', href: '/admin/users', icon: Users },
    { name: 'Plans', href: '/admin/plans', icon: Box },
    { name: 'Invoices', href: '/admin/invoices', icon: FileText },
    { name: 'Server Stats', href: '/admin/stats', icon: Activity },
  ];

  return (
    <>
      {/* Mobile overlay */}
      {isOpen && (
        <div
          className="fixed inset-0 z-40 bg-black/50 md:hidden"
          onClick={() => setIsOpen(false)}
        />
      )}

      {/* Sidebar */}
      <div
        className={cn(
          "fixed inset-y-0 left-0 z-50 w-64 flex-col bg-white border-r transition-transform duration-300 ease-in-out md:flex md:static md:translate-x-0",
          isOpen ? "flex translate-x-0" : "-translate-x-full"
        )}
      >
        <div className="flex h-16 items-center justify-between px-6 border-b">
          <Link href="/dashboard" className="text-xl font-bold text-indigo-600">
            EmailSaaS
          </Link>
          <button className="md:hidden" onClick={() => setIsOpen(false)}>
            <X className="h-5 w-5 text-gray-500" />
          </button>
        </div>

        <div className="flex-1 overflow-y-auto py-4">
          <nav className="space-y-1 px-3">
            {navItems.map((item) => {
              const Icon = item.icon;
              const isActive = pathname === item.href || pathname.startsWith(item.href + '/');
              return (
                <Link
                  key={item.name}
                  href={item.href}
                  className={cn(
                    "flex items-center rounded-md px-3 py-2 text-sm font-medium transition-colors",
                    isActive
                      ? "bg-indigo-50 text-indigo-700"
                      : "text-gray-700 hover:bg-gray-100"
                  )}
                >
                  <Icon className={cn("mr-3 h-5 w-5", isActive ? "text-indigo-700" : "text-gray-400")} />
                  {item.name}
                </Link>
              );
            })}
          </nav>

          {user?.is_admin && (
            <div className="mt-8">
              <h3 className="px-6 text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">
                Administration
              </h3>
              <nav className="space-y-1 px-3">
                {adminItems.map((item) => {
                  const Icon = item.icon;
                  const isActive = pathname === item.href || pathname.startsWith(item.href + '/');
                  return (
                    <Link
                      key={item.name}
                      href={item.href}
                      className={cn(
                        "flex items-center rounded-md px-3 py-2 text-sm font-medium transition-colors",
                        isActive
                          ? "bg-indigo-50 text-indigo-700"
                          : "text-gray-700 hover:bg-gray-100"
                      )}
                    >
                      <Icon className={cn("mr-3 h-5 w-5", isActive ? "text-indigo-700" : "text-gray-400")} />
                      {item.name}
                    </Link>
                  );
                })}
              </nav>
            </div>
          )}
        </div>

        <div className="border-t p-4">
          <div className="flex items-center justify-between">
            <div className="flex flex-col truncate">
              <span className="text-sm font-medium text-gray-900 truncate">{user?.name}</span>
              <span className="text-xs text-gray-500 truncate">{user?.email}</span>
            </div>
            <Button variant="ghost" size="icon" onClick={logout} title="Logout">
              <LogOut className="h-5 w-5 text-gray-500" />
            </Button>
          </div>
        </div>
      </div>
    </>
  );
}
