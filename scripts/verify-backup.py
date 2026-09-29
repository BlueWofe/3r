"""Verify a PostgreSQL dump by restoring to a disposable database inside 3r.

Only uses the explicitly selected 3r Compose project. The application database
is read, never overwritten; no existing database is dropped.
"""
import argparse
import datetime
from pathlib import Path
import subprocess
import tempfile

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--project', choices=['r3-dev', 'r3-uat'], default='r3-dev')
parser.add_argument('--env-file', default='.env')
args = parser.parse_args()
dc = ['docker', 'compose', '--env-file', args.env_file, '-p', args.project, '-f', 'compose.yml']
if args.project == 'r3-uat':
    dc += ['-f', 'compose.uat.yml']
dc += ['exec', '-T', 'postgres']
name = 'r3_restore_check_' + datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%d%H%M%S%f')
def command(*parts, **kwargs):
    return subprocess.run(dc + list(parts), check=True, **kwargs)
def count(db):
    return subprocess.check_output(dc + ['psql', '-U', 'r3', '-d', db, '-Atc',
        "SELECT 'users:'||count(*) FROM users UNION ALL SELECT 'entities:'||count(*) FROM entities UNION ALL SELECT 'assignments:'||count(*) FROM assignments UNION ALL SELECT 'prisons:'||count(*) FROM prisons UNION ALL SELECT 'class_templates:'||count(*) FROM class_templates UNION ALL SELECT 'service_sessions:'||count(*) FROM service_sessions"], text=True).strip()
with tempfile.TemporaryDirectory(prefix='r3-backup-verify-') as tmp:
    archive = Path(tmp) / 'verification.dump'
    before = count('r3')
    with archive.open('wb') as output:
        command('pg_dump', '-U', 'r3', '-d', 'r3', '-Fc', stdout=output)
    command('createdb', '-U', 'r3', name)
    try:
        with archive.open('rb') as source:
            command('pg_restore', '-U', 'r3', '--exit-on-error', '-d', name, stdin=source)
        restored = count(name)
        if before != restored:
            raise RuntimeError('Restored counts differ; repeat during a quiet maintenance window.')
        print('Backup restore verified; matching counts:\n' + restored)
        print('Archive size:', archive.stat().st_size)
    finally:
        command('dropdb', '-U', 'r3', name)
