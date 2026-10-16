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
  catch_all_policy?: CatchAllPolicy;
  catch_all_mailbox_id?: number | null;
  required_dns_records: DnsRecord[];
  activated_at: string | null;
  created_at: string;
}

export type CatchAllPolicy = 'disabled' | 'reject' | 'mailbox' | 'discard';

export interface CatchAllMailbox {
  id: number;
  email: string;
  local_part: string;
  display_name: string | null;
  is_active: boolean;
}

export interface CatchAllRoutingDetails {
  domain_name: string;
  policy: CatchAllPolicy;
  is_routable: boolean;
  action: 'reject' | 'discard' | 'mailbox';
  probe_email: string;
  destination: string | null;
  terminal_target: string | null;
  status: 'synchronized' | 'operational' | 'configured' | 'failed' | 'unknown';
  warning: string | null;
  mta_driver: string;
}

export interface DomainCatchAllDetails {
  domain_id: number;
  domain_name: string;
  domain_status: string;
  is_verified: boolean;
  policy: CatchAllPolicy;
  is_enabled: boolean;
  mailbox: CatchAllMailbox | null;
  routing: CatchAllRoutingDetails;
  available_mailboxes: CatchAllMailbox[];
  updated_at: string | null;
}

export interface CatchAllUpdateRequest {
  policy: CatchAllPolicy;
  mailbox_id?: number | null;
}

export interface DnsRecord {
  type: string;
  host: string;
  value: string;
  verified: boolean;
}

export type MailboxStorageStatus = 'normal' | 'warning' | 'near_limit' | 'over_limit' | 'unknown';

export interface MailboxStorageDetails {
  mailbox_id: number;
  email: string;
  local_part?: string;
  domain_id: number;
  domain_name?: string;
  domain_status?: string;
  is_active: boolean;
  quota_mb: number;
  quota_bytes: number;
  quota_formatted: string;
  plan_limit_mb: number;
  plan_limit_formatted?: string;
  usage_bytes: number | null;
  usage_mb: number | null;
  usage_formatted: string;
  remaining_bytes: number | null;
  remaining_mb: number | null;
  remaining_formatted: string;
  utilization_percent: number | null;
  status: MailboxStorageStatus;
  status_label: string;
  is_measured: boolean;
  measurement_source: string;
  measured_at: string | null;
  warning: string | null;
}

export interface TenantStorageSummary {
  total_mailboxes: number;
  active_mailboxes: number;
  total_quota_mb: number;
  total_quota_formatted: string;
  plan_storage_limit_per_mailbox_mb: number;
  plan_storage_limit_formatted?: string;
  measured_mailboxes_count: number;
  unmeasured_mailboxes_count: number;
  total_usage_bytes: number | null;
  total_usage_mb: number | null;
  total_usage_formatted: string;
  aggregate_utilization_percent: number | null;
  aggregate_status: MailboxStorageStatus;
  aggregate_status_label?: string;
  status_counts: Record<MailboxStorageStatus, number>;
  measurement_source: string;
  environment_support: boolean;
  environment_note: string;
}

export interface TenantStorageOverviewResponse {
  summary: TenantStorageSummary;
  data: MailboxStorageDetails[];
  links?: {
    first: string | null;
    last: string | null;
    prev: string | null;
    next: string | null;
  };
  meta?: {
    current_page: number;
    from: number | null;
    last_page: number;
    per_page: number;
    to: number | null;
    total: number;
  };
}

export interface Mailbox {
  id: number;
  domain_id?: number;
  domain_name?: string;
  local_part: string;
  email: string;
  display_name: string | null;
  quota_mb: number;
  quota_formatted: string;
  is_active: boolean;
  is_catchall: boolean;
  is_forwarding_enabled?: boolean;
  auto_reply_enabled?: boolean;
  webmail_url: string;
  last_login_at: string | null;
  storage?: MailboxStorageDetails;
  created_at: string;
}

export interface MailboxForwardingSettings {
  is_enabled: boolean;
  destinations: string[];
  keep_copy: boolean;
  is_routable: boolean;
  cycle_detected: boolean;
  cycle_path: string | null;
  status: 'synchronized' | 'operational' | 'configured' | 'failed' | 'unknown';
  warning: string | null;
}

export interface MailboxAutoReplySettings {
  is_enabled: boolean;
  subject: string | null;
  body: string | null;
  start_at: string | null;
  end_at: string | null;
  schedule_status: 'active' | 'scheduled' | 'expired' | 'disabled';
  is_currently_active: boolean;
}

