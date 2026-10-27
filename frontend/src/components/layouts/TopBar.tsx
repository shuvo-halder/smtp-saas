'use client';

import { Menu } from 'lucide-react';
import { useAuth } from '@/lib/auth';
import { Badge } from '@/components/ui/badge';
import { NotificationBellDropdown } from '@/components/notifications/NotificationDrawer';

interface TopBarProps {
  onMenuClick: () => void;
}

export function TopBar({ onMenuClick }: TopBarProps) {
  const { user } = useAuth();

  return (
    <header className="sticky top-0 z-30 flex h-16 w-full items-center justify-between border-b bg-white px-4 shadow-sm sm:px-6">
      <div className="flex items-center">
        <button
          onClick={onMenuClick}
          className="mr-4 text-gray-500 md:hidden hover:text-gray-700 focus:outline-none"
        >
          <Menu className="h-6 w-6" />
        </button>
        <h1 className="text-xl font-semibold text-gray-900 hidden sm:block">Dashboard</h1>
      </div>

      <div className="flex items-center space-x-4">
        {user?.plan && (
          <Badge variant="secondary" className="hidden sm:inline-flex">
            {user.plan.name} Plan
          </Badge>
        )}
        <NotificationBellDropdown />
      </div>
    </header>
  );
}
