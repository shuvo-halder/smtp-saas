import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert';

console.log('=== Step 18 Frontend Verification Suite ===\n');

// 1. Verify en.json i18n dictionary completeness
const enJsonPath = path.resolve('messages/en.json');
assert(fs.existsSync(enJsonPath), 'messages/en.json must exist');
const en = JSON.parse(fs.readFileSync(enJsonPath, 'utf8'));

console.log('✓ Loaded messages/en.json successfully');

// Assert Admin.sidebar keys
assert(en.Admin?.sidebar?.backup_dr, 'Admin.sidebar.backup_dr translation key missing');
assert(en.Admin?.sidebar?.smtp, 'Admin.sidebar.smtp translation key missing');
console.log('✓ Admin.sidebar navigation keys verified');

// Assert Admin.smtp.tabs.incidents
assert(en.Admin?.smtp?.tabs?.incidents, 'Admin.smtp.tabs.incidents key missing');
console.log('✓ Admin.smtp.tabs.incidents key verified');

// Assert Admin.smtp.incidents keys
const incidents = en.Admin?.smtp?.incidents;
assert(incidents, 'Admin.smtp.incidents translation block missing');
assert(incidents.title, 'incidents.title missing');
assert(incidents.subtitle, 'incidents.subtitle missing');
assert(incidents.search_placeholder, 'incidents.search_placeholder missing');
assert(incidents.filter_status, 'incidents.filter_status missing');
assert(incidents.filter_severity, 'incidents.filter_severity missing');
assert(incidents.cards?.open_view, 'incidents.cards.open_view missing');
assert(incidents.cards?.critical_view, 'incidents.cards.critical_view missing');
assert(incidents.table?.severity, 'incidents.table.severity missing');
assert(incidents.table?.occurred, 'incidents.table.occurred missing');
assert(incidents.severity?.critical, 'incidents.severity.critical missing');
assert(incidents.severity?.high, 'incidents.severity.high missing');
assert(incidents.status?.open, 'incidents.status.open missing');
assert(incidents.status?.resolved, 'incidents.status.resolved missing');
assert(incidents.types?.bounce_rate_spike, 'incidents.types.bounce_rate_spike missing');
assert(incidents.sources?.log_parser, 'incidents.sources.log_parser missing');
assert(incidents.detail_modal?.title, 'incidents.detail_modal.title missing');
assert(incidents.detail_modal?.evidence, 'incidents.detail_modal.evidence missing');
assert(incidents.resolve_modal?.resolve_title, 'incidents.resolve_modal.resolve_title missing');
assert(incidents.resolve_modal?.confirm_resolve, 'incidents.resolve_modal.confirm_resolve missing');
assert(incidents.resolve_modal?.permission_denied, 'incidents.resolve_modal.permission_denied missing');
console.log('✓ Admin.smtp.incidents dictionary keys verified');

// Assert Admin.backup_dr keys
const backupDr = en.Admin?.backup_dr;
assert(backupDr, 'Admin.backup_dr translation block missing');
assert(backupDr.title, 'backup_dr.title missing');
assert(backupDr.gap_banner?.title, 'backup_dr.gap_banner.title missing');
assert(backupDr.gap_banner?.badge, 'backup_dr.gap_banner.badge missing');
assert(backupDr.posture?.backup_db, 'backup_dr.posture.backup_db missing');
assert(backupDr.posture?.encryption, 'backup_dr.posture.encryption missing');
assert(backupDr.posture?.offsite, 'backup_dr.posture.offsite missing');
assert(backupDr.matrix?.archive_integrity, 'backup_dr.matrix.archive_integrity missing');
assert(backupDr.matrix?.isolated_schema, 'backup_dr.matrix.isolated_schema missing');
assert(backupDr.matrix?.isolated_mail, 'backup_dr.matrix.isolated_mail missing');
assert(backupDr.matrix?.live_mariadb, 'backup_dr.matrix.live_mariadb missing');
assert(backupDr.matrix?.pitr, 'backup_dr.matrix.pitr missing');
assert(backupDr.statuses?.verified, 'backup_dr.statuses.verified missing');
assert(backupDr.gap_spec?.endpoint, 'backup_dr.gap_spec.endpoint missing');
assert(backupDr.runbook?.title, 'backup_dr.runbook.title missing');
console.log('✓ Admin.backup_dr dictionary keys verified');

// 2. Component File Structure Verification
assert(fs.existsSync('src/components/admin/smtp/SmtpIncidentsTable.tsx'), 'SmtpIncidentsTable.tsx must exist');
assert(fs.existsSync('src/app/(admin)/admin/backup-dr/page.tsx'), 'backup-dr/page.tsx must exist');
assert(fs.existsSync('src/types/index.ts'), 'types/index.ts must exist');

const typesContent = fs.readFileSync('src/types/index.ts', 'utf8');
assert(typesContent.includes('export interface AbuseIncident'), 'types/index.ts must export AbuseIncident');
assert(typesContent.includes('export type IncidentSeverity'), 'types/index.ts must export IncidentSeverity');
assert(typesContent.includes('export type IncidentStatus'), 'types/index.ts must export IncidentStatus');
console.log('✓ TypeScript type interfaces verified');

const incidentsComponentContent = fs.readFileSync('src/components/admin/smtp/SmtpIncidentsTable.tsx', 'utf8');
assert(incidentsComponentContent.includes('/api/admin/smtp/incidents'), 'SmtpIncidentsTable must consume /api/admin/smtp/incidents');
assert(incidentsComponentContent.includes('/resolve'), 'SmtpIncidentsTable must submit to /resolve');
assert(incidentsComponentContent.includes('isSubmitting'), 'SmtpIncidentsTable must protect against duplicate submission');
assert(incidentsComponentContent.includes('incident-detail-title'), 'SmtpIncidentsTable must have detail modal');
assert(incidentsComponentContent.includes('aria-modal'), 'SmtpIncidentsTable modal must be accessible');
console.log('✓ SmtpIncidentsTable component contract verified');

const backupDrContent = fs.readFileSync('src/app/(admin)/admin/backup-dr/page.tsx', 'utf8');
assert(backupDr.gap_banner?.badge === 'REQUIRES ARCHITECTURE APPROVAL', 'backupDr.gap_banner.badge must equal REQUIRES ARCHITECTURE APPROVAL');
assert(backupDrContent.includes("t('gap_banner.badge')"), 'backup-dr must render gap_banner.badge');
assert(backupDrContent.includes("t('gap_spec.endpoint')"), 'backup-dr must specify target endpoint');
assert(backupDrContent.includes('AES-256-CBC'), 'backup-dr must reflect AES-256-CBC cipher');
assert(backupDrContent.includes('PARTIALLY VERIFIED'), 'backup-dr must distinguish partial verification');
assert(backupDrContent.includes('NOT IMPLEMENTED'), 'backup-dr must distinguish PITR Option B');
console.log('✓ Backup & DR health page contract verified');

console.log('\n=== All Step 18 Frontend Tests Passed (100% Verified) ===');
