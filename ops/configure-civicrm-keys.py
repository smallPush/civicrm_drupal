#!/usr/bin/env python3
"""Persist newly required CiviCRM keys in Dokploy; never print their values."""
import json
import secrets
import subprocess

APPLICATION = 'PS5hnfYSx02yBm2AhayaY'


def main():
    pg = subprocess.check_output(['docker', 'ps', '-q', '--filter',
        'label=com.docker.swarm.service.name=dokploy-postgres'], text=True).strip()
    command = ['docker', 'exec', '-i', pg, 'psql', '-v', 'ON_ERROR_STOP=1',
               '-U', 'dokploy', '-d', 'dokploy', '-At']
    query = f'''SELECT json_build_object('env', env) FROM application WHERE "applicationId"='{APPLICATION}';'''
    current = json.loads(subprocess.check_output(command, input=query, text=True))['env'] or ''
    for name, suite in [('CIVICRM_CRED_KEYS', 'aes-cbc'), ('CIVICRM_SIGN_KEYS', 'jwt-hs256')]:
        if not any(line.strip().startswith(name + '=') for line in current.splitlines()):
            current += '\n' + name + '=' + suite + ':hkdf-sha256:' + secrets.token_hex(32) + '\n'
    literal = "'" + current.replace("'", "''") + "'"
    sql = f'''UPDATE application SET env={literal} WHERE "applicationId"='{APPLICATION}';'''
    subprocess.run(command, input=sql, text=True, check=True, stdout=subprocess.DEVNULL)
    print('Required CiviCRM keys persisted in Dokploy; existing values retained.')


if __name__ == '__main__':
    main()
