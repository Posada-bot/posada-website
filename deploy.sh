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
SFTP_USER=c10nbn7qy_ssh
PORT=22
DOCROOT=webroots/df8d18c6/
PWFILE="$HOME/onecom_pw.txt"
HERE="$(cd "$(dirname "$0")" && pwd)"

[ -f "$PWFILE" ] || { echo "missing $PWFILE — put the one.com SSH&SFTP password there"; exit 2; }
PW=$(cat "$PWFILE")
# A non-empty password is required: the redaction below matches on it, and an
# empty needle would make the safety filters meaningless.
[ -n "$PW" ] || { echo "FATAL: $PWFILE is empty — put the one.com SSH&SFTP password there" >&2; exit 2; }

# Strip the password out of anything we print. Literal (index/substr), so no
# regex metacharacter in the password can defeat it. The value is passed via the
# environment rather than argv, so it never appears in `ps`.
redact() {
  PW_REDACT="$PW" awk '
    BEGIN { p = ENVIRON["PW_REDACT"] }
    {
      while (p != "" && (i = index($0, p)) > 0)
        $0 = substr($0, 1, i - 1) "<redacted>" substr($0, i + length(p))
      print
    }'
}

# one.com publishes this host on BOTH A and AAAA, but either family can be dead
# while the other answers — and DNS flaps for several minutes after the
# "Allow access SSH & SFTP" toggle is enabled. Resolve both, then pin the first
# address that actually ANSWERS on the SFTP port. Picking blind (the old
# getent-first-line behaviour) could hand lftp a dead endpoint and surface as a
# confusing auth-looking failure.
IPV4=$(dig +short A    "$HOST" | grep -E '^[0-9]+(\.[0-9]+){3}$'   | head -1 || true)
IPV6=$(dig +short AAAA "$HOST" | grep -E '^[0-9a-fA-F:]+$'         | head -1 || true)

IP=""
for cand in "$IPV4" "$IPV6"; do
  [ -n "$cand" ] || continue
  if nc -z -w5 "$cand" "$PORT" >/dev/null 2>&1; then
    IP="$cand"
    break
  fi
  echo "  note: $cand did not answer on port $PORT, trying next"
done

if [ -z "$IP" ]; then
  {
    echo "FATAL: no reachable endpoint for $HOST on port $PORT."
    echo "  IPv4 tried: ${IPV4:-<none resolved>}"
    echo "  IPv6 tried: ${IPV6:-<none resolved>}"
    echo "  -> Is the one.com 'Allow access SSH & SFTP' toggle ON?"
    echo "  -> After enabling it the host stays NXDOMAIN for up to ~6 min before DNS publishes."
    echo "  -> NOTE: toggling it resets the SSH password — refresh $PWFILE afterwards."
  } >&2
  exit 3
fi

# POS-211: prove $HERE is actually the posada.io site tree before mirroring it
# onto the live docroot — never push an unverified/wrong directory to production.
# index.html + lovelace/ are markers only the real site tree has.
[ -f "$HERE/index.html" ] && [ -d "$HERE/lovelace" ] \
  || { echo "FATAL: $HERE does not look like the posada.io site (missing index.html/lovelace/), refusing to deploy" >&2; exit 1; }

echo "deploying to one.com via [$IP] ..."

# Capture lftp's status instead of letting `set -e` kill the script at the
# assignment — that is what previously turned every failure into a bare exit 1
# with no diagnostics at all.
set +e
OUT=$(lftp "sftp://[$IP]" <<LFTP 2>&1
set sftp:auto-confirm yes
set net:timeout 30
set net:max-retries 2
user $SFTP_USER $PW
mirror -R --only-newer --no-perms \
  --exclude-glob .git/ \
  --exclude deploy.sh --exclude README.md --exclude .gitignore \
  "$HERE/" "$DOCROOT"
bye
LFTP
)
RC=$?
set -e

# Redact once, then PROVE the password is gone before anything is printed.
# This is the single output path — success and failure both go through it, so
# the error branch cannot leak what the success branch hides.
SAFE=$(printf '%s\n' "$OUT" | redact)
if printf '%s' "$SAFE" | grep -qaF "$PW"; then
  echo "INTERNAL: redaction failed — suppressing lftp output rather than risk printing the password." >&2
  SAFE="<lftp output suppressed: redaction self-check failed>"
fi
printf '%s\n' "$SAFE"

if printf '%s' "$OUT" | grep -qiE 'login incorrect|authentication failed|permission denied'; then
  {
    echo ""
    echo "!!! AUTH FAILED (lftp rc=$RC) — the one.com toggle resets the password."
    echo "    Refresh $PWFILE with the current value and retry."
  } >&2
  exit 9
fi

if [ "$RC" -ne 0 ]; then
  {
    echo ""
    echo "FATAL: lftp exited $RC — the transfer did not complete."
    echo "    The (password-redacted) lftp output above is the diagnostic."
  } >&2
  exit "$RC"
fi

echo "deploy complete."
