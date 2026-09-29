"""3r only: pinned-key SSH probe and reproducible UAT deployment.

Requires paramiko; SSH passphrase is read from R3_SSH_PASSPHRASE or getpass.
Does not create firewall rules, format disks or touch YS directories/containers.
"""
from __future__ import annotations
import argparse
import base64
import getpass
import hashlib
import os
from pathlib import Path
import re
import shlex
import socket
import subprocess
import tempfile
import paramiko

ROOT = Path(__file__).resolve().parents[1]

def connect(args):
    secret = os.getenv('R3_SSH_PASSPHRASE') or getpass.getpass('SSH key passphrase: ')
    key_class = paramiko.ECDSAKey if args.key_type == 'ecdsa' else paramiko.Ed25519Key
    key = key_class.from_private_key_file(str(args.key), password=secret)
    transport = paramiko.Transport(socket.create_connection((args.host, 22), timeout=15))
    transport.banner_timeout = 15
    transport.start_client(timeout=15)
    actual = 'SHA256:' + base64.b64encode(hashlib.sha256(transport.get_remote_server_key().asbytes()).digest()).decode().rstrip('=')
    if actual != args.fingerprint:
        transport.close()
        raise RuntimeError('SSH host fingerprint mismatch; verify independently in GCP Console.')
    transport.auth_publickey(args.user, key)
    if not transport.is_authenticated():
        raise RuntimeError('SSH authentication rejected')
    return transport

def run(transport, command):
    channel = transport.open_session(timeout=20)
    channel.exec_command('bash -lc ' + shlex.quote(command))
    # Merge stderr to avoid a full stderr pipe blocking long image builds.
    channel.set_combine_stderr(True)
    for line in channel.makefile('r'):
        print(line, end='', flush=True)
    code = channel.recv_exit_status()
    if code:
        raise RuntimeError(f'Remote command failed ({code})')

def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('action', choices=['probe', 'deploy'])
    p.add_argument('--host', required=True)
    p.add_argument('--user', default='z51bluewofe')
    p.add_argument('--key', type=Path, default=Path.home()/'.ssh'/'ys-instance-51-ed25519')
    p.add_argument('--fingerprint', required=True)
    p.add_argument('--key-type', choices=['ed25519', 'ecdsa'], default='ed25519')
    p.add_argument('--image-bundle', type=Path, help='Local docker save archive containing SHA-tagged images; avoids remote compilation')
    p.add_argument('--env-file', type=Path, help='3r UAT env file, never source YS env')
    args = p.parse_args()
    transport = connect(args)
    try:
        if args.action == 'probe':
            run(transport, 'whoami; hostname; sudo -n true; free -m; df -h /srv; sudo -n docker compose version; sudo -n docker ps --format "{{.Names}} {{.Ports}}"; ss -ltn')
            return
        if not args.env_file or not args.env_file.is_file():
            raise RuntimeError('--env-file is required for deploy')
        env = args.env_file.read_text(encoding='utf-8-sig')
        if not re.search(r'^COMPOSE_PROJECT_NAME=r3-uat$',env,re.M):
            raise RuntimeError('UAT env must specify COMPOSE_PROJECT_NAME=r3-uat')
        if subprocess.check_output(['git','status','--porcelain'],cwd=ROOT,text=True).strip():
            raise RuntimeError('Commit implementation before deploying; release must match Git SHA')
        revision = subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip()
        release = '/opt/3r/releases/' + revision
        with tempfile.TemporaryDirectory(prefix='r3-release-') as folder:
            archive=Path(folder)/'source.tar.gz'
            subprocess.run(['git','archive','--format=tar.gz','-o',str(archive),revision],cwd=ROOT,check=True)
            stage='/tmp/r3-'+revision
            run(transport, 'umask 077; mkdir -p '+shlex.quote(stage))
            sftp=paramiko.SFTPClient.from_transport(transport)
            sftp.put(str(archive),stage+'/source.tar.gz')
            sftp.put(str(args.env_file),stage+'/uat.env')
            sftp.chmod(stage+'/uat.env',0o600)
            if args.image_bundle:
                sftp.put(str(args.image_bundle), stage+'/images.tar')
            sftp.close()
            command=f'''sudo -n mkdir -p {release} /opt/3r/config /srv/3r/uat/backups
sudo -n tar -xzf {stage}/source.tar.gz -C {release}
sudo -n install -m 0600 {stage}/uat.env /opt/3r/config/uat.env
{('sudo -n docker load -i '+stage+'/images.tar') if args.image_bundle else ''}
sudo -n env R3_PREBUILT={'true' if args.image_bundle else 'false'} bash {release}/scripts/deploy-uat.sh {revision}
rm -f {stage}/uat.env'''
            run(transport,command)
    finally:
        transport.close()

if __name__ == '__main__':
    main()
