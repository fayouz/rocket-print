#!/bin/sh
# Stands in for smbclient in the tests (SMBCLIENT_BINARY): logs each call to $LOG, with the authentication file,
# and answers according to the share: //print-server/{laser,offline,denied}.
LOG="$(dirname "$0")/../../var/test-data/smbclient.log"
mkdir -p "$(dirname "$LOG")"
{
  echo "ARGS: $*"
  prev=""
  for arg in "$@"; do
    if [ "$prev" = "-A" ]; then echo "AUTH:"; cat "$arg"; fi
    if [ "$prev" = "-c" ]; then
      for f in $(echo "$arg" | tr ';' '\n' | sed -n 's/^ *print //p'); do echo "PRINTED: $(cat "$f")"; done
    fi
    prev="$arg"
  done
} >> "$LOG"

case "$*" in
  *//print-server/offline*) echo "do_connect: Connection to print-server failed (Error NT_STATUS_HOST_UNREACHABLE)" >&2; exit 1 ;;
  *//print-server/denied*) echo "session setup failed: NT_STATUS_LOGON_FAILURE" >&2; exit 1 ;;
  "-L //print-server"*)
    echo "Disk|documents|Shared documents"
    echo "Printer|laser|HP LaserJet 2e étage"
    echo "IPC|IPC\$|IPC Service"
    exit 0 ;;
  "-L "*) echo "do_connect: Connection to unknown failed (Error NT_STATUS_UNSUCCESSFUL)" >&2; exit 1 ;;
esac
echo "putting file as stdin (1.2 kB/s)"
exit 0