export interface MailboxSieveSettings {
  script_preview: string;
  syntax_valid: boolean;
  driver: string;
  delivery_transport: string;
  environment_mode: 'production' | 'local_simulation';
}

export interface MailboxMtaSettings {
  postfix_operational: boolean;
  map_query: string;
  status: 'synchronized' | 'operational';
}

export interface MailboxRulesDetails {
  mailbox_id: number;
  email: string;
  domain_name: string;
  forwarding: MailboxForwardingSettings;
  auto_reply: MailboxAutoReplySettings;
  sieve: MailboxSieveSettings;
  mta: MailboxMtaSettings;
}

export interface ForwardingUpdateRequest {
  is_forwarding_enabled: boolean;
  destinations: string[];
  keep_copy?: boolean;
}

export interface AutoReplyUpdateRequest {
  auto_reply_enabled: boolean;
  subject?: string | null;
  body?: string | null;
  start_at?: string | null;
  end_at?: string | null;
}

export interface EmailAliasRoutingDetails {
  is_routable: boolean;
  terminal_target: string | null;
  hop_count: number;
  hops: string[];
  warning: string | null;
  mta_driver: string;
}

export interface EmailAlias {
  id: number;
  domain_id: number;
  domain_name?: string;
  source_email: string;
  destination_email: string;
  is_active: boolean;
  forwarding_status: 'configured' | 'synchronized' | 'operational' | 'failed' | 'unknown' | string;
  routing_details?: EmailAliasRoutingDetails;
  created_at: string;
  updated_at: string;
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
  tenants_over_quota_count?: number | null;
  reconciliation_status?: 'healthy' | 'attention_required' | 'unknown';
  checked_at: string;
}

