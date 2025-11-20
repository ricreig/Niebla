#!/usr/bin/env bash
set -euo pipefail
ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
PHP_BIN=${PHP_BIN:-php}
cd "$ROOT_DIR/api"
TZ_ENV=${SIGMA_TZ:-America/Tijuana}
export TZ="$TZ_ENV"

exec "$PHP_BIN" update_schedule.php --days=2 "$@"
