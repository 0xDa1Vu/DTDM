#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
umask 077
backup_dir="backups/$(date -u +%Y%m%dT%H%M%SZ)"
mkdir -p "$backup_dir"
bash scripts/demo.sh exec -T db sh -c 'MYSQL_PWD="$MYSQL_PASSWORD" exec mysqldump -u "$MYSQL_USER" --single-transaction --no-tablespaces --set-gtid-purged=OFF "$MYSQL_DATABASE"' > "$backup_dir/mysql.sql.partial"
test -s "$backup_dir/mysql.sql.partial"
mv "$backup_dir/mysql.sql.partial" "$backup_dir/mysql.sql"
tar -czf "$backup_dir/uploads.tar.gz" public/uploads
git rev-parse HEAD > "$backup_dir/source-commit.txt"
python3 - "$backup_dir" <<'PY'
import hashlib, pathlib, sys
root = pathlib.Path(sys.argv[1])
with (root / 'SHA256SUMS').open('w') as out:
    for path in sorted(root.iterdir()):
        if path.name != 'SHA256SUMS':
            out.write(f'{hashlib.sha256(path.read_bytes()).hexdigest()}  {path.name}\n')
PY
printf 'Backup local: %s\n' "$backup_dir"
