import { ReactNode } from 'react';
import { TenantSidebar } from '@/components/tenant/TenantSidebar';

export default function TenantLayout({ children, params }: { children: ReactNode, params: { subdomain: string } }) {
  return (
    <div className="flex min-h-screen bg-gray-50/50">
      <TenantSidebar />
      <div className="flex-1 flex flex-col min-w-0">
        <header className="h-16 border-b bg-white flex items-center justify-between px-6 shadow-sm sticky top-0 z-10">
          <div className="flex items-center">
             <span className="md:hidden font-bold text-indigo-600 mr-4">EmailSaaS</span>
             <span className="text-sm font-medium text-gray-500 bg-gray-100 px-2 py-1 rounded-md hidden sm:inline-block">
               {params.subdomain}.mailsaas.com
             </span>
          </div>
          <div className="flex items-center space-x-4">
             {/* User Profile placeholder */}
             <div className="h-8 w-8 rounded-full bg-indigo-100 border border-indigo-200 flex items-center justify-center text-indigo-700 font-bold text-sm">
                U
             </div>
          </div>
        </header>
        <main className="flex-1 p-6 overflow-auto">
          <div className="mx-auto max-w-6xl">
            {children}
          </div>
        </main>
      </div>
    </div>
  );
}
