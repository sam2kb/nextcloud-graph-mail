#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 sam2kb
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Build a reproducible, unsigned Nextcloud application archive."""
import argparse
import gzip
import hashlib
import io
from pathlib import Path
import tarfile
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parent.parent
FILES = ('README.md', 'LICENSE', 'docs/operator-recovery.md', 'appinfo/info.xml', 'appinfo/routes.php', 'bin/sendmail', 'bin/.htaccess')
TREES = {'lib': {'.php'}, 'templates': {'.php'}, 'img': {'.svg', '.png'}, 'l10n': {'.js', '.json'}, 'js': {'.js'}, 'css': {'.css'}}

def build(destination: Path) -> Path:
    version = ET.parse(ROOT / 'appinfo/info.xml').findtext('version')
    paths = [ROOT / name for name in FILES]
    for directory, suffixes in TREES.items():
        paths.extend(p for p in (ROOT / directory).rglob('*') if p.is_file() and p.suffix in suffixes)
    destination.mkdir(parents=True, exist_ok=True)
    output = destination / f'graph_mail-{version}.tar.gz'
    archive = io.BytesIO()
    with tarfile.open(fileobj=archive, mode='w', format=tarfile.USTAR_FORMAT) as tar:
        for source in sorted(set(paths)):
            if source.is_symlink() or not source.is_file():
                raise ValueError(f'Invalid package input: {source.relative_to(ROOT)}')
            relative = source.relative_to(ROOT).as_posix()
            data = source.read_bytes()
            entry = tarfile.TarInfo('graph_mail/' + relative)
            entry.mode = 0o755 if relative == 'bin/sendmail' else 0o644
            entry.size = len(data)
            entry.mtime = 0
            tar.addfile(entry, io.BytesIO(data))
    with output.open('wb') as handle:
        with gzip.GzipFile(fileobj=handle, mode='wb', filename='', mtime=0) as zipped:
            zipped.write(archive.getvalue())
    digest = hashlib.sha256(output.read_bytes()).hexdigest()
    output.with_suffix(output.suffix + '.sha256').write_text(f'{digest}  {output.name}\n', encoding='ascii')
    return output

if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, default=ROOT / 'dist')
    args = parser.parse_args()
    print(build(args.output.resolve()))
