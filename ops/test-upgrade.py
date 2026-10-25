#!/usr/bin/env python3
"""Test the candidate against an isolated clone; no production writes or mail."""
import json
import os
from pathlib import Path
import secrets
import shutil
import subprocess
import time

PREFIX = 'security-upgrade-20261025'
IMAGE = 'donesemprenedores-donesemprenedores-pacj57:security-20261025'
SERVICE = 'donesemprenedores-donesemprenedores-pacj57'


def run(*args, **kwargs):
    return subprocess.run(args, check=True, **kwargs)


def main():
    os.umask(0o077)
    production = subprocess.check_output(['docker', 'ps', '-q', '--filter',
        'label=com.docker.swarm.service.name=' + SERVICE], text=True).strip()
    spec = json.loads(subprocess.check_output(['docker', 'inspect', production]))[0]
    env = dict(item.split('=', 1) for item in spec['Config']['Env'])
    if env.get('CIVICRM_DB_NAME', env['DB_NAME']) != env['DB_NAME']:
        raise SystemExit('Separate CiviCRM database needs a separate test clone')
    password = secrets.token_hex(24)
    env.update(DB_HOST=PREFIX + '-db', DB_PORT='3306', DB_USER='root', DB_PASSWORD=password,
               CIVICRM_DB_HOST=PREFIX + '-db', CIVICRM_DB_PORT='3306',
               CIVICRM_DB_USER='root', CIVICRM_DB_PASSWORD=password,
               CIVICRM_DB_NAME=env['DB_NAME'], CIVICRM_UF_BASEURL='http://localhost:18080/',
               MYSQL_ROOT_PASSWORD=password, MYSQL_DATABASE=env['DB_NAME'])
    env.setdefault('CIVICRM_CRED_KEYS', 'aes-cbc:hkdf-sha256:' + secrets.token_hex(32))
    env.setdefault('CIVICRM_SIGN_KEYS', 'jwt-hs256:hkdf-sha256:' + secrets.token_hex(32))
    inherited = dict(os.environ, **env)
    files = Path('/tmp/opencode') / (PREFIX + '-files')
    if files.exists():
        raise SystemExit('Test files already exist; inspect previous test first')
    source = next(m['Source'] for m in spec['Mounts'] if m['Destination'].endswith('/files'))
    shutil.copytree(source, files)
    for root, dirs, names in os.walk(files):
        os.chown(root, 33, 33)
        for name in names:
            os.chown(Path(root) / name, 33, 33)
    created = []
    network_created = False
    try:
        run('docker', 'network', 'create', '--internal', PREFIX)
        network_created = True
        run('docker', 'run', '-d', '--name', PREFIX + '-db', '--network', PREFIX,
            '--memory=512m', '--env', 'MYSQL_ROOT_PASSWORD', '--env', 'MYSQL_DATABASE',
            'mysql:8', env=inherited)
        created.append(PREFIX + '-db')
        for _ in range(90):
            ready = subprocess.run(['docker', 'exec', '-e', 'MYSQL_PWD=' + password,
                PREFIX + '-db', 'mysql', '-uroot', '-e', 'SELECT 1'], capture_output=True)
            if ready.returncode == 0:
                break
            time.sleep(2)
        else:
            raise RuntimeError('Test database did not become ready')
        with subprocess.Popen(['docker', 'exec', production, 'vendor/bin/drush', 'sql:dump',
            '--extra-dump=--single-transaction --no-tablespaces --skip-ssl'], stdout=subprocess.PIPE) as dump:
            run('docker', 'exec', '-i', '-e', 'MYSQL_PWD=' + password, PREFIX + '-db',
                'mysql', '-uroot', env['DB_NAME'], stdin=dump.stdout)
            if dump.wait() != 0:
                raise RuntimeError('Test database export failed')
        args = ['docker', 'run', '-d', '--name', PREFIX + '-web', '--network', PREFIX,
                '--memory=768m', '--memory-swap=1536m',
                '-v', str(files) + ':/var/www/html/web/sites/default/files']
        for key in env:
            args.extend(['--env', key])
        run(*args, IMAGE, env=inherited)
        created.append(PREFIX + '-web')
        drush = ['docker', 'exec', '--user', 'www-data', PREFIX + '-web', 'vendor/bin/drush',
                 '--uri=http://localhost:18080']
        run(*drush, 'updatedb', '--yes', '--quiet')
        run(*drush, 'php:script', '/var/www/html/ops/upgrade-civicrm.php')
        run(*drush, 'cache:rebuild')
        run(*drush, 'status', '--fields=drupal-version,db-status,bootstrap', '--format=json')
        run('docker', 'exec', PREFIX + '-web', 'curl', '-fsS', '--max-time', '30',
            '-o', '/dev/null', '-w', 'Isolated login HTTP %{http_code}\n',
            'http://127.0.0.1/user/login')
        run('docker', 'exec', PREFIX + '-web', 'composer', '--no-plugins', '--no-scripts',
            'check-platform-reqs', '--no-dev')
        print('Isolated database and CiviCRM migration test passed')
    except Exception:
        logs = files / 'civicrm/ConfigAndLog'
        if logs.is_dir():
            target = Path('/root/backups') / (PREFIX + '-failed-' + secrets.token_hex(4))
            target.mkdir(mode=0o700)
            shutil.copytree(logs, target / 'logs')
            print('Private test diagnostics:', target)
        raise
    finally:
        for name in reversed(created):
            subprocess.run(['docker', 'rm', '-f', '-v', name], check=False)
        if network_created:
            subprocess.run(['docker', 'network', 'rm', PREFIX], check=False)
        shutil.rmtree(files)


if __name__ == '__main__':
    main()
