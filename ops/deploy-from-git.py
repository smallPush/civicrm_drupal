#!/usr/bin/env python3
"""Queue a normal Dokploy Git deployment using its existing private webhook."""
import json
import subprocess
import urllib.request


def main():
    pg = subprocess.check_output(['docker', 'ps', '-q', '--filter',
        'label=com.docker.swarm.service.name=dokploy-postgres'], text=True).strip()
    sql = '''SELECT json_build_object('token', "refreshToken", 'branch', branch)
             FROM application WHERE "applicationId"='PS5hnfYSx02yBm2AhayaY';'''
    config = json.loads(subprocess.check_output(['docker', 'exec', '-i', pg, 'psql',
        '-U', 'dokploy', '-d', 'dokploy', '-At'], input=sql, text=True))
    revision = subprocess.check_output(['git', 'rev-parse', 'HEAD'], text=True).strip()
    body = {'ref': 'refs/heads/' + config['branch'],
            'head_commit': {'id': revision, 'message': 'Security dependency upgrade ' + revision},
            'commits': [{'added': [], 'removed': [], 'modified': ['Dockerfile', 'composer.lock']}]}
    request = urllib.request.Request('http://127.0.0.1:3000/api/deploy/' + config['token'],
        data=json.dumps(body).encode(), headers={'Content-Type': 'application/json', 'X-GitHub-Event': 'push'})
    with urllib.request.urlopen(request, timeout=30) as response:
        print('Dokploy deployment queued:', response.status)


if __name__ == '__main__':
    main()
