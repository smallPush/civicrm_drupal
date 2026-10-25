#!/usr/bin/env python3
"""Apply backed-up production migrations without granting SUPER to the app user."""
import os
import subprocess

URI = 'https://donesemprenedores.smallpush.org'


def container(service):
    ids = subprocess.check_output(['docker', 'ps', '-q', '--filter',
        'label=com.docker.swarm.service.name=' + service], text=True).split()
    if len(ids) != 1:
        raise RuntimeError('Expected exactly one running container for ' + service)
    return ids[0]


def main():
    app = container('donesemprenedores-donesemprenedores-pacj57')
    database = container('donesemprenedores-donesemprenedores-a5lqwb')
    def sql(query):
        env = dict(os.environ, SQL=query)
        return subprocess.check_output(['docker', 'exec', '-e', 'SQL', database,
            'sh', '-ceu', 'export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"; '
            'exec mysql -uroot --batch --skip-column-names "$MYSQL_DATABASE" -e "$SQL"'],
            text=True, env=env).strip()
    previous = sql('SELECT @@GLOBAL.log_bin_trust_function_creators')
    if previous not in ('0', '1'):
        raise RuntimeError('Unexpected MySQL variable value')
    drush = ['docker', 'exec', '--user', 'www-data', app, 'vendor/bin/drush', '--uri=' + URI]
    # CiviCRM creates/rebuilds triggers during upgrade. Permit this only for the
    # migration window, keeping the normal database account's grants unchanged.
    sql('SET GLOBAL log_bin_trust_function_creators=1')
    try:
        sql('DROP TABLE IF EXISTS civicrm_install_temp_table_test')
        subprocess.run([*drush, 'updatedb', '--yes', '--quiet'], check=True)
        subprocess.run([*drush, 'php:script', '/var/www/html/ops/upgrade-civicrm.php'], check=True)
        subprocess.run([*drush, 'cache:rebuild'], check=True)
        subprocess.run([*drush, 'status', '--fields=drupal-version,db-status,bootstrap', '--format=json'], check=True)
    finally:
        sql('SET GLOBAL log_bin_trust_function_creators=' + previous)
    print('Migrations complete; original MySQL trigger-creation policy restored.')


if __name__ == '__main__':
    main()
