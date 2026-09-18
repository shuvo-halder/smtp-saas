export interface User {
  id: number;
  name: string;
  email: string;
  phone: string | null;
  company_name: string | null;
  address: string | null;
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
  is_active: boolean;
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

export interface SmtpOverview {
  telemetry_available: boolean;
  cluster_recipients_today: number | null;
  cluster_hard_bounces_today: number | null;
  cluster_soft_bounces_today: number | null;
  cluster_bounce_rate: number | null;
  mail_queue_size: number | null;
  total_tenants: number;
  total_mailboxes: number;
  active_abuse_warnings_count: number | null;
  checked_at: string;
}

export interface SmtpTenant {
  id: number;
  name: string;
  email: string;
  status: 'active' | 'suspended' | 'pending';
  is_subscription_active: boolean;
  plan_name: string;
  daily_quota: number;
  mailbox_daily_quota: number;
  domains_count: number;
  mailboxes_count: number;
  telemetry_available: boolean;
  today_recipients: number | null;
  today_hard_bounces: number | null;
  today_soft_bounces: number | null;
  bounce_rate: number | null;
  abuse_status: 'healthy' | 'warning' | 'critical' | 'unknown';
  active_alerts: string[];
  created_at: string;
}

export interface SmtpMailbox {
  id: number;
  local_part: string;
  email: string;
  display_name: string | null;
  domain_id: number;
  domain_name: string;
  tenant_id: number | null;
  tenant_name: string;
  is_active: boolean;
  parent_domain_status: string;
  parent_tenant_status: string;
  parent_subscription_active: boolean;
  can_be_enabled: boolean;
  telemetry_available: boolean;
  today_recipients: number | null;
  today_hard_bounces: number | null;
  today_soft_bounces: number | null;
  consecutive_hard_bounces: number | null;
  created_at: string;
}

export interface AbuseWarning {
  entity_type: 'tenant' | 'mailbox';
  entity_id: number;
  identifier: string;
  name: string;
  alert_type: string;
  current_value: number | string;
  threshold: number | string;
  message: string;
}

export interface AbuseResponse {
  telemetry_available: boolean;
  warnings: AbuseWarning[];
}

