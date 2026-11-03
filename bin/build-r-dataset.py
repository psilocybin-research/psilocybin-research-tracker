#!/usr/bin/env python3
"""CLI-only, dependency-free XZ exports from the already sanitized public SQLite.

R serialization v2, XDR: a named list of plain data.frames, atomic vectors and
attributes only. No executable R objects, factors, external pointers or ALTREP.
Format reference: R Internals, section 1.8 (Serialization Formats).
Compatibility is checked by real R readRDS(), not by this writer reading itself.
Python 3.6+ and its standard sqlite3/lzma modules are sufficient on the host.
"""
import argparse
import hashlib
import lzma
import os
from pathlib import Path
import sqlite3
import struct


PUBLIC_COLUMNS = (
    'id', 'title', 'authors', 'journal', 'publication_date', 'publication_year',
    'doi', 'pubmed_id', 'source_url', 'source_record_id', 'substance_tags',
    'topic_tags', 'study_type', 'source_name', 'publication_status', 'date_added',
    'last_checked', 'abstract_available', 'abstract_source', 'abstract_source_url',
    'abstract_redistributed', 'text_rights_status', 'text_license_uri',
    'source_provenance_json',
)
INTEGER_COLUMNS = {'id', 'publication_year', 'abstract_available', 'abstract_redistributed'}
MAX_RECORDS = 50000


class RWriter:
    """The small non-executable subset needed for two public data.frames."""
    def __init__(self, stream):
        self.stream = stream

    def integer(self, value):
        self.stream.write(struct.pack('>i', value))

    def string(self, value):
        # CHARSXP; UTF-8 GP flag at bit 15, -1 length denotes NA_STRING.
        self.integer(9 | (1 << 15))
        if value is None:
            self.integer(-1)
        else:
            raw = value.encode('utf-8', errors='strict')
            if b'\0' in raw:
                raise ValueError('Embedded NUL cannot be represented in an R string')
            self.integer(len(raw))
            self.stream.write(raw)

    def strings(self, values):
        self.integer(16)  # STRSXP
        self.integer(len(values))
        for value in values:
            if value is not None and not isinstance(value, str):
                raise ValueError('Unexpected non-text SQLite value')
            self.string(value)

    def integers(self, values):
        if any(value is not None and (type(value) is not int or abs(value) > 2**53) for value in values):
            raise ValueError('SQLite integer cannot be represented exactly in base R')
        # R reserves the most-negative int32 as NA. Promote the entire column
        # to exact doubles if necessary; never silently truncate identifiers.
        real = any(value is not None and not -2147483647 <= value <= 2147483647 for value in values)
        self.integer(14 if real else 13)
        self.integer(len(values))
        for value in values:
            if real:
                self.stream.write(bytes.fromhex('7ff00000000007a2') if value is None else struct.pack('>d', value))
            else:
                self.integer(-2147483648 if value is None else value)

    def attribute(self, name):
        self.integer(2 | (1 << 10))  # tagged LISTSXP
        self.integer(1)  # SYMSXP
        self.string(name)

    def frame(self, names, rows, integer_names):
        self.integer(19 | (1 << 8) | (1 << 9))  # object VECSXP + attributes
        self.integer(len(names))
        for index, name in enumerate(names):
            values = [row[index] for row in rows]
            (self.integers if name in integer_names else self.strings)(values)
        self.attribute('names')
        self.strings(names)
        self.attribute('class')
        self.strings(['data.frame'])
        self.attribute('row.names')
        # Compact automatic row names; an empty frame has integer(0).
        self.integer(13)
        self.integer(2 if rows else 0)
        if rows:
            self.integer(-2147483648)
            self.integer(-len(rows))
        self.integer(254)  # NILVALUE_SXP terminates attributes

    def dataset(self, publications, metadata):
        self.stream.write(b'X\n')
        # Version 2 XDR, format feature level R 2.3.0. No native encoding,
        # package-specific objects or newer serialization extensions required.
        for value in (2, 0x020300, 0x020300):
            self.integer(value)
        self.integer(19 | (1 << 9))
        self.integer(2)
        self.frame(PUBLIC_COLUMNS, publications, INTEGER_COLUMNS)
        self.frame(('key', 'value'), metadata, set())
        self.attribute('names')
        self.strings(['publications', 'metadata'])
        self.integer(254)


def build(source, rds_path, sqlite_path):
    source = source.resolve(strict=True)
    if any(path.exists() or path.resolve() == source for path in (rds_path, sqlite_path)):
        raise ValueError('Refusing to overwrite an existing file')
    if rds_path.resolve() == sqlite_path.resolve():
        raise ValueError('Output paths must differ')
    connection = sqlite3.connect(source.as_uri() + '?mode=ro', uri=True)
    try:
        connection.execute('PRAGMA query_only=ON')
        connection.execute('BEGIN')
        if connection.execute('PRAGMA quick_check').fetchone()[0] != 'ok':
            raise ValueError('Invalid public SQLite database')
        tables = {row[0] for row in connection.execute("SELECT name FROM sqlite_master WHERE type='table'")}
        columns = tuple(row[1] for row in connection.execute('PRAGMA table_info(publications)'))
        if tables != {'publications', 'metadata'} or columns != PUBLIC_COLUMNS:
            raise ValueError('Only the allowlisted public SQLite schema may be exported')
        if tuple(row[1] for row in connection.execute('PRAGMA table_info(metadata)')) != ('key', 'value'):
            raise ValueError('Unexpected public metadata schema')
        publications = connection.execute('SELECT * FROM publications ORDER BY id LIMIT ?', (MAX_RECORDS + 1,)).fetchall()
        metadata = connection.execute('SELECT key,value FROM metadata ORDER BY key').fetchall()
        if len(publications) > MAX_RECORDS or int(dict(metadata)['record_count']) != len(publications):
            raise ValueError('Public record count or capacity mismatch')
        with rds_path.open('xb') as raw:
            os.chmod(str(rds_path), 0o600)
            with lzma.LZMAFile(raw, 'w', preset=6, check=lzma.CHECK_CRC64) as stream:
                RWriter(stream).dataset(publications, metadata)
        digest = hashlib.sha256()
        with source.open('rb') as raw, sqlite_path.open('xb') as compressed:
            os.chmod(str(sqlite_path), 0o600)
            with lzma.LZMAFile(compressed, 'w', preset=6, check=lzma.CHECK_CRC64) as stream:
                while True:
                    chunk = raw.read(1024 * 1024)
                    if not chunk:
                        break
                    digest.update(chunk)
                    stream.write(chunk)
        # Verify exact SQLite bytes after decompression before publication.
        restored = hashlib.sha256()
        with lzma.open(str(sqlite_path), 'rb') as stream:
            while True:
                chunk = stream.read(1024 * 1024)
                if not chunk:
                    break
                restored.update(chunk)
        if digest.digest() != restored.digest():
            raise ValueError('Compressed SQLite integrity mismatch')
    finally:
        connection.close()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--sqlite', type=Path, required=True)
    parser.add_argument('--rds', type=Path, required=True)
    parser.add_argument('--sqlite-xz', type=Path, required=True)
    args = parser.parse_args()
    build(args.sqlite, args.rds, args.sqlite_xz)


if __name__ == '__main__':
    main()
