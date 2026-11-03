#!/usr/bin/env python3
"""Externally verify published research downloads and annual statistics using only public HTTP."""
import argparse
from collections import defaultdict
import csv
from datetime import datetime, timezone
import hashlib
import hmac
import io
import json
import lzma
import importlib.util
import os
from pathlib import Path
import re
import sqlite3
import tempfile
import time
from urllib.error import HTTPError
from urllib.parse import parse_qs, urljoin, urlsplit
from urllib.request import Request, urlopen
import zipfile

MAX_FILE_BYTES = 128 * 1024 * 1024
FIELDS = ('total', 'published', 'preprints', 'trial_registrations', 'other')
REQUIRED = {'sqlite', 'sqlite-xz', 'rds', 'csv', 'json', 'bibtex', 'ris', 'latex', 'bundle', 'compact-json', 'txt', 'statistics-json', 'statistics-csv'}


def require(condition, message):
    if not condition:
        raise ValueError(message)


def open_public(url, method='GET'):
    request = Request(url, method=method, headers={
        'User-Agent': 'PsilocybinResearchAccessCheck/1.0',
        'Accept-Encoding': 'identity',
        'Cache-Control': 'no-cache',
    })
    for attempt in range(2):
        try:
            response = urlopen(request, timeout=45)
            require(response.status == 200, f'Unexpected HTTP status for {url}: {response.status}')
            require(response.headers.get('Content-Encoding', 'identity') == 'identity', 'Server ignored identity transfer encoding')
            return response
        except HTTPError as error:
            delay = error.headers.get('Retry-After', '10')
            if error.code not in (429, 503) or attempt or not delay.isdigit() or int(delay) > 60:
                raise
            time.sleep(max(1, int(delay)))


def json_response(url):
    with open_public(url) as response:
        require(response.headers.get_content_type() == 'application/json', f'Expected JSON at {url}')
        raw = response.read(1024 * 1024 + 1)
        require(len(raw) <= 1024 * 1024, f'Metadata response too large: {url}')
        expected = response.headers.get('X-Publication-Tracker-SHA256')
        require(expected is not None and hmac.compare_digest(hashlib.sha256(raw).hexdigest(), expected), f'Metadata checksum mismatch: {url}')
        return json.loads(raw)


def safe_artifact_url(url, base, snapshot, expected_format):
    actual, origin = urlsplit(url), urlsplit(base)
    query = parse_qs(actual.query)
    require((actual.scheme, actual.netloc) == (origin.scheme, origin.netloc), 'Artifact URL is outside the requested origin')
    require(actual.path == origin.path.rstrip('/') + '/dataset-download.php', 'Artifact URL uses an unexpected handler')
    require(query.get('snapshot') == [snapshot] and query.get('format') == [expected_format], 'Artifact URL does not match the manifest snapshot/format')
    return url


def download(entry, target, snapshot):
    require(type(entry.get('bytes')) is int and 0 <= entry['bytes'] <= MAX_FILE_BYTES, 'Artifact byte size is outside the verification bound')
    require(re.fullmatch('[a-f0-9]{64}', entry.get('sha256', '')) is not None, 'Malformed artifact SHA-256')
    # HEAD must describe this exact existing artifact, without generating a replacement.
    with open_public(entry['url'], 'HEAD') as response:
        require(response.headers.get_content_type() == entry['mime_type'], 'HEAD content type differs from the manifest')
        require(int(response.headers['Content-Length']) == entry['bytes'], 'HEAD byte size differs from the manifest')
        require(response.headers.get('X-Publication-Tracker-SHA256') == entry['sha256'], 'HEAD checksum differs from the manifest')
        require(response.headers.get('X-Publication-Tracker-Snapshot') == snapshot, 'HEAD snapshot differs from the manifest')
    digest, size = hashlib.sha256(), 0
    with open_public(entry['url']) as response, target.open('wb') as stream:
        require(response.headers.get_content_type() == entry['mime_type'], 'GET content type differs from the manifest')
        require(response.headers.get('X-Publication-Tracker-Snapshot') == snapshot, 'GET snapshot differs from the manifest')
        require(int(response.headers['X-Publication-Tracker-Export-Count']) == entry['record_count'], 'GET count differs from the manifest')
        while chunk := response.read(1024 * 1024):
            size += len(chunk)
            require(size <= MAX_FILE_BYTES, 'Artifact exceeded the verification byte bound')
            digest.update(chunk)
            stream.write(chunk)
    require(size == entry['bytes'], 'Downloaded byte count differs from the manifest')
    require(hmac.compare_digest(digest.hexdigest(), entry['sha256']), 'Downloaded bytes do not match the manifest SHA-256')
    return {'bytes': size, 'sha256': digest.hexdigest()}


