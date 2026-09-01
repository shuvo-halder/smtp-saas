'use client';

import { useState } from 'react';
import { useTranslations } from 'next-intl';
import useSWR from 'swr';
import { api } from '@/lib/api';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Domain, PaginatedResponse, DnsRecord } from '@/types';
import { Plus, Check, X, ChevronDown, ChevronUp, Copy } from 'lucide-react';

export default function TenantDomainsPage() {
  const t = useTranslations('Tenant.domains');
  const [isAdding, setIsAdding] = useState(false);
  const [newDomain, setNewDomain] = useState('');
  const [addError, setAddError] = useState('');
  const [expandedDomainId, setExpandedDomainId] = useState<number | null>(null);

  const fetcher = (url: string) => api.get(url).then(res => res.data);
  const { data, error, isLoading, mutate } = useSWR<PaginatedResponse<Domain>>('/api/domains', fetcher);

  const handleAddDomain = async (e: React.FormEvent) => {
    e.preventDefault();
    setAddError('');
    try {
      await api.post('/api/domains', { domain_name: newDomain });
      setNewDomain('');
      setIsAdding(false);
      mutate();
    } catch (err: any) {
      setAddError(err.response?.data?.message || 'Failed to add domain');
    }
  };

  const handleVerify = async (id: number) => {
    try {
      await api.post(`/api/domains/${id}/verify`);
      mutate();
    } catch (err) {
      console.error(err);
    }
  };

  const copyToClipboard = (text: string) => {
    navigator.clipboard.writeText(text);
  };

  if (error) return <div className="text-red-500">Error loading domains</div>;

  return (
    <div className="space-y-6">
      <div className="flex justify-between items-center">
        <h1 className="text-2xl font-bold text-gray-900 tracking-tight">{t('title')}</h1>
        <Button onClick={() => setIsAdding(true)} className="bg-indigo-600 hover:bg-indigo-700">
          <Plus className="w-4 h-4 mr-2" />
          {t('add_domain')}
        </Button>
      </div>

      {isAdding && (
        <Card className="border-indigo-100 shadow-md">
          <CardContent className="p-6">
            <form onSubmit={handleAddDomain} className="flex flex-col sm:flex-row gap-4 items-end">
              <div className="flex-1 w-full space-y-1">
                <label className="text-sm font-medium text-gray-700">{t('domain_name')}</label>
                <Input 
                  placeholder="example.com" 
                  value={newDomain} 
                  onChange={(e) => setNewDomain(e.target.value)}
                  required 
                />
              </div>
              <div className="flex gap-2 w-full sm:w-auto">
                <Button type="button" variant="outline" onClick={() => setIsAdding(false)}>Cancel</Button>
                <Button type="submit" className="bg-indigo-600 hover:bg-indigo-700">Save</Button>
              </div>
            </form>
            {addError && <p className="text-sm text-red-600 mt-2">{addError}</p>}
          </CardContent>
        </Card>
      )}

      <Card className="shadow-sm">
        <CardContent className="p-0">
          {isLoading ? (
            <div className="flex justify-center p-12"><Spinner /></div>
          ) : (
            <div className="divide-y">
              {data?.data.length === 0 ? (
                <div className="p-8 text-center text-gray-500">No domains added yet.</div>
              ) : (
                data?.data.map((domain) => (
                  <div key={domain.id} className="flex flex-col">
                    <div 
                      className="flex items-center justify-between p-6 hover:bg-gray-50 cursor-pointer transition-colors"
                      onClick={() => setExpandedDomainId(expandedDomainId === domain.id ? null : domain.id)}
                    >
                      <div>
                        <div className="flex items-center gap-3">
                          <span className="font-semibold text-lg text-gray-900">{domain.domain_name}</span>
                          <Badge variant={domain.status === 'active' ? 'success' : 'warning'}>
                            {domain.status}
                          </Badge>
                        </div>
                        <div className="text-sm text-gray-500 mt-1">
                          {domain.mailboxes_count} {t('mailboxes')}
                        </div>
                      </div>
                      <div className="flex items-center gap-6">
                        <div className="hidden sm:flex items-center gap-4 text-sm">
                           <div className="flex flex-col items-center">
                              {domain.mx_verified ? <Check className="w-4 h-4 text-green-500" /> : <X className="w-4 h-4 text-red-400" />}
                              <span className="text-xs text-gray-500 mt-1">MX</span>
                           </div>
                           <div className="flex flex-col items-center">
                              {domain.spf_verified ? <Check className="w-4 h-4 text-green-500" /> : <X className="w-4 h-4 text-red-400" />}
                              <span className="text-xs text-gray-500 mt-1">SPF</span>
                           </div>
                           <div className="flex flex-col items-center">
                              {domain.dkim_verified ? <Check className="w-4 h-4 text-green-500" /> : <X className="w-4 h-4 text-red-400" />}
                              <span className="text-xs text-gray-500 mt-1">DKIM</span>
                           </div>
                        </div>
                        {expandedDomainId === domain.id ? <ChevronUp className="text-gray-400" /> : <ChevronDown className="text-gray-400" />}
                      </div>
                    </div>

                    {/* DNS Configuration Accordion Content */}
                    {expandedDomainId === domain.id && (
                      <div className="bg-gray-50 p-6 border-t border-gray-100">
                        <div className="flex justify-between items-center mb-4">
                          <div>
                            <h3 className="font-semibold text-gray-900">{t('dns_config')}</h3>
                            <p className="text-sm text-gray-600 mt-1">{t('dns_instructions')}</p>
                          </div>
                          <Button variant="outline" size="sm" onClick={() => handleVerify(domain.id)}>
                            {t('verify_dns')}
                          </Button>
                        </div>
                        
                        <div className="bg-white rounded-md border shadow-sm overflow-hidden">
                          <table className="w-full text-sm text-left text-gray-600">
                            <thead className="bg-gray-50 text-xs uppercase text-gray-500 border-b">
                              <tr>
                                <th className="px-4 py-3">{t('record_type')}</th>
                                <th className="px-4 py-3">{t('record_host')}</th>
                                <th className="px-4 py-3">{t('record_value')}</th>
                                <th className="px-4 py-3 text-center">{t('record_verified')}</th>
                              </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                              {domain.required_dns_records?.map((record: DnsRecord, i: number) => (
                                <tr key={i} className="hover:bg-gray-50">
                                  <td className="px-4 py-3 font-mono font-medium">{record.type}</td>
                                  <td className="px-4 py-3 font-mono">{record.host}</td>
                                  <td className="px-4 py-3">
                                    <div className="flex items-center gap-2">
                                      <span className="font-mono text-xs break-all truncate max-w-xs">{record.value}</span>
                                      <button onClick={() => copyToClipboard(record.value)} className="text-gray-400 hover:text-indigo-600" title="Copy">
                                        <Copy className="w-4 h-4" />
                                      </button>
                                    </div>
                                  </td>
                                  <td className="px-4 py-3 text-center">
                                    {record.verified ? (
                                      <Badge variant="success" className="bg-green-100 text-green-700 hover:bg-green-100 border-0">Verified</Badge>
                                    ) : (
                                      <Badge variant="warning" className="bg-yellow-100 text-yellow-700 hover:bg-yellow-100 border-0">Pending</Badge>
                                    )}
                                  </td>
                                </tr>
                              ))}
                            </tbody>
                          </table>
                        </div>
                      </div>
                    )}
                  </div>
                ))
              )}
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
