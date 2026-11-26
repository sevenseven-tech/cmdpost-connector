#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
output="${1:-/tmp/cmdpost-connector.zip}"
python3 - "$output" <<'PY'
import pathlib
import sys
import zipfile

root = pathlib.Path.cwd()
output = pathlib.Path(sys.argv[1]).resolve()
files = sorted(root.glob('*.php')) + [root / 'readme.txt', root / 'LICENSE']
files += sorted(path for path in (root / 'assets').rglob('*') if path.is_file())
with zipfile.ZipFile(output, 'w', zipfile.ZIP_DEFLATED) as archive:
    for path in files:
        archive.write(path, 'cmdpost-connector/' + path.relative_to(root).as_posix())
print(output)
PY
