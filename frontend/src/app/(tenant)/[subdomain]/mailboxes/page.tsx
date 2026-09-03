'use client';

import { useState } from 'react';
import { useTranslations } from 'next-intl';
import useSWR from 'swr';
import api from '@/lib/api';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Domain, PaginatedResponse, Mailbox } from '@/types';
import { Plus, Settings, Trash2 } from 'lucide-react';

export default function TenantMailboxesPage() {
  const t = useTranslations('Tenant.mailboxes');
  
  const fetcher = (url: string) => api.get(url).then(res => res.data);
  const { data: domainsData, isLoading: loadingDomains } = useSWR<PaginatedResponse<Domain>>('/api/domains', fetcher);
  
  const [selectedDomain, setSelectedDomain] = useState<string | null>(null);
  const [isAdding, setIsAdding] = useState(false);
  const [formData, setFormData] = useState({ local_part: '', password: '', display_name: '', quota_mb: 1024 });
  const [addError, setAddError] = useState('');

  // Auto-select first domain if not selected
  if (!selectedDomain && domainsData?.data && domainsData.data.length > 0) {
    setSelectedDomain(domainsData.data[0].id.toString());
  }

  const { data: mailboxesData, isLoading: loadingMailboxes, mutate } = useSWR<PaginatedResponse<Mailbox>>(
    selectedDomain ? `/api/domains/${selectedDomain}/mailboxes` : null,
    fetcher
  );

  const handleAddMailbox = async (e: React.FormEvent) => {
    e.preventDefault();
    setAddError('');
    if (!selectedDomain) return;
    
    try {
      await api.post(`/api/domains/${selectedDomain}/mailboxes`, formData);
      setFormData({ local_part: '', password: '', display_name: '', quota_mb: 1024 });
      setIsAdding(false);
      mutate();
    } catch (err: any) {
      setAddError(err.response?.data?.message || 'Failed to create mailbox');
    }
  };

  const generatePassword = () => {
    const chars = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*";
    let password = "";
    for (let i = 0; i < 16; i++) {
      password += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    setFormData({ ...formData, password });
  };

  const domainObj = domainsData?.data.find(d => d.id.toString() === selectedDomain);

  return (
    <div className="space-y-6">
      <div className="flex justify-between items-center">
        <h1 className="text-2xl font-bold text-gray-900 tracking-tight">{t('title')}</h1>
        <Button onClick={() => setIsAdding(true)} disabled={!selectedDomain} className="bg-indigo-600 hover:bg-indigo-700">
          <Plus className="w-4 h-4 mr-2" />
          {t('add_mailbox')}
        </Button>
      </div>

      <div className="flex items-center gap-4 bg-white p-4 rounded-lg shadow-sm border border-gray-100">
        <label className="text-sm font-medium text-gray-700">{t('select_domain')}:</label>
        <select 
          className="border border-gray-300 rounded-md p-2 text-sm focus:ring-indigo-500 focus:border-indigo-500 w-64"
          value={selectedDomain || ''}
          onChange={(e) => setSelectedDomain(e.target.value)}
          disabled={loadingDomains}
        >
          {loadingDomains && <option>Loading...</option>}
          {!loadingDomains && domainsData?.data.length === 0 && <option>No domains available</option>}
          {domainsData?.data.map(domain => (
            <option key={domain.id} value={domain.id}>{domain.domain_name}</option>
          ))}
        </select>
      </div>

      {isAdding && (
        <Card className="border-indigo-100 shadow-md">
          <CardContent className="p-6">
            <h2 className="text-lg font-semibold mb-4 text-gray-900">Create New Mailbox</h2>
            <form onSubmit={handleAddMailbox} className="space-y-4 max-w-lg">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">{t('email_prefix')}</label>
                <div className="flex rounded-md shadow-sm">
                  <input
                    type="text"
                    required
                    value={formData.local_part}
                    onChange={(e) => setFormData({...formData, local_part: e.target.value})}
                    className="flex-1 min-w-0 block w-full px-3 py-2 rounded-none rounded-l-md border border-gray-300 sm:text-sm focus:ring-indigo-500 focus:border-indigo-500"
                    placeholder="info"
                  />
                  <span className="inline-flex items-center px-3 rounded-r-md border border-l-0 border-gray-300 bg-gray-50 text-gray-500 sm:text-sm font-mono">
                    @{domainObj?.domain_name}
                  </span>
                </div>
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">{t('display_name')}</label>
                <Input 
                  value={formData.display_name}
                  onChange={(e) => setFormData({...formData, display_name: e.target.value})}
                  placeholder="John Doe" 
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">{t('password')}</label>
                <div className="flex gap-2">
                  <Input 
                    type="text" 
                    required 
                    value={formData.password}
                    onChange={(e) => setFormData({...formData, password: e.target.value})}
                  />
                  <Button type="button" variant="outline" onClick={generatePassword}>{t('generate_password')}</Button>
                </div>
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">{t('quota')}</label>
                <Input 
                  type="number" 
                  required 
                  min="1"
                  value={formData.quota_mb}
                  onChange={(e) => setFormData({...formData, quota_mb: parseInt(e.target.value)})}
                />
              </div>

              {addError && <p className="text-sm text-red-600">{addError}</p>}

              <div className="flex gap-2 pt-2">
                <Button type="button" variant="outline" onClick={() => setIsAdding(false)}>Cancel</Button>
                <Button type="submit" className="bg-indigo-600 hover:bg-indigo-700">Create Mailbox</Button>
              </div>
            </form>
          </CardContent>
        </Card>
      )}

      <Card className="shadow-sm">
        <CardContent className="p-0">
          <div className="overflow-x-auto">
            <table className="w-full text-sm text-left text-gray-600">
              <thead className="text-xs text-gray-700 uppercase bg-gray-50 border-b">
                <tr>
                  <th className="px-6 py-4">{t('table_email')}</th>
                  <th className="px-6 py-4">{t('table_usage')}</th>
                  <th className="px-6 py-4">{t('table_status')}</th>
                  <th className="px-6 py-4 text-right">{t('table_actions')}</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100">
                {loadingMailboxes ? (
                  <tr>
                    <td colSpan={4} className="px-6 py-12 text-center"><Spinner /></td>
                  </tr>
                ) : mailboxesData?.data.length === 0 ? (
                  <tr>
                    <td colSpan={4} className="px-6 py-12 text-center text-gray-500">No mailboxes found for this domain.</td>
                  </tr>
                ) : (
                  mailboxesData?.data.map((mb) => (
                    <tr key={mb.id} className="hover:bg-gray-50 bg-white">
                      <td className="px-6 py-4">
                        <div className="font-semibold text-gray-900">{mb.email}</div>
                        <div className="text-xs text-gray-500">{mb.display_name}</div>
                      </td>
                      <td className="px-6 py-4">
                        <div className="text-xs font-medium text-gray-900">0 MB / {mb.quota_formatted}</div>
                        <div className="w-24 h-1.5 bg-gray-200 rounded-full mt-1 overflow-hidden">
                           <div className="bg-indigo-500 h-full" style={{ width: '0%' }}></div>
                        </div>
                      </td>
                      <td className="px-6 py-4">
                        <Badge variant={mb.is_active ? 'success' : 'destructive'}>
                          {mb.is_active ? 'Active' : 'Disabled'}
                        </Badge>
                      </td>
                      <td className="px-6 py-4 text-right space-x-2">
                        <button className="text-gray-400 hover:text-indigo-600 p-1">
                          <Settings className="w-4 h-4" />
                        </button>
                        <button className="text-gray-400 hover:text-red-600 p-1">
                          <Trash2 className="w-4 h-4" />
                        </button>
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </CardContent>
      </Card>
    </div>
  );
}
