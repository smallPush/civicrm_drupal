#!/usr/bin/env python3
"""Create private pre-upgrade database/config/upload backups; never print secrets."""
import datetime
import gzip
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tarfile


def main():
    os.umask(0o077)
    parent = Path('/root/backups')
    if not parent.is_dir():
        raise SystemExit('Backup parent must already exist')
    if shutil.disk_usage(parent).free < 10 * 1024**3:
        raise SystemExit('At least 10 GiB free required')
    stamp = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')
    dest = parent / ('updates-' + stamp)
    dest.mkdir(mode=0o700)
    containers = [json.loads(line) for line in subprocess.check_output([
        'docker', 'ps', '--format', '{{json .}}'
    ]).decode().splitlines()]
    for container in containers:
        image = container['Image']
        if image.startswith(('postgres:', 'mysql:', 'mariadb:')):
            if image.startswith('postgres:'):
                command = 'exec pg_dumpall -U "${POSTGRES_USER:-postgres}"'
            else:
                command = ('export MYSQL_PWD="${MYSQL_ROOT_PASSWORD:-${MARIADB_ROOT_PASSWORD:-}}"; '
                           'test -n "$MYSQL_PWD"; '
                           'dump=mysqldump; command -v "$dump" >/dev/null || dump=mariadb-dump; '
                           'exec "$dump" --all-databases --single-transaction --routines --events --triggers -uroot')
            target = dest / (container['ID'] + '.sql.gz')
            with subprocess.Popen(['docker', 'exec', container['ID'], 'sh', '-ceu', command],
                                  stdout=subprocess.PIPE) as proc:
                with gzip.open(target, 'wb') as out:
                    shutil.copyfileobj(proc.stdout, out)
                if proc.wait() != 0:
                    raise SystemExit('Database backup failed: ' + container['ID'])
            with gzip.open(target, 'rb') as check:
                while check.read(1024 * 1024):
                    pass
            print('Database verified:', container['ID'])
    paths = ['/etc/docker', '/etc/dokploy/security', '/etc/dokploy/traefik',
             '/etc/systemd/system/dokploy-admin-firewall.service',
             '/usr/local/lib/dokploy-admin-firewall.py', '/etc/ufw',
             '/var/lib/docker/volumes/donesemprenedores-drupal-data/_data']
    with tarfile.open(dest / 'config-uploads.tar.gz', 'w:gz') as archive:
        for path in paths:
            if Path(path).exists():
                archive.add(path, arcname=path.lstrip('/'))
    with tarfile.open(dest / 'config-uploads.tar.gz', 'r:gz') as archive:
        for member in archive:
            if member.isfile():
                with archive.extractfile(member) as stream:
                    while stream.read(1024 * 1024):
                        pass
    services = subprocess.check_output(['docker', 'service', 'ls', '-q']).decode().split()
    # Full definitions contain credentials and stay outside Git in this 0700 directory.
    (dest / 'services.private.json').write_bytes(subprocess.check_output(['docker', 'service', 'inspect', *services]))
    for tool in ['iptables-save', 'ip6tables-save']:
        (dest / (tool + '.txt')).write_bytes(subprocess.check_output(['/usr/sbin/' + tool]))
    hashes = {}
    for path in dest.iterdir():
        if path.is_file():
            with path.open('rb') as stream:
                hashes[path.name] = hashlib.file_digest(stream, 'sha256').hexdigest()
    (dest / 'SHA256SUMS.json').write_text(json.dumps(hashes, indent=2) + '\n')
    print('Verified backup directory:', dest)


if __name__ == '__main__':
    main()
