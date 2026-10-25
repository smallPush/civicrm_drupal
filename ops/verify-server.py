#!/usr/bin/env python3
"""Generate a non-secret maintenance evidence report and fail on regressions."""
import argparse
import datetime
import hashlib
import json
from pathlib import Path
import subprocess

BASELINE = {
    'stoic.smallpush.org': ('/', 200),
    'realstate.smallpush.org': ('/', 502),
    'donesemprenedores.smallpush.org': ('/user/login', 200),
    'mates.smallpush.org': ('/', 200),
    'findwork.smallpush.org': ('/', 502),
    'nexusspain.com': ('/', 200),
    'matesstaging.smallpush.org': ('/', 200),
    'local.smallpush.org': ('/', 502),
    'clota.smallpush.org': ('/', 502),
    'demo.smallpush.org': ('/', 502),
    'leadmagnet.smallpush.org': ('/', 200),
}


def output(*args):
    return subprocess.check_output(args, text=True).strip()


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--output', type=Path)
    args = parser.parse_args()
    report = {'checked_utc': datetime.datetime.now(datetime.timezone.utc).isoformat()}
    report['packages'] = output('dpkg-query', '-W', '-f=${Package}=${Version}\n',
        'docker-ce', 'docker-ce-cli', 'containerd.io', 'docker-buildx-plugin',
        'docker-compose-plugin', 'docker-ce-rootless-extras', 'docker-model-plugin').splitlines()
    report['docker'] = json.loads(output('docker', 'version', '--format', '{{json .Server}}'))
    report['services'] = []
    healthy = True
    for line in output('docker', 'service', 'ls', '--format', '{{json .}}').splitlines():
        item = json.loads(line)
        row = {k: item[k] for k in ['Name', 'Image', 'Replicas']}
        report['services'].append(row)
        healthy &= len(set(item['Replicas'].split()[0].split('/'))) == 1
    report['endpoints'] = {}
    for domain, (path, expected) in BASELINE.items():
        result = subprocess.run(['curl', '-sS', '--max-time', '20', '-o', '/dev/null',
            '-w', '%{http_code}', '--resolve', domain + ':443:127.0.0.1',
            'https://' + domain + path], text=True, capture_output=True)
        passed = result.returncode == 0 and result.stdout == str(expected)
        report['endpoints'][domain] = {'path': path, 'baseline': expected,
            'actual': result.stdout, 'matches_baseline': passed}
        healthy &= passed
    report['dokploy_health'] = json.loads(output('curl', '-fsS', '--max-time', '15',
        'http://127.0.0.1:3000/api/health'))
    healthy &= report['dokploy_health'].get('ok') is True
    app = output('docker', 'ps', '-q', '--filter',
        'label=com.docker.swarm.service.name=donesemprenedores-donesemprenedores-pacj57')
    php = '''require 'vendor/autoload.php';
        $r = ['php' => PHP_VERSION, 'upload_progress' => ini_get('session.upload_progress.enabled')];
        foreach (['drupal/core','drupal/webform','civicrm/civicrm-core','guzzlehttp/guzzle',
            'dompdf/dompdf','phpoffice/phpspreadsheet','symfony/http-foundation','symfony/yaml'] as $p) {
            $r[$p] = Composer\\InstalledVersions::getPrettyVersion($p);
        }
        echo json_encode($r);'''
    report['application'] = json.loads(output('docker', 'exec', app, 'php', '-r', php))
    healthy &= report['application']['php'] == '8.3.36'
    healthy &= not report['application']['upload_progress']
    healthy &= report['application']['drupal/core'] == '11.4.11'
    healthy &= report['application']['civicrm/civicrm-core'] == '6.19.2'
    report['application']['image_id'] = output('docker', 'inspect', app, '--format', '{{.Image}}')
    report['application']['composer_lock_sha256'] = output('docker', 'exec', app,
        'sha256sum', '/var/www/html/composer.lock').split()[0]
    report['application']['bootstrap'] = json.loads(output('docker', 'exec', '--user',
        'www-data', app, 'vendor/bin/drush', '--uri=https://donesemprenedores.smallpush.org',
        'status', '--fields=drupal-version,db-status,bootstrap', '--format=json'))
    healthy &= report['application']['bootstrap']['bootstrap'] == 'Successful'
    healthy &= report['application']['bootstrap']['db-status'] == 'Connected'
    for mode, flags in [('installed', []), ('locked', ['--locked'])]:
        audit = json.loads(output('docker', 'exec', app, 'composer', '--no-plugins',
            '--no-scripts', 'audit', '--no-dev', '--format=json', *flags))
        report['application'][mode + '_audit'] = audit
        healthy &= not audit.get('advisories')
    report['platform_images'] = {}
    for service in ['dokploy', 'traefik']:
        report['platform_images'][service] = output('docker', 'service', 'inspect',
            service, '--format', '{{.Spec.TaskTemplate.ContainerSpec.Image}}')
    report['config_sha256'] = {}
    for filename in ['/etc/dokploy/security/php-security.ini',
                     '/etc/dokploy/traefik/traefik.yml',
                     '/etc/systemd/system/dokploy-admin-firewall.service',
                     '/usr/local/lib/dokploy-admin-firewall.py']:
        report['config_sha256'][filename] = hashlib.sha256(Path(filename).read_bytes()).hexdigest()
    interfaces = {i['ifname'] for i in json.loads(output('ip', '-j', 'address'))}
    for version, tool in [('ipv4', 'iptables'), ('ipv6', 'ip6tables')]:
        rules = output('/usr/sbin/' + tool, '-S', 'DOCKER-USER')
        tokens = rules.split()
        guarded = 'DOKPLOY-ADMIN-GUARD' in tokens and '-i' in tokens
        guarded &= guarded and tokens[tokens.index('-i') + 1] in interfaces
        drops = output('/usr/sbin/' + tool, '-S', 'DOKPLOY-ADMIN-GUARD')
        guarded &= '--ctorigdstport 3000 -j DROP' in drops
        report[version + '_admin_guard'] = guarded
        healthy &= guarded
    report['checks_passed'] = bool(healthy)
    text = json.dumps(report, indent=2) + '\n'
    if args.output:
        args.output.write_text(text)
        print('Verification ' + ('passed' if healthy else 'FAILED') + '; report: ' + str(args.output))
    else:
        print(text)
    raise SystemExit(0 if healthy else 1)


if __name__ == '__main__':
    main()
