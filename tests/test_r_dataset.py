import importlib.util
import lzma
from pathlib import Path
import sqlite3
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]


def module(name, path):
    spec = importlib.util.spec_from_file_location(name, ROOT / path)
    value = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value)
    return value


builder = module('r_dataset_builder', 'bin/build-r-dataset.py')
verifier = module('r_dataset_verifier', 'tools/verify-r-dataset.py')


class PublicRDatasetTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='r-dataset-test-')
        self.folder = Path(self.temporary.name)
        self.source = self.folder / 'public.sqlite'
        self.rds = self.folder / 'public.rds'
        self.xz = self.folder / 'public.sqlite.xz'
        self.db = sqlite3.connect(self.source)
        self.db.execute('CREATE TABLE publications (' + ','.join(name + (' INTEGER' if name in builder.INTEGER_COLUMNS else ' TEXT') for name in builder.PUBLIC_COLUMNS) + ')')
        self.db.execute('CREATE TABLE metadata (key TEXT PRIMARY KEY, value TEXT)')
        self.db.execute("INSERT INTO metadata VALUES ('record_count','0')")
        self.db.commit()

    def tearDown(self):
        self.db.close()
        self.temporary.cleanup()

    def insert(self, **values):
        self.db.execute('INSERT INTO publications (' + ','.join(values) + ') VALUES (' + ','.join('?' for _ in values) + ')', list(values.values()))
        self.db.execute("UPDATE metadata SET value=(SELECT COUNT(*) FROM publications) WHERE key='record_count'")
        self.db.commit()

    def verify(self):
        builder.build(self.source, self.rds, self.xz)
        self.assertEqual(lzma.decompress(self.xz.read_bytes()), self.source.read_bytes())
        self.assertIn('match SQLite', verifier.verify(self.source, self.rds))

    def test_unicode_null_empty_literal_na_and_duplicate_titles(self):
        self.insert(id=1, title='α — 日本語 🍄\n"quoted", line', authors='', journal='NA', publication_year=None, doi='10.example/é', publication_status='clinical trial', abstract_available=0, abstract_redistributed=0)
        self.insert(id=2, title='α — 日本語 🍄\n"quoted", line', authors=None, journal='N/A', publication_year=2026, publication_status='preprint', abstract_available=1)
        self.verify()

    def test_zero_rows(self):
        self.verify()

    def test_integer_boundaries_promote_without_loss(self):
        self.insert(id=2147483648, title='large ID', publication_year=-2147483648)
        self.insert(id=9007199254740992, title='largest exact supported ID', publication_year=None)
        self.verify()

    def test_unsafe_integer_fails(self):
        self.insert(id=9007199254740993, title='unrepresentable ID')
        with self.assertRaises(ValueError):
            builder.build(self.source, self.rds, self.xz)

    def test_private_schema_rejected(self):
        self.db.execute('ALTER TABLE publications ADD COLUMN raw_json TEXT')
        self.db.commit()
        with self.assertRaises(ValueError):
            builder.build(self.source, self.rds, self.xz)
        self.assertFalse(self.rds.exists())

    def test_nul_rejected_and_source_never_overwritten(self):
        self.insert(id=1, title='bad\0title')
        before = self.source.read_bytes()
        with self.assertRaises(ValueError):
            builder.build(self.source, self.source, self.xz)
        self.assertEqual(before, self.source.read_bytes())
        with self.assertRaises(ValueError):
            builder.build(self.source, self.rds, self.xz)


if __name__ == '__main__':
    unittest.main()
