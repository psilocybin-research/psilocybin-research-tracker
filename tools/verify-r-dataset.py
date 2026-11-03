#!/usr/bin/env python3
"""Verify the public RDS against SQLite using an independent, installed base R."""
import csv
from pathlib import Path
import shutil
import sqlite3
import subprocess
import tempfile
import uuid


def verify(sqlite_path, rds_path, rscript=None):
    rscript = rscript or shutil.which('Rscript')
    if not rscript:
        raise ValueError('Rscript is required for independent RDS interoperability verification')
    source = Path(sqlite_path).resolve()
    with sqlite3.connect(source.as_uri() + '?mode=ro', uri=True) as connection:
        with tempfile.TemporaryDirectory(prefix='verify-public-rds-') as directory:
            folder = Path(directory)
            marker = '__SQL_NULL_' + uuid.uuid4().hex + '__'
            for table, order in [('publications', 'id'), ('metadata', 'key')]:
                columns = connection.execute('PRAGMA table_info(' + table + ')').fetchall()
                rows = connection.execute('SELECT * FROM ' + table + ' ORDER BY ' + order).fetchall()
                if any(marker == value for row in rows for value in row):
                    raise ValueError('Unexpected null-marker collision')
                types = []
                for index, column in enumerate(columns):
                    integer = 'INT' in column[2].upper()
                    large = any(row[index] is not None and not -2147483647 <= row[index] <= 2147483647 for row in rows) if integer else False
                    types.append('numeric' if large else 'integer' if integer else 'character')
                (folder / (table + '.types')).write_text('\n'.join(types) + '\n', encoding='utf-8')
                with (folder / (table + '.csv')).open('w', encoding='utf-8', newline='') as stream:
                    writer = csv.writer(stream, quoting=csv.QUOTE_ALL)
                    writer.writerow([column[1] for column in columns])
                    writer.writerows([[marker if value is None else value for value in row] for row in rows])
            script = Path(__file__).resolve().parents[1] / 'tests/verify-r-dataset.R'
            result = subprocess.run([rscript, str(script), str(Path(rds_path).resolve()), str(folder), marker],
                                    check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=120)
            return result.stdout.decode('utf-8').strip()