def expected_statistics(records):
    counts = defaultdict(lambda: {key: 0 for key in FIELDS})
    for record in records:
        year, status = record['publication_year'], record['publication_status']
        key = {'published': 'published', 'preprint': 'preprints', 'clinical trial': 'trial_registrations'}.get(status, 'other')
        counts[year]['total'] += 1
        counts[year][key] += 1
    missing = counts.pop(None, {key: 0 for key in FIELDS})
    totals = {key: missing[key] + sum(row[key] for row in counts.values()) for key in FIELDS}
    return counts, missing, totals


def verify_statistics(summary, records):
    counts, missing, totals = expected_statistics(records)
    require(summary['record_count'] == len(records), 'Statistics record count differs from SQLite')
    require(summary['status_totals'] == totals and summary['missing_year'] == missing, 'Statistics status/missing-year totals differ from SQLite')
    require(summary['missing_year_records'] == missing['total'] and summary['dated_records'] == len(records) - missing['total'], 'Statistics dated/undated counts do not reconcile')
    actual = {row['year']: {key: row[key] for key in FIELDS} for row in summary['years']}
    require(actual == dict(counts) and len(actual) == len(summary['years']), 'Year/status rows differ from SQLite or contain duplicate years')


def check(base):
    base = base.rstrip('/') + '/'
    parsed = urlsplit(base)
    require(parsed.scheme in ('http', 'https') and parsed.netloc and not parsed.username and not parsed.password and not parsed.query and not parsed.fragment, 'Use a plain public HTTP(S) base URL without credentials/query/fragment')
    manifest = json_response(urljoin(base, 'dataset-manifest.json'))
    require(manifest['schema_version'] == 'public-dataset-manifest-v1' and manifest['complete'] is True, 'Manifest does not confirm a complete dataset')
    require(manifest.get('is_current') is True, 'The last completed snapshot is stale; refresh publication before claiming current coverage')
    require(type(manifest.get('record_count')) is int and manifest['record_count'] >= 0, 'Invalid manifest record count')
    snapshot = manifest['snapshot_id']
    require(re.fullmatch('[a-f0-9]{64}', snapshot) is not None, 'Invalid snapshot identity')
    require(REQUIRED <= manifest['artifacts'].keys(), 'Manifest is missing a required public download format')
    report = {'observed_at_utc': datetime.now(timezone.utc).isoformat(), 'base_url': base, 'snapshot_id': snapshot, 'generated_at_utc': manifest['generated_at_utc'], 'record_count': manifest['record_count'], 'artifacts': {}}
    with tempfile.TemporaryDirectory(prefix='research-access-') as directory:
        paths = {}
        for name in sorted(REQUIRED):
            entry = manifest['artifacts'][name]
            require(entry['record_count'] == manifest['record_count'], 'Artifact record count differs from its snapshot')
            safe_artifact_url(entry['url'], base, snapshot, name)
            paths[name] = Path(directory) / name
            report['artifacts'][name] = download(entry, paths[name], snapshot)
        with sqlite3.connect(paths['sqlite'].as_uri() + '?mode=ro', uri=True) as db:
            db.row_factory = sqlite3.Row
            require(db.execute('PRAGMA quick_check').fetchone()[0] == 'ok', 'Public SQLite integrity check failed')
            columns = {row[1] for row in db.execute('PRAGMA table_info(publications)')}
            require(not {'abstract', 'keywords', 'raw_json', 'email', 'full_text'} & columns, 'Public SQLite exposes forbidden source/private columns')
            records = [dict(row) for row in db.execute('SELECT id,title,publication_year,publication_status FROM publications ORDER BY id')]
            metadata = dict(db.execute('SELECT key,value FROM metadata'))
        compressed = manifest['artifacts']['sqlite-xz']
        require(compressed['uncompressed_bytes'] == manifest['artifacts']['sqlite']['bytes'] and compressed['uncompressed_sha256'] == manifest['artifacts']['sqlite']['sha256'], 'Compressed SQLite provenance differs')
        decoder = lzma.LZMADecompressor(memlimit=128 * 1024 * 1024)
        restored = decoder.decompress(paths['sqlite-xz'].read_bytes(), max_length=MAX_FILE_BYTES + 1)
        require(decoder.eof and not decoder.unused_data and len(restored) <= MAX_FILE_BYTES and restored == paths['sqlite'].read_bytes(), 'SQLite XZ does not decompress to the exact public SQLite bytes')
        require(manifest['artifacts']['rds']['source_sha256'] == manifest['artifacts']['sqlite']['sha256'] and manifest['artifacts']['rds']['r_serialization_version'] == 2, 'RDS provenance/serialization metadata mismatch')
        spec = importlib.util.spec_from_file_location('verify_public_rds', Path(__file__).resolve().parents[1] / 'tools/verify-r-dataset.py')
        verifier = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(verifier)
        report['rds_interoperability'] = verifier.verify(paths['sqlite'], paths['rds'])
        require(len(records) == manifest['record_count'] and int(metadata['record_count']) == len(records), 'Public SQLite count differs from manifest')
        require(metadata['generated_at_utc'] == manifest['generated_at_utc'], 'SQLite generation time differs from manifest')
        ids = {row['id'] for row in records}
        full = json.loads(paths['json'].read_bytes())
        compact = json.loads(paths['compact-json'].read_bytes())
        with paths['csv'].open(encoding='utf-8', newline='') as stream:
            csv_rows = list(csv.DictReader(stream))
        for name, rows in [('full JSON', full['papers']), ('compact JSON', compact['records']), ('CSV', csv_rows)]:
            require(len(rows) == len(records) and {int(row['id']) for row in rows} == ids, f'{name} record IDs differ from SQLite')
            for row in rows:
                require(not {'abstract', 'raw_json', 'keywords', 'full_text'} & row.keys(), f'{name} exposes source text')
        require(full['meta']['record_count'] == len(records) and full['meta']['generated_at_utc'] == manifest['generated_at_utc'], 'Full JSON metadata does not match the snapshot')
        require(compact['complete'] is True and compact['record_count'] == compact['total_records'] == len(records) and compact['generated_at_utc'] == manifest['generated_at_utc'], 'Compact JSON is incomplete or has inconsistent metadata')
        by_id = {row['id']: row for row in records}
        for name, rows in [('full JSON', full['papers']), ('CSV', csv_rows)]:
            for row in rows:
                source = by_id[int(row['id'])]
                year = int(row['publication_year']) if row['publication_year'] not in (None, '') else None
                require(year == source['publication_year'] and row['publication_status'] == source['publication_status'], f'{name} year/status differs from SQLite')
        for row in compact['records']:
            require(row['year'] == by_id[row['id']]['publication_year'], 'Compact year differs from public SQLite')
        expected_lines = [f"{row['year'] if row['year'] is not None else 'n.d.'} — {row['title']}" for row in compact['records']]
        require(paths['txt'].read_text(encoding='utf-8').splitlines() == expected_lines, 'TXT is incomplete or differs from the compact directory')
        annual = json.loads(paths['statistics-json'].read_bytes())
        require(annual['catalogue_revision'] == manifest['catalogue_revision'], 'Frozen annual statistics use a different source revision')
        verify_statistics(annual, records)
        with paths['statistics-csv'].open(encoding='utf-8', newline='') as stream:
            annual_rows = list(csv.DictReader(stream))
        require(sum(int(row['total']) for row in annual_rows) == len(records), 'Annual CSV does not reconcile')
        require(len([row for row in annual_rows if row['year'] == '']) == 1, 'Annual CSV must preserve one explicit missing-year category')
        counts, missing, _ = expected_statistics(records)
        csv_counts = {int(row['year']) if row['year'] else None: {key: int(row[key]) for key in FIELDS} for row in annual_rows}
        require(csv_counts == dict(counts) | {None: missing}, 'Annual CSV year/status categories differ from SQLite')
        with zipfile.ZipFile(paths['bundle']) as bundle:
            bundle_meta = json.loads(bundle.read('manifest.json'))
            require(set(bundle_meta['record_ids']) == ids and len(bundle_meta['record_ids']) == len(records) and bundle_meta['record_count'] == len(records), 'Bundle contains different record IDs')
            require(bundle_meta['generated_at_utc'] == manifest['generated_at_utc'], 'Bundle uses a different generation time')
            for name, entry in bundle_meta['files'].items():
                raw = bundle.read(name)
                require(len(raw) == entry['bytes'] and hashlib.sha256(raw).hexdigest() == entry['sha256'], 'Bundle internal checksum mismatch')
        for format_name, pattern in [('bibtex', r'^@article\{'), ('ris', r'^TY  - '), ('latex', r'^\\item ')]:
            require(len(re.findall(pattern, paths[format_name].read_text(encoding='utf-8'), flags=re.M)) == len(records), f'{format_name} reference count differs from the manifest')
        frozen_url = safe_artifact_url(manifest['manifest_url'], base, snapshot, 'manifest')
        frozen = json_response(frozen_url)
        require(frozen == {key: value for key, value in manifest.items() if key != 'is_current'}, 'Versioned manifest differs from the advertised snapshot')
        live = json_response(urljoin(base, 'api/v1/statistics/publications-by-year'))
        if live['catalogue_revision'] == manifest['catalogue_revision']:
            verify_statistics(live, records)
            report['live_statistics'] = 'matched the frozen public database'
        else:
            report['live_statistics'] = 'catalogue changed during this check; frozen downloads remain consistent'
        report['annual_rows'] = len(annual['years'])
        report['missing_year_records'] = annual['missing_year_records']
    for name, expected_type in [('for-ai.html', 'text/html'), ('for-ai.txt', 'text/plain'), ('llms.txt', 'text/plain'), ('docs/research-api.openapi.json', 'application/json')]:
        with open_public(urljoin(base, name)) as response:
            require(response.headers.get_content_type() == expected_type and response.read(1024), f'Documentation MIME/body check failed: {name}')
    report['status'] = 'passed'
    return report


def write_report(path, report):
    path.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.NamedTemporaryFile('w', dir=path.parent, delete=False, encoding='utf-8') as stream:
        temporary = Path(stream.name)
        try:
            json.dump(report, stream, indent=2, ensure_ascii=False)
            stream.write('\n')
            stream.flush()
            os.chmod(temporary, 0o600)
            os.replace(temporary, path)
        finally:
            temporary.unlink(missing_ok=True)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--base-url', default='https://psilocybin-research.com/')
    parser.add_argument('--report', type=Path)
    args = parser.parse_args()
    try:
        report = check(args.base_url)
    except Exception as error:
        report = {'observed_at_utc': datetime.now(timezone.utc).isoformat(), 'status': 'failed', 'error': str(error)}
        if args.report:
            write_report(args.report, report)
        raise SystemExit('Research access check failed: ' + str(error))
    if args.report:
        write_report(args.report, report)
    print(f"Verified {report['record_count']} public records, {len(report['artifacts'])} exact download files, annual statistics and documentation. Snapshot: {report['snapshot_id']}")


if __name__ == '__main__':
    main()
