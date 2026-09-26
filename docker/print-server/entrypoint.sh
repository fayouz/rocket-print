#!/bin/sh
set -e
# Demo account of the printer share (password from DEMO_SAMBA_PASSWORD).
id demo >/dev/null 2>&1 || useradd --no-create-home --shell /usr/sbin/nologin demo
printf '%s\n%s\n' "${DEMO_SAMBA_PASSWORD:-demo-print-password}" "${DEMO_SAMBA_PASSWORD:-demo-print-password}" | smbpasswd -s -a demo >/dev/null
mkdir -p /var/spool/samba /printed /run/samba
chmod 1777 /var/spool/samba
chown demo /printed
exec smbd --foreground --no-process-group --debug-stdout
