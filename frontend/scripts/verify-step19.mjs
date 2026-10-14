import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert';

console.log('=== Step 19 Frontend Verification Suite ===\n');

// 1. Verify en.json i18n dictionary completeness
const enJsonPath = path.resolve('messages/en.json');
assert(fs.existsSync(enJsonPath), 'messages/en.json must exist');
const en = JSON.parse(fs.readFileSync(enJsonPath, 'utf8'));

console.log('✓ Loaded messages/en.json successfully');

// Assert Admin.sidebar keys
assert(en.Admin?.sidebar?.system_health, 'Admin.sidebar.system_health translation key missing');
assert(en.Admin?.sidebar?.backup_dr, 'Admin.sidebar.backup_dr translation key missing');
console.log('✓ Admin.sidebar navigation keys verified');

// Assert Admin.system_health keys
const health = en.Admin?.system_health;
assert(health, 'Admin.system_health translation block missing');
assert(health.title, 'system_health.title missing');
assert(health.subtitle, 'system_health.subtitle missing');
assert(health.refresh, 'system_health.refresh missing');
assert(health.overall_status?.healthy, 'system_health.overall_status.healthy missing');
assert(health.overall_status?.degraded, 'system_health.overall_status.degraded missing');
assert(health.overall_status?.unhealthy, 'system_health.overall_status.unhealthy missing');
assert(health.posture?.HEALTHY, 'system_health.posture.HEALTHY missing');
assert(health.posture?.DEGRADED, 'system_health.posture.DEGRADED missing');
assert(health.posture?.UNHEALTHY, 'system_health.posture.UNHEALTHY missing');
assert(health.posture?.UNKNOWN, 'system_health.posture.UNKNOWN missing');
assert(health.posture?.NOT_CONFIGURED, 'system_health.posture.NOT_CONFIGURED missing');

// Assert 7 Component keys
assert(health.components?.app, 'components.app missing');
assert(health.components?.database, 'components.database missing');
assert(health.components?.redis, 'components.redis missing');
assert(health.components?.scheduler, 'components.scheduler missing');
assert(health.components?.task_failures, 'components.task_failures missing');
assert(health.components?.queue, 'components.queue missing');
assert(health.components?.backup, 'components.backup missing');

// Assert tasks execution table keys
assert(health.tasks?.title, 'tasks.title missing');
assert(health.tasks?.table?.task_name, 'tasks.table.task_name missing');
assert(health.tasks?.status?.running, 'tasks.status.running missing');
assert(health.tasks?.status?.success, 'tasks.status.success missing');
assert(health.tasks?.status?.failed, 'tasks.status.failed missing');
assert(health.tasks?.status?.skipped, 'tasks.status.skipped missing');
assert(health.tasks?.modal_title, 'tasks.modal_title missing');

// Assert errors keys
assert(health.errors?.forbidden_title, 'errors.forbidden_title missing');
assert(health.errors?.failed_title, 'errors.failed_title missing');
console.log('✓ Admin.system_health dictionary keys verified');

// 2. Component and Types Verification
assert(fs.existsSync('src/app/(admin)/admin/system-health/page.tsx'), 'system-health/page.tsx must exist');
assert(fs.existsSync('src/types/index.ts'), 'types/index.ts must exist');

const typesContent = fs.readFileSync('src/types/index.ts', 'utf8');
assert(typesContent.includes('export type SystemHealthStatus'), 'types/index.ts must export SystemHealthStatus');
assert(typesContent.includes('export interface ComponentHealth'), 'types/index.ts must export ComponentHealth');
assert(typesContent.includes('export interface ScheduledTaskRunItem'), 'types/index.ts must export ScheduledTaskRunItem');
assert(typesContent.includes('export interface SystemHealthResponse'), 'types/index.ts must export SystemHealthResponse');
console.log('✓ TypeScript type interfaces verified');

const sidebarContent = fs.readFileSync('src/components/admin/AdminSidebar.tsx', 'utf8');
assert(sidebarContent.includes('/admin/system-health'), 'AdminSidebar must include /admin/system-health');
assert(sidebarContent.includes('Activity'), 'AdminSidebar must include Activity icon');
console.log('✓ AdminSidebar navigation contract verified');

const healthPageContent = fs.readFileSync('src/app/(admin)/admin/system-health/page.tsx', 'utf8');
assert(healthPageContent.includes('/api/admin/system/health'), 'Health page must consume /api/admin/system/health');
assert(healthPageContent.includes('refreshInterval: 30000'), 'Health page must configure conservative 30s polling');
assert(healthPageContent.includes('status === 403'), 'Health page must handle 403 Forbidden state');
assert(!healthPageContent.includes('/api/admin/system/backup-status'), 'Health page must NOT invoke unapproved backup-status API');
assert(!healthPageContent.includes('shell_exec'), 'Health page must NEVER invoke shell execution directly');
console.log('✓ System health page security and API contracts verified');

console.log('\n=== All Step 19 Frontend Tests Passed (100% Verified) ===');
