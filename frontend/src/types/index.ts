export interface User {
  id: number;
  name: string;
  email: string;
  phone: string | null;
  company_name: string | null;
  status: 'active' | 'suspended' | 'pending';
  is_admin: boolean;
  plan: Plan | null;
  plan_expires_at: string | null;
  created_at: string;
}

export interface Plan {
  id: number;
  name: string;
  slug: string;
  description: string | null;
  max_domains: number;
  max_mailboxes_per_domain: number;
  storage_mb_per_mailbox: number;
  max_aliases_per_domain: number;
  price_monthly: number;
  price_yearly: number;
  features: string[];
  is_featured: boolean;
  yearly_discount: number;
  max_domains_label: string;
  max_mailboxes_label: string;
}

export interface Domain {
  id: number;
  domain_name: string;
  status: 'pending' | 'verifying' | 'active' | 'suspended' | 'failed';
  mx_verified: boolean;
  spf_verified: boolean;
  dkim_verified: boolean;
  dmarc_verified: boolean;
  dkim_selector: string;
  mailboxes_count: number;
  required_dns_records: DnsRecord[];
  activated_at: string | null;
  created_at: string;
}

export interface DnsRecord {
  type: string;
  host: string;
  value: string;
  verified: boolean;
}

export interface Mailbox {
  id: number;
  local_part: string;
  email: string;
  display_name: string | null;
  quota_mb: number;
  quota_formatted: string;
  is_active: boolean;
  is_catchall: boolean;
  webmail_url: string;
  last_login_at: string | null;
  created_at: string;
}

export interface Invoice {
  id: number;
  invoice_number: string;
  billing_cycle: 'monthly' | 'yearly';
  subtotal: number;
  tax: number;
  total: number;
  currency: string;
  status: 'pending' | 'paid' | 'failed' | 'cancelled' | 'refunded';
  payment_gateway: string | null;
  paid_at: string | null;
  due_date: string;
  period_start: string | null;
  period_end: string | null;
  formatted_total: string;
  plan: Plan;
  created_at: string;
}

export interface PaginatedResponse<T> {
  data: T[];
  meta: { current_page: number; last_page: number; per_page: number; total: number; };
  links: { first: string; last: string; prev: string | null; next: string | null; };
}

export interface DashboardStats {
  user: User;
  domains_count: number;
  mailboxes_count: number;
  recent_domains: Domain[];
  recent_invoices: Invoice[];
}
