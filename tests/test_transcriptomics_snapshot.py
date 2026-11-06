"""Independent source-value and reproducibility checks for the deployed query snapshot."""
import csv
import hashlib
import importlib.util
import io
import json
from pathlib import Path
import sqlite3
import tempfile
import unittest
import zipfile

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('snapshot_builder',ROOT/'bin/build-transcriptomics-snapshot.py')
builder = importlib.util.module_from_spec(spec);spec.loader.exec_module(builder)

class SnapshotTests(unittest.TestCase):
    def test_conversion(self):
        self.assertIsNone(builder.convert('NA'))
        self.assertIsNone(builder.convert(''))
        self.assertEqual(builder.convert('1.25e-12'),1.25e-12)
        self.assertEqual(builder.convert('ENSG000001.2'),'ENSG000001.2')
        with self.assertRaises(ValueError): builder.convert('Inf')

    def test_all_imported_values_against_archive(self):
        manifest=json.loads((ROOT/'config/transcriptomics-snapshot.json').read_text())
        archive=ROOT/'data/transcriptomics-source.zip'
        if not archive.exists(): self.skipTest('Source ZIP not downloaded; follow docs/transcriptomics-mcp.md to run the archive integration check')
        db=sqlite3.connect(ROOT/manifest['database']['path'])
        self.assertEqual(hashlib.sha256((ROOT/manifest['database']['path']).read_bytes()).hexdigest(),manifest['database']['sha256'])
        with zipfile.ZipFile(archive) as z:
            prefix='psilocin-human-neuron-transcriptomics-v1.2.0/'
            for descriptor in manifest['tables']:
                path=descriptor['path'];raw=z.read(prefix+path)
                self.assertEqual(hashlib.sha256(raw).hexdigest(),descriptor['sha256'])
                expected=[{k:builder.convert(v) for k,v in row.items()} for row in csv.DictReader(io.StringIO(raw.decode()))]
                if path.startswith('metadata/'):
                    actual=json.loads(db.execute('SELECT payload FROM documents WHERE source=?',(path,)).fetchone()[0])
                else:
                    table='genes' if '/primary_' in path and path.endswith('_genes.csv') else 'records'
                    actual=[json.loads(row[0]) for row in db.execute('SELECT payload FROM '+table+' WHERE source=? ORDER BY rowid',(path,))]
                self.assertEqual(actual,expected,path)
                self.assertEqual(len(actual),descriptor['rows'])
        db.close()

    def test_wrong_archive_rejected(self):
        source=json.loads((ROOT/'config/transcriptomics-source.json').read_text())
        with tempfile.TemporaryDirectory() as tmp:
            wrong=Path(tmp)/'bad.zip';wrong.write_bytes(b'not the published archive')
            with self.assertRaisesRegex(ValueError,'checksum'):builder.build(wrong,source,Path(tmp))

if __name__=='__main__':unittest.main()
