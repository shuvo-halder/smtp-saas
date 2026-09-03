# AI IMPLEMENTATION HANDOFF

This document is the operational starting point for any AI coding agent working on this repository.

> **Any AI agent working on this repository must treat the existing architecture as locked unless explicit approval is provided to change it. The agent must inspect the current implementation before making changes, must not assume undocumented functionality exists, and must update the relevant implementation documentation immediately after completing and verifying its work. Documentation must describe the actual current state of the repository, not the intended or assumed state.**

Before modifying code, the agent MUST read:

1. `README.md`
2. `docs/ARCHITECTURE.md`
3. `docs/IMPLEMENTATION.md`
4. `docs/IMPLEMENTATION-STATUS.md`
5. `docs/MODULES.md`
6. `docs/DECISIONS.md`
7. `docs/DATABASE.md`
8. `docs/SECURITY.md`

## Current Project State
The project is a fully functional, production-ready Multi-Tenant Managed Business Email & SMTP SaaS Platform. The Next.js frontend, Laravel API backend, MariaDB database, Postfix MTA, Dovecot IMAP, and SSLCommerz billing integrations are completely implemented and audited.

## Current Active Work
There is no active work pending execution. The architecture has just undergone a strict audit resolving DB transactional safety and shell script sync issues.

## Pending Work
- Establish an automated offsite backup script for MariaDB dumps and S3 syncing of the `/var/vmail/` directory.

## Protected Architecture
- **Multi-Tenancy:** Handled via Wildcard subdomain (`IdentifyTenant` middleware).
- **Mail Server Sync:** Postfix and Dovecot read strictly from MariaDB `domains` and `mailboxes` tables. Bash scripts are ONLY for file/folder creation (`/var/vmail`).
- **Billing:** Pre-paid manual renewals via SSLCommerz.

## Important Contracts
- Any changes to `domains` or `mailboxes` table schema **MUST** be reflected in `/etc/postfix/mysql-*.cf` and `/etc/dovecot/dovecot-sql.conf.ext`.
- All database modifications impacting the mail server MUST be wrapped in `DB::transaction`.
- **Zero hardcoded English strings** are allowed in the Next.js frontend (`messages/en.json` must be used).

## Last Implementation
- Executed P0 architectural audit. Stripped `mysql` instructions from bash scripts, wrapped provisioning endpoints in DB transactions, extracted i18n strings, generated documentation system. (See `CHANGELOG.md` for 2026-09-04).

## Next Recommended Work
- Implement the offsite backup strategies.
- Configure server monitoring (e.g., Prometheus / Grafana).
