<?php
// =============================================================================
// Roundcube config.inc.php
// =============================================================================

$config = [];

// ─── Database ─────────────────────────────────────────────────────────────────
$config['db_dsnw'] = 'mysql://emailsaas:__DB_PASS__@127.0.0.1/roundcubemail';

// ─── IMAP (Dovecot) ───────────────────────────────────────────────────────────
$config['default_host']    = 'ssl://127.0.0.1';
$config['default_port']    = 993;
$config['imap_cache']      = 'db';
$config['imap_timeout']    = 15;
$config['imap_auth_type']  = 'LOGIN';

// ─── SMTP (Postfix) ───────────────────────────────────────────────────────────
$config['smtp_server']     = 'tls://127.0.0.1';
$config['smtp_port']       = 587;
$config['smtp_user']       = '%u';      // Use IMAP username
$config['smtp_pass']       = '%p';      // Use IMAP password
$config['smtp_auth_type']  = 'LOGIN';

// ─── Security ─────────────────────────────────────────────────────────────────
$config['des_key']         = 'your-24-char-secret-key-here!!';
$config['ip_check']        = true;
$config['referer_check']   = true;
$config['session_lifetime'] = 30;   // minutes

// ─── UI Branding ──────────────────────────────────────────────────────────────
$config['product_name']    = 'EmailSaaS Webmail';
$config['support_url']     = 'mailto:support@yourdomain.com';
$config['skin']            = 'elastic';     // Modern responsive skin

// ─── Features ─────────────────────────────────────────────────────────────────
$config['plugins'] = [
    'archive',
    'zipdownload',
    'password',         // Allow users to change password
    'managesieve',      // Email filters
    'vcard_attachments',
    'emoticons',
    'enigma',           // PGP encryption (optional)
];

// ─── Login / Username Format ──────────────────────────────────────────────────
$config['login_lc']       = 2;       // Lowercase username
$config['username_domain'] = '';     // Allow full email login
$config['auto_create_user'] = false;

// ─── Performance ──────────────────────────────────────────────────────────────
$config['enable_caching']   = true;
$config['messages_cache_ttl'] = 43200;  // 12 hours

// ─── Message Settings ─────────────────────────────────────────────────────────
$config['max_message_size']  = '25M';
$config['mime_param_folding'] = 0;
$config['send_format_warning'] = false;

// ─── Addressbook ──────────────────────────────────────────────────────────────
$config['autocomplete_min_length'] = 2;
$config['address_book_type']       = 'sql';

// ─── Folder Defaults ──────────────────────────────────────────────────────────
$config['drafts_mbox'] = 'Drafts';
$config['sent_mbox']   = 'Sent';
$config['trash_mbox']  = 'Trash';
$config['junk_mbox']   = 'Junk';
