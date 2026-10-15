'use client';

import React from 'react';
import { Invoice } from '@/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { X, Printer, Download, CheckCircle2, Clock, AlertTriangle, XCircle } from 'lucide-react';
import { formatCurrency, formatDate } from '@/lib/utils';

interface InvoiceDetailModalProps {
  invoice: Invoice | null;
  isOpen: boolean;
  onClose: () => void;
}

export default function InvoiceDetailModal({
  invoice,
  isOpen,
  onClose,
}: InvoiceDetailModalProps) {
  if (!isOpen || !invoice) return null;

  const handlePrint = () => {
    window.print();
  };

  const getStatusBadge = (status: string) => {
    switch (status) {
      case 'paid':
        return (
          <Badge variant="success" className="flex items-center gap-1">
            <CheckCircle2 className="w-3.5 h-3.5" /> Paid
          </Badge>
        );
      case 'pending':
        return (
          <Badge variant="warning" className="flex items-center gap-1">
            <Clock className="w-3.5 h-3.5" /> Pending
          </Badge>
        );
      case 'failed':
        return (
          <Badge variant="destructive" className="flex items-center gap-1">
            <AlertTriangle className="w-3.5 h-3.5" /> Failed
          </Badge>
        );
      case 'cancelled':
        return (
          <Badge variant="secondary" className="flex items-center gap-1">
            <XCircle className="w-3.5 h-3.5" /> Cancelled
          </Badge>
        );
      default:
        return <Badge variant="secondary">{status}</Badge>;
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm animate-in fade-in duration-150">
      <div 
        className="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl shadow-2xl max-w-2xl w-full max-h-[90vh] overflow-y-auto print:max-w-none print:shadow-none print:border-none print:m-0"
        role="dialog"
        aria-modal="true"
        aria-labelledby="invoice-modal-title"
      >
        {/* Modal Header */}
        <div className="flex items-center justify-between p-6 border-b border-gray-100 dark:border-gray-800 print:hidden">
          <div className="flex items-center space-x-3">
            <h2 id="invoice-modal-title" className="text-xl font-bold text-gray-900 dark:text-white">
              Invoice #{invoice.invoice_number}
            </h2>
            {getStatusBadge(invoice.status)}
          </div>
          <div className="flex items-center space-x-2">
            <Button variant="outline" size="sm" onClick={handlePrint} className="gap-1.5">
              <Printer className="w-4 h-4" /> Print
            </Button>
            {invoice.status === 'paid' && (
              <a
                href={`/api/billing/invoices/${invoice.id}/download`}
                target="_blank"
                rel="noopener noreferrer"
              >
                <Button variant="outline" size="sm" className="gap-1.5">
                  <Download className="w-4 h-4" /> PDF
                </Button>
              </a>
            )}
            <button
              onClick={onClose}
              className="p-1.5 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors"
              aria-label="Close modal"
            >
              <X className="w-5 h-5" />
            </button>
          </div>
        </div>

        {/* Invoice Content */}
        <div className="p-8 space-y-8 print:p-0">
          {/* Top Brand / Meta */}
          <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 border-b pb-6 dark:border-gray-800">
            <div>
              <h1 className="text-2xl font-extrabold tracking-tight text-indigo-600 dark:text-indigo-400">
                EmailSaaS
              </h1>
              <p className="text-xs text-gray-500 dark:text-gray-400 mt-1">
                Cloud Email Infrastructure & Delivery
              </p>
            </div>
            <div className="sm:text-right">
              <span className="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">
                Invoice Reference
              </span>
              <p className="text-base font-mono font-bold text-gray-900 dark:text-white">
                {invoice.invoice_number}
              </p>
              <p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                Issued: {formatDate(invoice.created_at)}
              </p>
            </div>
          </div>

          {/* Billing Meta Grid */}
          <div className="grid grid-cols-2 sm:grid-cols-3 gap-6 text-sm">
            <div>
              <span className="block text-xs font-semibold uppercase text-gray-400 dark:text-gray-500">
                Billing Cycle
              </span>
              <span className="font-medium text-gray-900 dark:text-white capitalize">
                {invoice.billing_cycle}
              </span>
            </div>
            <div>
              <span className="block text-xs font-semibold uppercase text-gray-400 dark:text-gray-500">
                Due Date
              </span>
              <span className="font-medium text-gray-900 dark:text-white">
                {invoice.due_date ? formatDate(invoice.due_date) : 'N/A'}
              </span>
            </div>
            <div>
              <span className="block text-xs font-semibold uppercase text-gray-400 dark:text-gray-500">
                Payment Status
              </span>
              <div className="mt-0.5">{getStatusBadge(invoice.status)}</div>
            </div>
            {invoice.paid_at && (
              <div>
                <span className="block text-xs font-semibold uppercase text-gray-400 dark:text-gray-500">
                  Payment Date
                </span>
                <span className="font-medium text-gray-900 dark:text-white">
                  {formatDate(invoice.paid_at)}
                </span>
              </div>
            )}
            {invoice.payment_gateway && (
              <div>
                <span className="block text-xs font-semibold uppercase text-gray-400 dark:text-gray-500">
                  Payment Gateway
                </span>
                <span className="font-medium text-gray-900 dark:text-white uppercase">
                  {invoice.payment_gateway}
                </span>
              </div>
            )}
            {invoice.period_start && invoice.period_end && (
              <div>
                <span className="block text-xs font-semibold uppercase text-gray-400 dark:text-gray-500">
                  Coverage Period
                </span>
                <span className="font-medium text-gray-900 dark:text-white">
                  {invoice.period_start} → {invoice.period_end}
                </span>
              </div>
            )}
          </div>

          {/* Line Items Table */}
          <div className="border rounded-lg overflow-hidden dark:border-gray-800">
            <table className="w-full text-sm text-left">
              <thead className="bg-gray-50 dark:bg-gray-800/60 text-xs uppercase font-semibold text-gray-500 dark:text-gray-400 border-b dark:border-gray-800">
                <tr>
                  <th className="px-6 py-3">Description</th>
                  <th className="px-6 py-3 text-center">Cycle</th>
                  <th className="px-6 py-3 text-right">Amount</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100 dark:divide-gray-800 bg-white dark:bg-gray-900">
                <tr>
                  <td className="px-6 py-4">
                    <p className="font-semibold text-gray-900 dark:text-white">
                      {invoice.plan?.name || 'Subscription Plan'}
                    </p>
                    <p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                      {invoice.plan?.description || 'Email hosting and business delivery plan'}
                    </p>
                  </td>
                  <td className="px-6 py-4 text-center capitalize text-gray-600 dark:text-gray-300">
                    {invoice.billing_cycle}
                  </td>
                  <td className="px-6 py-4 text-right font-medium text-gray-900 dark:text-white">
                    {formatCurrency(invoice.subtotal, invoice.currency)}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          {/* Totals Summary */}
          <div className="flex justify-end">
            <div className="w-full sm:w-64 space-y-2 text-sm">
              <div className="flex justify-between text-gray-600 dark:text-gray-400">
                <span>Subtotal:</span>
                <span>{formatCurrency(invoice.subtotal, invoice.currency)}</span>
              </div>
              <div className="flex justify-between text-gray-600 dark:text-gray-400">
                <span>Tax / VAT (0%):</span>
                <span>{formatCurrency(invoice.tax || 0, invoice.currency)}</span>
              </div>
              <div className="flex justify-between pt-2 border-t font-bold text-base text-gray-900 dark:text-white dark:border-gray-800">
                <span>Total Due:</span>
                <span className="text-indigo-600 dark:text-indigo-400">
                  {formatCurrency(invoice.total, invoice.currency)}
                </span>
              </div>
            </div>
          </div>

          {/* Footer Note */}
          <div className="pt-6 border-t text-xs text-gray-400 dark:text-gray-500 text-center dark:border-gray-800">
            Thank you for choosing EmailSaaS. For questions or support, contact support@emailsaas.local.
          </div>
        </div>

        {/* Modal Action Footer */}
        <div className="flex justify-end p-4 border-t bg-gray-50 dark:bg-gray-800/50 dark:border-gray-800 print:hidden">
          <Button variant="outline" onClick={onClose}>
            Close
          </Button>
        </div>
      </div>
    </div>
  );
}
