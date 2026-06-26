#!/usr/bin/env bash
# ============================================================================
# deploy.sh — publish the posada.io static site to one.com hosting.
#
# Reads the SFTP password from ~/onecom_pw.txt (NEVER commit the password).
# Mirrors this repo UP to the one.com docroot. Uses --only-newer and does NOT
# use --delete, so server-only content (releases/, license/, data/, apk/) is
# preserved and never wiped.
#
# Usage:  ./deploy.sh
# ============================================================================
set -euo pipefail

HOST=ssh.c10nbn7qy.service.one
USER=c10nbn7qy_ssh
DOCROOT=webroots/df8d18c6/
PWFILE="$HOME/onecom_pw.txt"
HERE="$(cd "$(dirname "$0")" && pwd)"

[ -f "$PWFILE" ] || { echo "missing $PWFILE — put the one.com SSH&SFTP password there"; exit 2; }
PW=$(cat "$PWFILE")

# one.com publishes this host IPv6-only, and DNS flaps for several minutes after
# the "Allow access SSH & SFTP" toggle is enabled. Pin the resolved address so
# the lftp session does not depend on the flapping resolver.
IP=$(getent ahosts "$HOST" | awk '{print $1; exit}')
[ -n "$IP" ] || {
  echo "cannot resolve $HOST."
  echo "  -> Is the one.com 'Allow access SSH & SFTP' toggle ON?"
  echo "  -> After enabling it the host stays NXDOMAIN for up to ~6 min before DNS publishes."
  echo "  -> NOTE: toggling it resets the SSH password — refresh ~/onecom_pw.txt afterwards."
  exit 3
}
# POS-211: prove $HERE is actually the posada.io site tree before mirroring it
# onto the live docroot — never push an unverified/wrong directory to production.
# index.html + lovelace/ are markers only the real site tree has.
[ -f "$HERE/index.html" ] && [ -d "$HERE/lovelace" ] \
  || { echo "FATAL: $HERE does not look like the posada.io site (missing index.html/lovelace/), refusing to deploy" >&2; exit 1; }

echo "deploying to one.com via [$IP] ..."

OUT=$(lftp "sftp://[$IP]" <<LFTP 2>&1
set sftp:auto-confirm yes
set net:timeout 30
set net:max-retries 2
user $USER $PW
mirror -R --only-newer --no-perms \
  --exclude-glob .git/ \
  --exclude deploy.sh --exclude README.md --exclude .gitignore \
  "$HERE/" "$DOCROOT"
bye
LFTP
)
echo "$OUT" | grep -vaF "$PW"

if echo "$OUT" | grep -qiE 'login incorrect|authentication failed|permission denied'; then
  echo "!!! AUTH FAILED — the one.com toggle resets the password; refresh ~/onecom_pw.txt and retry."
  exit 9
fi
echo "deploy complete."
