# Demo print server (Samba): see smb.conf. Documents "printed" on it land in /printed.
FROM debian:bookworm-slim
RUN apt-get update \
    && apt-get install -y --no-install-recommends samba \
    && rm -rf /var/lib/apt/lists/*
COPY smb.conf printcap /etc/samba/
COPY --chmod=755 entrypoint.sh /usr/local/bin/entrypoint
EXPOSE 445
HEALTHCHECK --interval=10s --timeout=5s CMD smbclient -L //localhost -N -g >/dev/null 2>&1 || smbclient -L //localhost -U demo%"${DEMO_SAMBA_PASSWORD:-demo-print-password}" -g >/dev/null
ENTRYPOINT ["entrypoint"]
