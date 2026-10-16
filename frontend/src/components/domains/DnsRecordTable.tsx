'use client';

import { DnsRecord } from '@/types';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { 
  Copy, 
  CheckCircle2, 
  XCircle, 
  AlertTriangle, 
  HelpCircle, 
  ChevronDown, 
  ChevronRight,
  Info
} from 'lucide-react';
import { useState } from 'react';

interface DnsRecordTableProps {
  records?: DnsRecord[] | Record<string, DnsRecord>;
  showTroubleshooting?: boolean;
}

export function DnsRecordTable({ records = [], showTroubleshooting = true }: DnsRecordTableProps) {
  const [copiedKey, setCopiedKey] = useState<string | null>(null);
  const [expandedRows, setExpandedRows] = useState<Record<string, boolean>>({});

  const recordList: DnsRecord[] = Array.isArray(records) 
    ? records 
    : (records ? Object.values(records) : []);

  const copyToClipboard = async (text: string, key: string) => {
    try {
      await navigator.clipboard.writeText(text);
      setCopiedKey(key);
      setTimeout(() => setCopiedKey(null), 2000);
    } catch (err) {
      console.error('Failed to copy', err);
    }
  };

  const toggleRow = (id: string) => {
    setExpandedRows(prev => ({ ...prev, [id]: !prev[id] }));
  };

  const renderStatusBadge = (record: DnsRecord) => {
    const status = record.status || (record.verified ? 'VERIFIED' : 'EXPECTED');

    switch (status) {
      case 'VERIFIED':
        return (
          <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-green-50 text-green-700 border border-green-200">
            <CheckCircle2 className="h-3.5 w-3.5 text-green-600" />
            Verified
          </span>
        );
      case 'MISMATCH':
        return (
          <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-amber-50 text-amber-700 border border-amber-200">
            <AlertTriangle className="h-3.5 w-3.5 text-amber-600" />
            Mismatch
          </span>
        );
      case 'MISSING':
        return (
          <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-red-50 text-red-700 border border-red-200">
            <XCircle className="h-3.5 w-3.5 text-red-600" />
            Missing
          </span>
        );
      case 'CHECK_FAILED':
      case 'UNRESOLVED':
        return (
          <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-orange-50 text-orange-700 border border-orange-200">
            <HelpCircle className="h-3.5 w-3.5 text-orange-600" />
            Check Failed
          </span>
        );
      case 'NOT_CONFIGURED':
        return (
          <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-700 border border-gray-200">
            <Info className="h-3.5 w-3.5 text-gray-500" />
            Pending Key
          </span>
        );
      case 'EXPECTED':
      default:
        return (
          <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-blue-50 text-blue-700 border border-blue-200">
            <Info className="h-3.5 w-3.5 text-blue-600" />
            Required
          </span>
        );
    }
  };

  if (recordList.length === 0) {
    return (
      <div className="rounded-md border p-6 text-center text-sm text-muted-foreground bg-gray-50">
        No DNS records available for this domain.
      </div>
    );
  }

  return (
    <div className="rounded-lg border overflow-hidden shadow-sm bg-white">
      <div className="overflow-x-auto">
        <table className="w-full text-sm text-left">
          <thead className="text-xs text-gray-700 uppercase bg-gray-50 border-b">
            <tr>
              <th className="px-4 py-3 w-16">Type</th>
              <th className="px-4 py-3">Host / Name</th>
              <th className="px-4 py-3">Expected Value</th>
              <th className="px-4 py-3 text-center w-20">Priority</th>
              <th className="px-4 py-3">Purpose</th>
              <th className="px-4 py-3 text-center w-28">Status</th>
              {showTroubleshooting && <th className="px-4 py-3 text-right w-16">Details</th>}
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-200">
            {recordList.map((record, index) => {
              const rowId = record.id || `${record.type}-${record.host}-${index}`;
              const isExpanded = !!expandedRows[rowId];
              const hasDiagnostics = record.troubleshooting || record.actual_value;

              return (
                <tr key={rowId} className="group hover:bg-gray-50/70 transition-colors">
                  {/* Type */}
                  <td className="px-4 py-3 font-semibold">
                    <Badge variant="outline" className="font-mono text-xs uppercase bg-gray-100 border-gray-300">
                      {record.type}
                    </Badge>
                  </td>

                  {/* Host */}
                  <td className="px-4 py-3">
                    <div className="flex items-center gap-2">
                      <span className="font-mono bg-gray-100 px-1.5 py-0.5 rounded text-xs text-gray-800 break-all select-all">
                        {record.host}
                      </span>
                      <Button
                        variant="ghost"
                        size="icon"
                        className="h-6 w-6 text-gray-500 hover:text-gray-900"
                        onClick={() => copyToClipboard(record.host, `host-${rowId}`)}
                        title="Copy Host"
                      >
                        <Copy className="h-3.5 w-3.5" />
                      </Button>
                      {copiedKey === `host-${rowId}` && (
                        <span className="text-[10px] text-green-600 font-medium">Copied!</span>
                      )}
                    </div>
                  </td>

                  {/* Value */}
                  <td className="px-4 py-3 max-w-xs md:max-w-md">
                    <div className="flex items-start gap-2">
                      <span className="font-mono bg-gray-50 border border-gray-200 p-1.5 rounded text-xs text-gray-900 break-all select-all line-clamp-3 group-hover:line-clamp-none">
                        {record.value || record.expected_value}
                      </span>
                      <Button
                        variant="ghost"
                        size="icon"
                        className="h-6 w-6 shrink-0 mt-0.5 text-gray-500 hover:text-gray-900"
                        onClick={() => copyToClipboard(record.value || record.expected_value || '', `val-${rowId}`)}
                        title="Copy Value"
                      >
                        <Copy className="h-3.5 w-3.5" />
                      </Button>
                      {copiedKey === `val-${rowId}` && (
                        <span className="text-[10px] text-green-600 font-medium shrink-0">Copied!</span>
                      )}
                    </div>
                  </td>

                  {/* Priority */}
                  <td className="px-4 py-3 text-center text-xs font-mono text-gray-600">
                    {record.priority !== null && record.priority !== undefined ? (
                      <span className="bg-blue-50 text-blue-700 px-2 py-0.5 rounded-full font-medium">
                        {record.priority}
                      </span>
                    ) : (
                      <span className="text-gray-400">—</span>
                    )}
                  </td>

                  {/* Purpose */}
                  <td className="px-4 py-3 text-xs text-gray-600">
                    <div>
                      <span className="font-medium text-gray-900">{record.purpose || `${record.type} Record`}</span>
                      {record.purpose_description && (
                        <p className="text-[11px] text-gray-500 mt-0.5 line-clamp-1">{record.purpose_description}</p>
                      )}
                    </div>
                  </td>

                  {/* Status */}
                  <td className="px-4 py-3 text-center">
                    {renderStatusBadge(record)}
                  </td>

                  {/* Troubleshooting Expand Toggle */}
                  {showTroubleshooting && (
                    <td className="px-4 py-3 text-right">
                      {hasDiagnostics ? (
                        <Button
                          variant="ghost"
                          size="icon"
                          className="h-7 w-7 text-gray-500 hover:text-gray-900"
                          onClick={() => toggleRow(rowId)}
                          title={isExpanded ? 'Hide Diagnostics' : 'Show Diagnostics'}
                        >
                          {isExpanded ? (
                            <ChevronDown className="h-4 w-4" />
                          ) : (
                            <ChevronRight className="h-4 w-4" />
                          )}
                        </Button>
                      ) : (
                        <span className="text-gray-300">—</span>
                      )}
                    </td>
                  )}
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>

      {/* Expanded Troubleshooting Rows */}
      {showTroubleshooting && (
        <div className="divide-y divide-gray-100 bg-gray-50/50">
          {recordList
            .filter(r => expandedRows[r.id || `${r.type}-${r.host}`])
            .map(record => {
              const rowId = record.id || `${record.type}-${record.host}`;
              return (
                <div key={`diag-${rowId}`} className="p-4 bg-amber-50/30 border-t border-amber-100 text-xs">
                  <div className="flex items-start gap-3">
                    <Info className="h-4 w-4 text-amber-600 mt-0.5 shrink-0" />
                    <div className="space-y-1.5 flex-1">
                      <div className="font-semibold text-gray-900 flex items-center gap-2">
                        <span>{record.type} ({record.host}) Diagnostics</span>
                        {renderStatusBadge(record)}
                      </div>
                      {record.actual_value && (
                        <div className="text-gray-700">
                          <strong className="text-gray-900">Observed in Public DNS:</strong>{' '}
                          <code className="font-mono bg-white px-1.5 py-0.5 rounded border border-gray-200">
                            {record.actual_value}
                          </code>
                        </div>
                      )}
                      {record.troubleshooting && (
                        <div className="text-amber-900 bg-amber-50 border border-amber-200 p-2.5 rounded">
                          <strong className="block text-amber-950 mb-0.5">Troubleshooting Recommendation:</strong>
                          {record.troubleshooting}
                        </div>
                      )}
                      {record.last_checked_at && (
                        <div className="text-[11px] text-gray-500">
                          Last checked: {new Date(record.last_checked_at).toLocaleString()}
                        </div>
                      )}
                    </div>
                  </div>
                </div>
              );
            })}
        </div>
      )}
    </div>
  );
}
