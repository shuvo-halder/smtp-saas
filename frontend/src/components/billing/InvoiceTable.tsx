'use client';

import React, { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Invoice } from '@/types';
import { formatCurrency, formatDate } from '@/lib/utils';
import { Download, Eye, ChevronLeft, ChevronRight, CheckCircle2, Clock, AlertTriangle, XCircle } from 'lucide-react';
import InvoiceDetailModal from './InvoiceDetailModal';

interface InvoiceTableProps {
  invoices: Invoice[];
  isLoading: boolean;
  currentPage?: number;
  lastPage?: number;
  total?: number;
  onPageChange?: (page: number) => void;
  statusFilter?: string;
  onStatusFilterChange?: (status: string) => void;
}

export default function InvoiceTable({
  invoices,
  isLoading,
  currentPage = 1,
  lastPage = 1,
  total = 0,
  onPageChange,
  statusFilter = 'all',
  onStatusFilterChange,
}: InvoiceTableProps) {
  const [selectedInvoice, setSelectedInvoice] = useState<Invoice | null>(null);
  const [isModalOpen, setIsModalOpen] = useState(false);

  const handleViewInvoice = (invoice: Invoice) => {
    setSelectedInvoice(invoice);
    setIsModalOpen(true);
  };

  const getStatusBadge = (status: string) => {
    switch (status) {
      case 'paid':
        return (
          <Badge variant="success" className="gap-1 inline-flex items-center">
            <CheckCircle2 className="w-3 h-3" /> Paid
          </Badge>
        );
      case 'pending':
        return (
          <Badge variant="warning" className="gap-1 inline-flex items-center">
            <Clock className="w-3 h-3" /> Pending
          </Badge>
        );
      case 'failed':
        return (
          <Badge variant="destructive" className="gap-1 inline-flex items-center">
            <AlertTriangle className="w-3 h-3" /> Failed
          </Badge>
        );
      case 'cancelled':
        return (
          <Badge variant="secondary" className="gap-1 inline-flex items-center">
            <XCircle className="w-3 h-3" /> Cancelled
          </Badge>
        );
      default:
        return <Badge variant="secondary">{status}</Badge>;
    }
  };

  return (
    <div className="space-y-4">
      {/* Filter and Summary Toolbar */}
      <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3">
        <div className="flex items-center gap-2">
          <span className="text-sm font-medium text-gray-500 dark:text-gray-400">Filter by status:</span>
          {onStatusFilterChange && (
            <select
              value={statusFilter}
              onChange={(e) => onStatusFilterChange(e.target.value)}
              className="text-sm border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 rounded-md px-2.5 py-1.5 focus:outline-none focus:ring-2 focus:ring-indigo-500"
            >
              <option value="all">All Invoices</option>
              <option value="paid">Paid</option>
              <option value="pending">Pending</option>
              <option value="failed">Failed</option>
              <option value="cancelled">Cancelled</option>
            </select>
          )}
        </div>
        <div className="text-xs text-gray-500 dark:text-gray-400">
          Total records: <span className="font-semibold text-gray-900 dark:text-white">{total}</span>
        </div>
      </div>

      {/* Main Table Card */}
      <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
        {isLoading ? (
          <div className="text-center py-12 text-gray-500 dark:text-gray-400">
            <div className="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600 mb-2"></div>
            <p>Loading invoice records...</p>
          </div>
        ) : !invoices || invoices.length === 0 ? (
          <div className="text-center py-12 px-4">
            <h3 className="text-base font-semibold text-gray-900 dark:text-white">No invoices found</h3>
            <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
              {statusFilter !== 'all'
                ? `No invoices matched the "${statusFilter}" status filter.`
                : 'No invoice history found for this account.'}
            </p>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
              <thead className="bg-gray-50 dark:bg-gray-900/60">
                <tr>
                  <th className="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Invoice #
                  </th>
                  <th className="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Plan
                  </th>
                  <th className="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Cycle
                  </th>
                  <th className="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Amount
                  </th>
                  <th className="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Status
                  </th>
                  <th className="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Issued Date
                  </th>
                  <th className="px-6 py-3.5 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Actions
                  </th>
                </tr>
              </thead>
              <tbody className="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                {invoices.map((invoice) => (
                  <tr key={invoice.id} className="hover:bg-gray-50/70 dark:hover:bg-gray-700/50 transition-colors">
                    <td className="px-6 py-4 whitespace-nowrap text-sm font-mono font-medium text-gray-900 dark:text-white">
                      {invoice.invoice_number}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-gray-300">
                      {invoice.plan?.name || 'Subscription Plan'}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-gray-300 capitalize">
                      {invoice.billing_cycle}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-900 dark:text-white">
                      {formatCurrency(invoice.total, invoice.currency)}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm">
                      {getStatusBadge(invoice.status)}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                      {formatDate(invoice.created_at)}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                      <div className="flex items-center justify-end space-x-2">
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => handleViewInvoice(invoice)}
                          className="h-8 px-2 text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300"
                        >
                          <Eye className="w-4 h-4 mr-1" /> View
                        </Button>
                        {invoice.status === 'paid' && (
                          <a
                            href={`/api/billing/invoices/${invoice.id}/download`}
                            target="_blank"
                            rel="noopener noreferrer"
                          >
                            <Button variant="outline" size="sm" className="h-8 px-2 text-xs">
                              <Download className="w-3.5 h-3.5 mr-1" /> PDF
                            </Button>
                          </a>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {/* Pagination Bar */}
        {onPageChange && lastPage > 1 && (
          <div className="flex items-center justify-between px-6 py-3.5 bg-gray-50 dark:bg-gray-900/40 border-t border-gray-200 dark:border-gray-700">
            <span className="text-xs text-gray-500 dark:text-gray-400">
              Page <span className="font-semibold text-gray-900 dark:text-white">{currentPage}</span> of{' '}
              <span className="font-semibold text-gray-900 dark:text-white">{lastPage}</span>
            </span>
            <div className="flex items-center space-x-2">
              <Button
                variant="outline"
                size="sm"
                onClick={() => onPageChange(currentPage - 1)}
                disabled={currentPage <= 1}
                className="h-8 px-2.5 text-xs"
              >
                <ChevronLeft className="w-4 h-4 mr-1" /> Prev
              </Button>
              <Button
                variant="outline"
                size="sm"
                onClick={() => onPageChange(currentPage + 1)}
                disabled={currentPage >= lastPage}
                className="h-8 px-2.5 text-xs"
              >
                Next <ChevronRight className="w-4 h-4 ml-1" />
              </Button>
            </div>
          </div>
        )}
      </div>

      {/* Invoice Detail Modal */}
      <InvoiceDetailModal
        invoice={selectedInvoice}
        isOpen={isModalOpen}
        onClose={() => {
          setIsModalOpen(false);
          setSelectedInvoice(null);
        }}
      />
    </div>
  );
}
