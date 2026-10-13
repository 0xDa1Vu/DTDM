#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
# Fixed project identity: never use the old LTW stack or its volumes.
exec docker compose -p dtdm_demo -f docker-compose.demo.yml "$@"
