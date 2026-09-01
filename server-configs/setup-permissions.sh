#!/bin/bash
# Setup vmail user, group, and permissions for Postfix & Dovecot virtual mailboxes

# 1. Create a system group for virtual mail with GID 5000
groupadd -g 5000 vmail

# 2. Create a system user for virtual mail with UID 5000, no shell, no home dir creation
useradd -g vmail -u 5000 vmail -d /var/vmail -s /usr/sbin/nologin

# 3. Create the virtual mail base directory
mkdir -p /var/vmail

# 4. Set ownership of the directory exclusively to the vmail user/group
chown -R vmail:vmail /var/vmail

# 5. Restrict permissions (Only vmail user can read/write)
chmod -R 770 /var/vmail

echo "Virtual mail directory /var/vmail created and secured for vmail (UID 5000)."
