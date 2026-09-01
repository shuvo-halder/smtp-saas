'use client';

import { DnsRecord } from '@/types';
import { Button } from '@/components/ui/button';
import { Copy, CheckCircle2, XCircle } from 'lucide-react';
import { useState } from 'react';

interface DnsRecordTableProps {
  records: DnsRecord[];
}

export function DnsRecordTable({ records }: DnsRecordTableProps) {
  const [copiedIndex, setCopiedIndex] = useState<number | null>(null);

  const copyToClipboard = async (text: string, index: number) => {
    try {
      await navigator.clipboard.writeText(text);
      setCopiedIndex(index);
      setTimeout(() => setCopiedIndex(null), 2000);
    } catch (err) {
      console.error('Failed to copy', err);
    }
  };

  return (
    <div className="rounded-md border overflow-hidden">
      <div className="bg-gray-50 p-4 border-b">
        <h4 className="font-semibold text-sm">Required DNS Records</h4>
        <p className="text-xs text-muted-foreground mt-1">
          Add these records to your domain's DNS settings at your registrar to verify ownership and enable email delivery.
        </p>
      </div>
      <div className="overflow-x-auto">
        <table className="w-full text-sm text-left">
          <thead className="text-xs text-gray-700 uppercase bg-gray-50 border-b">
            <tr>
              <th className="px-4 py-3">Type</th>
              <th className="px-4 py-3">Host / Name</th>
              <th className="px-4 py-3">Value</th>
              <th className="px-4 py-3 text-center">Status</th>
            </tr>
          </thead>
          <tbody>
            {records.map((record, index) => (
              <tr key={index} className="bg-white border-b last:border-0 hover:bg-gray-50">
                <td className="px-4 py-3 font-medium">{record.type}</td>
                <td className="px-4 py-3">
                  <div className="flex items-center gap-2">
                    <span className="font-mono bg-gray-100 px-1 py-0.5 rounded">{record.host}</span>
                  </div>
                </td>
                <td className="px-4 py-3">
                  <div className="flex items-center gap-2">
                    <span className="font-mono bg-gray-100 px-1 py-0.5 rounded break-all">{record.value}</span>
                    <Button 
                      variant="ghost" 
                      size="icon" 
                      className="h-6 w-6 ml-2" 
                      onClick={() => copyToClipboard(record.value, index)}
                      title="Copy to clipboard"
                    >
                      <Copy className="h-4 w-4" />
                    </Button>
                    {copiedIndex === index && <span className="text-xs text-green-500">Copied!</span>}
                  </div>
                </td>
                <td className="px-4 py-3 text-center">
                  {record.verified ? (
                    <div className="flex justify-center" title="Verified">
                      <CheckCircle2 className="h-5 w-5 text-green-500" />
                    </div>
                  ) : (
                    <div className="flex justify-center" title="Not Verified">
                      <XCircle className="h-5 w-5 text-red-500" />
                    </div>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