export interface SmtpTenant {
  id: number;
  name: string;
  email: string;
  status: 'active' | 'suspended' | 'pending';
  is_subscription_active: boolean;
  subscription_state?: 'valid' | 'expired' | 'suspended' | 'pending' | 'no_plan' | 'admin_exempt';
  plan_name: string;
  daily_quota: number;
  remaining_daily_quota?: number | null;
  quota_usage_percent?: number | null;
  is_over_quota?: boolean;
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

export interface AuditLog {
  id: number;
  actor_user_id: number | null;
  actor_name: string;
  actor_email: string;
  action: string;
  entity_type: string | null;
  entity_id: string | number | null;
  before_state: Record<string, any> | null;
  after_state: Record<string, any> | null;
  reason: string | null;
  ip_address: string | null;
  user_agent: string | null;
  request_id: string | null;
  outcome?: string;
  created_at: string;
}

export type IncidentSeverity = 'low' | 'medium' | 'high' | 'critical';
export type IncidentStatus = 'open' | 'resolved' | 'dismissed';

export interface AbuseIncident {
  id: number;
  uuid: string;
  tenant_id: number | null;
  tenant_email: string | null;
  domain_id: number | null;
  domain_name: string | null;
  mailbox_id: number | null;
  mailbox_email: string | null;
  incident_type: string;
  severity: IncidentSeverity;
  status: IncidentStatus;
  detection_source: string;
  summary: string;
  threshold_value: string | number | null;
  observed_value: string | number | null;
  evidence: Record<string, any>;
  idempotency_key: string;
  occurred_at: string;
  resolved_at: string | null;
  resolved_by: number | null;
  resolver_name: string | null;
  resolution_notes: string | null;
  created_at: string;
  updated_at: string;
}

export interface AbuseIncidentFilters {
  page?: number;
  per_page?: number;
  status?: string;
  severity?: string;
  incident_type?: string;
  detection_source?: string;
  search?: string;
  tenant_id?: number;
  domain_id?: number;
  mailbox_id?: number;
  date_from?: string;
  date_to?: string;
}

export interface BackupStatusItem {
  name: string;
  size_formatted: string;
  size_bytes: number;
  is_encrypted: boolean;
  created_at: string;
  age_formatted: string;
  verified: string;
  offsite_status: string;
  has_manifest: boolean;
}

export interface BackupSectionStatus {
  title: string;
  directory: string;
  total_archives: number;
  total_size_formatted: string;
  latest_archive: string | null;
  latest_age_hours: number | null;
  health_status: 'healthy' | 'warning' | 'critical' | 'not_configured';
  archives: BackupStatusItem[];
}

export interface BackupHealthPosture {
  encryption: {
    enabled: boolean;
    cipher: string;
    kdf: string;
    envelope: string;
    fail_closed: boolean;
  };
  offsite: {
    enabled: boolean;
    transport: string;
    host: string;
    pre_flight_check: boolean;
    fail_safe_local_preservation: boolean;
  };
  verification_matrix: {
    archive_integrity: 'VERIFIED' | 'FAILED' | 'PENDING';
    isolated_schema_restore: 'VERIFIED' | 'FAILED' | 'PENDING';
    isolated_mail_restore: 'VERIFIED' | 'FAILED' | 'PENDING';
    live_mariadb_restore: 'PARTIALLY VERIFIED' | 'PENDING INFRASTRUCTURE';
    live_dovecot_restore: 'PARTIALLY VERIFIED' | 'PENDING INFRASTRUCTURE';
    pitr: 'NOT IMPLEMENTED';
  };
}

// ==========================================
// STEP 19: SYSTEM HEALTH & SCHEDULED JOBS
// ==========================================

export type SystemHealthStatus = 'HEALTHY' | 'DEGRADED' | 'UNHEALTHY' | 'UNKNOWN' | 'NOT_CONFIGURED';

export interface ComponentHealth {
  status: SystemHealthStatus;
  details: Record<string, any>;
}

export interface ScheduledTaskRunItem {
  id: number;
  uuid: string;
  task_name: string;
  expression: string | null;
  status: 'running' | 'success' | 'failed' | 'skipped';
  started_at: string | null;
  finished_at: string | null;
  duration_ms: number | null;
  failure_summary: string | null;
}

export interface SystemHealthResponse {
  status: SystemHealthStatus;
  timestamp: string;
  environment: string;
  components: {
    app: ComponentHealth;
    database: ComponentHealth;
    redis: ComponentHealth;
    scheduler: ComponentHealth;
    task_failures: ComponentHealth;
    queue: ComponentHealth;
    backup: ComponentHealth;
  };
  recent_tasks: ScheduledTaskRunItem[];
}

export interface TenantSubscriptionSummary {
  plan: Plan | null;
  subscription: {
    status: 'active' | 'expired' | 'suspended' | 'inactive';
    is_active: boolean;
    expires_at: string | null;
    days_remaining: number | null;
    is_expiring_soon: boolean;
    can_renew: boolean;
  };
  usage: {
    domains_used: number;
    domains_limit: number;
    mailboxes_used: number;
    mailboxes_limit: number;
    max_mailboxes_per_domain: number;
    outbound_today: number;
    outbound_daily_limit: number;
    storage_mb_per_mailbox: number;
  };
}

export interface SmtpPortConfig {
  port: number;
  protocol: string;
  recommended: boolean;
  description: string;
}

export interface SmtpSettings {
  hostname: string;
  ports: SmtpPortConfig[];
  authentication: string;
  username_format: string;
  password_format: string;
  tls_minimum_version: string;
  code_snippets: {
    nodejs: string;
    python: string;
    php: string;
    env: string;
  };
}

export interface SmtpUsageHistoryItem {
  date: string;
  recipient_count: number;
  is_today: boolean;
}

export interface SmtpMailboxUsage {
  id: number;
  email: string;
  display_name: string | null;
  is_active: boolean;
  used_today: number;
  mailbox_daily_limit: number;
}

export interface TenantSmtpUsage {
  plan_name: string;
  daily_limit: number;
  is_unlimited: boolean;
  mailbox_daily_limit: number;
  used_today: number;
  remaining_today: number | null;
  percent_used: number;
  history: SmtpUsageHistoryItem[];
  mailboxes_breakdown: SmtpMailboxUsage[];
}

export interface SmtpCredential {
  id: number;
  domain_id: number;
  domain_name: string;
  email: string;
  display_name: string | null;
  is_active: boolean;
  last_login_at: string | null;
  created_at: string;
}

export interface RotatePasswordResponse {
  message: string;
  email: string;
  generated_password: string;
  warning: string;
}

export interface DeliveryLogItem {
  id: number;
  domain_id: number | null;
  mailbox_id: number | null;
  sender_email: string;
  recipient_email: string;
  queue_id: string | null;
  status: 'delivered' | 'deferred' | 'bounced' | 'rejected' | 'accepted';
  dsn_code: string | null;
  smtp_code: number | null;
  response_text: string | null;
  occurred_at: string;
  created_at: string;
}

export interface SmtpDiagnosticCheck {
  key: string;
  title: string;
  status: 'pass' | 'warning' | 'fail';
  summary: string;
  remediation: string | null;
}

export interface SmtpCommonError {
  code: string;
  cause: string;
  remediation: string;
}

export interface SmtpDiagnosticsResponse {
  status: 'healthy' | 'warning' | 'error';
  checks: SmtpDiagnosticCheck[];
  common_errors: SmtpCommonError[];
}

