#!/usr/bin/env python3
"""Run Python/SQL, RDS/R and notebook against ONE manifest and compare outputs."""
import argparse
import csv
import importlib.util
import json
from pathlib import Path
import subprocess


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--manifest', type=Path)
    args = parser.parse_args()
    args.output.mkdir(parents=True, exist_ok=False)
    folder = Path(__file__).resolve().parent
    spec = importlib.util.spec_from_file_location('annual_counts', folder / 'annual_counts.py')
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    python_output = args.output / 'python-sql'
    module.reproduce(python_output, args.manifest)
    manifest = python_output / 'dataset-manifest.json'
    subprocess.run(['Rscript', str(folder / 'annual_counts.R'), str(args.output / 'r'), str(manifest)], check=True, timeout=180)
    notebook = json.loads((folder / 'annual_counts.ipynb').read_text(encoding='utf-8'))
    # Execute the exact published, self-contained notebook definition cell.
    scope = {}
    exec(compile(''.join(notebook['cells'][1]['source']), 'annual_counts.ipynb', 'exec'), scope)
    scope['reproduce'](args.output / 'notebook', manifest)
    def rows(path):
        with path.open(encoding='utf-8', newline='') as stream:
            return list(csv.DictReader(stream))
    expected = rows(python_output / 'annual-counts.csv')
    for implementation in ['r', 'notebook']:
        if rows(args.output / implementation / 'annual-counts.csv') != expected:
            raise ValueError('Example tables differ: ' + implementation)
    report = {'status': 'passed', 'implementations': ['Python/SQLite/SQL', 'R/RDS', 'Python notebook'],
              'snapshot_id': json.loads(manifest.read_text())['snapshot_id'], 'year_categories': len(expected),
              'record_count': sum(int(row['total']) for row in expected),
              'scope': 'One verified snapshot, every annual/status row including missing years; records are not independent studies.'}
    (args.output / 'example-check.json').write_text(json.dumps(report, indent=2) + '\n', encoding='utf-8')
    print(json.dumps(report))


if __name__ == '__main__':
    main()
