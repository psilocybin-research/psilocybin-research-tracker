#!/usr/bin/env python3
"""Reproduce annual/status counts from one verified public snapshot (stdlib only).

Run: python3 annual_counts.py --output analysis-2026-11-03
Or preserve/reuse a manifest: --manifest saved-dataset-manifest.json
Never overwrites an existing output directory. Does not reconstruct search pages.
"""
import argparse
import csv
from datetime import datetime, timezone
import hashlib
import json
from pathlib import Path
import re
import sqlite3
import time
from urllib.error import HTTPError
from urllib.parse import urlsplit
from urllib.request import Request, urlopen

MANIFEST_URL = 'https://psilocybin-research.com/dataset-manifest.json'
FIELDS = ['year', 'total', 'published', 'preprints', 'trial_registrations', 'other']
MAX_BYTES = 128 * 1024 * 1024


def retrieve(url):
    parsed = urlsplit(url)
    if not (parsed.scheme == 'https' or (parsed.scheme == 'http' and parsed.hostname in ('127.0.0.1', 'localhost'))):
        raise ValueError('Use HTTPS, or localhost for a test fixture')
    if parsed.username or parsed.password:
        raise ValueError('Credentials in download URLs are not supported')
    request = Request(url, headers={'User-Agent': 'PsilocybinReproducibleExample/1.0', 'Accept-Encoding': 'identity'})
    for attempt in range(3):
        try:
            with urlopen(request, timeout=45) as response:
                if response.status != 200:
                    raise ValueError('Download did not return HTTP 200')
                raw = response.read(MAX_BYTES + 1)
                if len(raw) > MAX_BYTES:
                    raise ValueError('Download exceeds example capacity')
                return raw
        except HTTPError as error:
            delay = error.headers.get('Retry-After', '5')
            if error.code not in (429, 503) or attempt == 2 or not delay.isdigit() or int(delay) > 60:
                raise
            time.sleep(max(1, int(delay)))


def artifact(manifest, name, output):
    entry = manifest['artifacts'][name]
    url = urlsplit(entry['url'])
    origin = urlsplit(manifest['manifest_url'])
    if (url.scheme, url.netloc) != (origin.scheme, origin.netloc):
        raise ValueError('Artifacts must share the frozen manifest origin')
    from urllib.parse import parse_qs
    params = parse_qs(url.query)
    if params.get('snapshot') != [manifest['snapshot_id']] or params.get('format') != [name]:
        raise ValueError('Artifact identity differs from manifest')
    raw = retrieve(entry['url'])
    if len(raw) != entry['bytes'] or hashlib.sha256(raw).hexdigest() != entry['sha256']:
        raise ValueError('Downloaded file does not match its size/SHA-256')
    if entry['record_count'] != manifest['record_count']:
        raise ValueError('Artifact represented count differs')
    path = output / ('publications.sqlite' if name == 'sqlite' else 'publications-by-year.json')
    path.write_bytes(raw)
    return path


def reproduce(output, manifest_path=None):
    output = Path(output)
    output.mkdir(parents=True, exist_ok=False)
    # Write into a new folder; failures remain visibly incomplete, never success.
    raw = Path(manifest_path).read_bytes() if manifest_path else retrieve(MANIFEST_URL)
    manifest = json.loads(raw)
    if manifest.get('complete') is not True or not re.fullmatch('[a-f0-9]{64}', manifest['snapshot_id']):
        raise ValueError('Manifest does not identify a complete snapshot')
    if manifest.get('is_current') is False:
        print('Using a completed historical snapshot; not the latest live catalogue.')
    (output / 'dataset-manifest.json').write_bytes(raw)
    database = artifact(manifest, 'sqlite', output)
    expected = json.loads(artifact(manifest, 'statistics-json', output).read_bytes())
    query = Path(__file__).parent / 'annual-counts.sql.txt'
    if not query.exists():
        query = Path(__file__).parent / 'annual-counts.sql'
    sql = query.read_text(encoding='utf-8')
    with sqlite3.connect(database.resolve().as_uri() + '?mode=ro', uri=True) as connection:
        if connection.execute('PRAGMA quick_check').fetchone()[0] != 'ok':
            raise ValueError('SQLite integrity check failed')
        connection.row_factory = sqlite3.Row
        counts = [dict(row) for row in connection.execute(sql)]
        metadata = dict(connection.execute('SELECT key,value FROM metadata'))
        actual = connection.execute('SELECT COUNT(*) FROM publications').fetchone()[0]
    wanted = [{key: row[key] for key in FIELDS} for row in expected['years']]
    wanted.append({'year': None, **expected['missing_year']})
    if counts != wanted or actual != manifest['record_count'] or sum(row['total'] for row in counts) != actual:
        raise ValueError('SQL, frozen statistics and record totals disagree')
    if metadata['generated_at_utc'] != manifest['generated_at_utc'] or expected['catalogue_revision'] != manifest['catalogue_revision']:
        raise ValueError('Frozen inputs are from different snapshots')
    with (output / 'annual-counts.csv').open('w', newline='', encoding='utf-8') as stream:
        writer = csv.DictWriter(stream, fieldnames=FIELDS)
        writer.writeheader()
        writer.writerows(counts)
    (output / 'annual-counts.sql').write_text(sql, encoding='utf-8')
    provenance = {'status': 'verified', 'snapshot_id': manifest['snapshot_id'],
                  'catalogue_revision': manifest['catalogue_revision'], 'record_count': actual,
                  'generated_at_utc': manifest['generated_at_utc'],
                  'retrieved_at_utc': datetime.now(timezone.utc).isoformat(),
                  'manifest_sha256': hashlib.sha256(raw).hexdigest(),
                  'sqlite_sha256': manifest['artifacts']['sqlite']['sha256'],
                  'statistics_sha256': manifest['artifacts']['statistics-json']['sha256'],
                  'interpretation': 'Indexed records, not independent studies or participants. Missing years remain separate; the current year is partial. Classification and coverage require source checks.'}
    (output / 'analysis-provenance.json').write_text(json.dumps(provenance, indent=2) + '\n', encoding='utf-8')
    print('Verified {} records; {} year categories including missing years.'.format(actual, len(counts)))
    return counts


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--manifest', type=Path)
    args = parser.parse_args()
    reproduce(args.output, args.manifest)
