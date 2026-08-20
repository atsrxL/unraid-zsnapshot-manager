#!/bin/bash
set -Eeuo pipefail

ZFS_BIN="${ZFS_BIN:-/sbin/zfs}"
[[ -x "$ZFS_BIN" ]] || ZFS_BIN="$(command -v zfs || true)"

HOLD_TAG="zsm-protect"
MANAGED_PROP="io.github.atsrxl:managed"
SOURCE_PROP="io.github.atsrxl:source"
POLICY_PROP="io.github.atsrxl:policy"

fail() {
  echo "ERROR: $*" >&2
  exit 1
}

require_zfs() {
  [[ -n "$ZFS_BIN" && -x "$ZFS_BIN" ]] || fail "zfs command not found"
}

valid_dataset() {
  local value="${1:-}"
  [[ -n "$value" && "$value" != *"@"* && "$value" =~ ^[A-Za-z0-9_.:-]+(/[A-Za-z0-9_.:-]+)*$ ]]
}

valid_snapshot_leaf() {
  [[ "${1:-}" =~ ^[A-Za-z0-9_.:-]+$ ]]
}

dataset_exists() {
  "$ZFS_BIN" list -H -o name "$1" >/dev/null 2>&1
}

snapshot_exists() {
  "$ZFS_BIN" list -H -t snapshot -o name "$1" >/dev/null 2>&1
}

next_snapshot_name() {
  local dataset="$1" prefix="${2:-manual}" base candidate index=0
  base="${prefix}-$(date +%Y%m%d-%H%M%S)"
  candidate="$base"
  while snapshot_exists "${dataset}@${candidate}"; do
    index=$((index + 1))
    candidate="${base}-${index}"
  done
  printf '%s\n' "$candidate"
}

cmd_create_snapshot() {
  require_zfs
  local dataset="${1:-}" snap="${2:-}" recursive="${3:-0}" source="${4:-manual}" policy="${5:-}"
  valid_dataset "$dataset" || fail "invalid dataset: $dataset"
  dataset_exists "$dataset" || fail "dataset does not exist: $dataset"
  [[ "$source" == "manual" || "$source" == "schedule" ]] || fail "invalid snapshot source"
  [[ "$recursive" == "0" || "$recursive" == "1" ]] || fail "invalid recursive flag"

  if [[ -z "$snap" ]]; then
    snap="$(next_snapshot_name "$dataset" manual)"
  fi
  valid_snapshot_leaf "$snap" || fail "invalid snapshot name"
  snapshot_exists "${dataset}@${snap}" && fail "snapshot already exists: ${dataset}@${snap}"

  local -a args=(snapshot)
  [[ "$recursive" == "1" ]] && args+=(-r)
  args+=(-o "${MANAGED_PROP}=1" -o "${SOURCE_PROP}=${source}")
  [[ -n "$policy" ]] && args+=(-o "${POLICY_PROP}=${policy}")
  args+=("${dataset}@${snap}")

  "$ZFS_BIN" "${args[@]}"
  echo "Created ${dataset}@${snap}"
}

cmd_delete_snapshot() {
  require_zfs
  local snapshot="${1:-}"
  [[ "$snapshot" == *@* ]] || fail "invalid snapshot"
  snapshot_exists "$snapshot" || fail "snapshot does not exist: $snapshot"
  "$ZFS_BIN" destroy "$snapshot"
  echo "Deleted $snapshot"
}

cmd_hold() {
  require_zfs
  local snapshot="${1:-}"
  snapshot_exists "$snapshot" || fail "snapshot does not exist: $snapshot"
  "$ZFS_BIN" hold "$HOLD_TAG" "$snapshot"
  echo "Protected $snapshot"
}

cmd_release() {
  require_zfs
  local snapshot="${1:-}"
  snapshot_exists "$snapshot" || fail "snapshot does not exist: $snapshot"
  "$ZFS_BIN" release "$HOLD_TAG" "$snapshot"
  echo "Unprotected $snapshot"
}

cmd_clone_snapshot() {
  require_zfs
  local snapshot="${1:-}" target="${2:-}"
  snapshot_exists "$snapshot" || fail "snapshot does not exist: $snapshot"
  valid_dataset "$target" || fail "invalid clone dataset: $target"
  if "$ZFS_BIN" list -H -o name "$target" >/dev/null 2>&1; then
    fail "clone target already exists: $target"
  fi
  "$ZFS_BIN" clone "$snapshot" "$target"
  echo "Cloned $snapshot -> $target"
}

cmd_rollback() {
  require_zfs
  local snapshot="${1:-}" dataset newest
  snapshot_exists "$snapshot" || fail "snapshot does not exist: $snapshot"
  dataset="${snapshot%@*}"
  newest="$("$ZFS_BIN" list -H -t snapshot -o name -S creation -d 1 "$dataset" 2>/dev/null | head -n 1 || true)"
  [[ "$newest" == "$snapshot" ]] || fail "safety guard: rollback is only allowed to the newest snapshot"
  "$ZFS_BIN" rollback "$snapshot"
  echo "Rolled back $snapshot"
}

case "${1:-}" in
  create-snapshot) shift; cmd_create_snapshot "$@" ;;
  delete-snapshot) shift; cmd_delete_snapshot "$@" ;;
  hold) shift; cmd_hold "$@" ;;
  release) shift; cmd_release "$@" ;;
  clone-snapshot) shift; cmd_clone_snapshot "$@" ;;
  rollback) shift; cmd_rollback "$@" ;;
  *) fail "unknown command: ${1:-}" ;;
esac
