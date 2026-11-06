#!/usr/bin/env python3
"""Build a read-only MCP query snapshot from a checksum-pinned Zenodo ZIP.

No model fitting, arbitrary archive extraction or runtime network requests.
"""
import argparse
import csv
import hashlib
import io
import json
import math
from pathlib import Path
import re
import sqlite3
import tempfile
from urllib.request import Request, urlopen
import zipfile

ROOT = Path(__file__).resolve().parents[1]
CONFIG = ROOT / 'config/transcriptomics-source.json'
CONTRASTS = ['day1_vs_control', 'day3_vs_control', 'day3_vs_day1']
LIMITATIONS = [
    'Independent secondary analysis of Schmidt et al.; not a new experiment or independent replication.',
    'Three differentiation blocks, two cell-line backgrounds, nine pooled profiles and 23 libraries. The CAMERA model has four residual degrees of freedom.',
    'Control harvest age is unresolved. Common-control contrasts are not verified matched-age treatment effects; Day 3 minus Day 1 is not a treatment-by-time interaction.',
    'Transcriptional associations do not establish mitochondrial respiration, redox flux, telomerase activity, telomere preservation or lengthening.',
    'FGSEA, CAMERA and Bayesian analyses reuse the same experiment and answer different questions. Adjusted p-values and posterior probabilities are not interchangeable.',
    'Estimated-correlation CAMERA is nonsignificant for OXPHOS; fixed-0.01 CAMERA is more favorable. Both settings must be reported. Their reference degrees of freedom also differ (4 versus 21,096).',
    'Bayesian probabilities are conditional on models and priors; Day 1 magnitude is prior-sensitive and Day 3 intervals include zero.',
    'Telomere enrichment is exploratory; removing DNA-repair overlap retains significance only at Day 1. Coordinated NRF2–glutathione support was not established.',
]
DEFINITIONS = {
    'log2FoldChange': 'Unshrunken DESeq2 log2 expression fold-change; not mitochondrial activity.',
    'lfcSE': 'Standard error of the log2 fold-change estimate.',
    'baseMean': 'Mean DESeq2-normalized count across modeled profiles.',
    'stat': 'DESeq2 Wald statistic.', 'pvalue': 'Unadjusted gene-level p-value.',
    'padj': 'DESeq2 adjusted gene-level p-value; null denotes missing, not zero.',
    'NES': 'FGSEA normalized enrichment score; not a gene-expression effect size.',
    'pval': 'Unadjusted p-value for the test identified by the table/method.',
    'PValue': 'Unadjusted CAMERA p-value.',
    'family_fdr': 'Adjusted p-value within this analysis table\'s declared test family. Families differ; consult its source report.',
    'fgsea_internal_padj': 'FGSEA internal adjustment; family_fdr is the declared family correction.',
    'mode': 'CAMERA correlation specification: estimated or fixed_0.01; neither is independent replication.',
    'Correlation': 'Inter-gene correlation used by CAMERA.',
    'variance_inflation_factor': 'Variance inflation due to gene correlation, not an expression effect size.',
    'residual_df': 'Residual degrees of freedom; fixed CAMERA reference df differs; see methods.',
    'probability_gt_zero': 'Posterior probability conditional on the specified model and priors.',
    'mean': 'Bayesian posterior mean of the named parameter; expression-score differences are not gene-level fold changes.',
    'lower95': 'Lower 95% posterior credible bound in posterior_summary.',
    'upper95': 'Upper 95% posterior credible bound in posterior_summary.',
    'fit': 'Bayesian likelihood and prior specification; all ten models are available.',
    'leadingEdge': 'Semicolon-separated leading-edge genes, selected by FGSEA; not independently validated mediators.',
}

def dumps(value):
    return json.dumps(value, ensure_ascii=False, allow_nan=False, separators=(',', ':'))

def fetch_json(url):
    req = Request(url, headers={'User-Agent': 'PsilocybinTranscriptomicsSnapshot/1.0', 'Accept-Encoding': 'identity'})
    with urlopen(req, timeout=45) as response:
        return json.loads(response.read())

def convert(value):
    if value in ('', 'NA', 'NaN'): return None
    if value in ('TRUE', 'True'): return True
    if value in ('FALSE', 'False'): return False
    if re.fullmatch(r'-?\d+', value): return int(value)
    try:
        number = float(value)
        if not math.isfinite(number): raise ValueError('Nonfinite source statistic')
        return number
    except ValueError:
        if value in ('Inf', '-Inf', 'Infinity', '-Infinity'): raise
        return value

def build(archive, source, root=ROOT):
    raw = archive.read_bytes()
    if len(raw) != source['archive_bytes'] or hashlib.md5(raw).hexdigest() != source['archive_md5'] or hashlib.sha256(raw).hexdigest() != source['archive_sha256']:
        raise ValueError('Archive size/checksum does not match the reviewed source configuration')
    snapshot = 'zenodo-' + source['record_id'] + '-' + source['archive_sha256'][:12]
    out = root / 'data/transcriptomics' / snapshot
    out.mkdir(parents=True, exist_ok=True)
    descriptors = []
    with zipfile.ZipFile(archive) as z:
        prefix = 'psilocin-human-neuron-transcriptomics-v' + source['analysis_version'] + '/'
        sums = dict((line.split('  ', 1)[1], line.split('  ', 1)[0]) for line in z.read(prefix + 'SHA256SUMS').decode().splitlines())
        license_map = json.loads(z.read(prefix + 'FILE_LICENSES.json'))
        verified_inputs = {}
        def read(path):
            data = z.read(prefix + path)
            if hashlib.sha256(data).hexdigest() != sums[path]: raise ValueError('Internal archive checksum failed: ' + path)
            verified_inputs[path] = {'path':path,'sha256':hashlib.sha256(data).hexdigest(),'bytes':len(data),'rights':license_map.get(path,{})}
            return data
        def table(path):
            data = read(path)
            reader = csv.DictReader(io.StringIO(data.decode('utf-8')))
            rows = [{key: convert(value) for key, value in row.items()} for row in reader]
            descriptors.append({'path': path, 'sha256': hashlib.sha256(data).hexdigest(), 'bytes': len(data), 'rows': len(rows), 'columns': reader.fieldnames, 'rights': license_map[path], 'url': source['pinned_repository'] + '/blob/' + source['analysis_commit'] + '/' + path})
            return rows
        with tempfile.NamedTemporaryFile(dir=out, suffix='.sqlite', delete=False) as tmp: dbpath = Path(tmp.name)
        try:
            db = sqlite3.connect(dbpath)
            db.executescript('CREATE TABLE genes(gene_id TEXT,stable_id TEXT,symbol TEXT COLLATE NOCASE,contrast TEXT,payload TEXT,source TEXT, PRIMARY KEY(gene_id,contrast)); CREATE INDEX symbols ON genes(symbol); CREATE INDEX stable_ids ON genes(stable_id); CREATE TABLE records(family TEXT,name TEXT,contrast TEXT,variant TEXT,payload TEXT,source TEXT); CREATE INDEX record_lookup ON records(family,name,contrast); CREATE TABLE documents(name TEXT PRIMARY KEY,payload TEXT,source TEXT);')
            for contrast in CONTRASTS:
                path = f'tables/transcriptomics/primary_{contrast}_genes.csv'
                for row in table(path):
                    db.execute('INSERT INTO genes VALUES(?,?,?,?,?,?)', (row['gene_id'], row['gene_id'].split('.')[0], row['symbol'], contrast, dumps(row), path))
            selected = {
                'fgsea': ['tables/transcriptomics/enrichment_all.csv'],
                'camera': ['tables/camera_sensitivity/all_tests.csv'],
                'bayesian': ['tables/bayesian_oxphos/' + n + '.csv' for n in ['posterior_summary','diagnostics','paired_scores','profile_scores','predictive_checks']],
                'dna': ['tables/dna_maintenance/' + n + '.csv' for n in ['all_tests','canonical_panel','concordance','telomere_decomposition']],
                'redox': ['tables/targeted_redox/all_tests.csv'],
                'alias': ['tables/oxphos_alias/primary_comparison.csv','tables/oxphos_alias/alias_mapping.csv'],
            }
            for family, paths in selected.items():
                for path in paths:
                    for row in table(path):
                        db.execute('INSERT INTO records VALUES(?,?,?,?,?,?)', (family,row.get('pathway',row.get('module',Path(path).stem)),row.get('contrast',''),row.get('variant',row.get('mode',row.get('fit',''))),dumps(row),path))
            for name, path in [('design','metadata/schmidt_analysis_units.csv'),('samples','metadata/schmidt_core_samples.csv')]:
                db.execute('INSERT INTO documents VALUES(?,?,?)',(name,dumps(table(path)),path))
            notices = '\n\n'.join('## ' + path + '\n\n' + read(path).decode() for path in ['LICENSE.md','THIRD_PARTY_NOTICES.md','ATLAS_THIRD_PARTY_NOTICES.md','LICENSES/MIT.txt','LICENSES/CC-BY-4.0.txt','LICENSES/CC0-1.0.txt'])
            db.execute('INSERT INTO documents VALUES(?,?,?)',('notices',dumps(notices),'LICENSE.md; THIRD_PARTY_NOTICES.md; ATLAS_THIRD_PARTY_NOTICES.md; LICENSES/'))
            membership = json.loads(read('explorer/src/data/atlas.json'))['membership']
            db.execute('INSERT INTO documents VALUES(?,?,?)',('membership',dumps(membership),'explorer/src/data/atlas.json'))
            db.commit()
            assert db.execute('PRAGMA integrity_check').fetchone()[0] == 'ok'
            genes = db.execute('SELECT COUNT(DISTINCT gene_id) FROM genes').fetchone()[0]
            assert db.execute('SELECT COUNT(*) FROM records WHERE family="camera"').fetchone()[0] == 30
            assert db.execute('SELECT COUNT(DISTINCT variant) FROM records WHERE family="bayesian" AND source LIKE "%posterior_summary.csv"').fetchone()[0] == 10
            db.execute('VACUUM');db.close()
            digest = hashlib.sha256(dbpath.read_bytes()).hexdigest()
            final = out / ('results-' + digest[:16] + '.sqlite')
            dbpath.replace(final)
        finally:
            dbpath.unlink(missing_ok=True)
    manifest = {'schema_version': 1, 'snapshot_id': snapshot, 'source': source, 'database': {'path': str(final.relative_to(root)), 'sha256': digest, 'bytes': final.stat().st_size}, 'gene_count': genes, 'contrasts': CONTRASTS, 'tables': descriptors, 'verified_archive_inputs':list(verified_inputs.values()), 'field_definitions': DEFINITIONS, 'limitations': LIMITATIONS,
        'citations': {'source_study': 'Schmidt M, Hoffrichter A, Davoudi M, et al. (2026). Psilocin fosters neuroplasticity in iPSC-derived human cortical neurons. eLife 14:RP104006. https://doi.org/10.7554/eLife.104006.3', 'reanalysis': 'Germann CB (2026). Metabolic and redox transcription after brief psilocin exposure in human iPSC-derived cortical neurons. Version ' + source['analysis_version'] + '. https://doi.org/' + source['doi']},
        'scope': 'Query derivative of the fixed Zenodo archive. No statistical reanalysis; source CSV values retained, missing values become JSON null, numbers become JSON numbers. Not a mirror of raw data, fitted objects or posterior draws.',
        'update_policy': 'Pinned release; GitHub/atlas changes never alter this snapshot. A new Zenodo release requires checksum review, rebuild, tests and deployment. No runtime calls to Zenodo or GitHub.'}
    (root/'config').mkdir(exist_ok=True)
    (root/'config/transcriptomics-snapshot.json').write_text(json.dumps(manifest, ensure_ascii=False, indent=2, allow_nan=False)+'\n')
    print(dumps({'snapshot_id': snapshot, 'genes': genes, 'tables': len(descriptors), 'database_bytes': final.stat().st_size, 'database_sha256': digest}))
    return manifest

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--archive',type=Path)
    parser.add_argument('--check-latest',action='store_true',help='Report a newer related Zenodo record without changing the deployed snapshot')
    parser.add_argument('--require-current',action='store_true',help='Exit nonzero when the latest-version check reports an available update')
    args = parser.parse_args()
    source = json.loads(CONFIG.read_text())
    if args.check_latest:
        record = fetch_json('https://zenodo.org/records/' + source['record_id'] + '/export/json')
        if record.get('versions',{}).get('is_latest') is True:
            latest_id = str(record['id'])
        else:
            latest_api = record.get('links',{}).get('latest')
            if not latest_api or not latest_api.startswith('https://zenodo.org/api/records/'):
                raise SystemExit('No verified latest-version link; freshness is unknown.')
            latest_id = str(fetch_json(latest_api)['id'])
        if not latest_id.isdigit(): raise SystemExit('Unexpected latest-record identifier; freshness is unknown.')
        latest = 'https://zenodo.org/records/' + latest_id
        print(dumps({'pinned_record':source['record_id'],'latest_record':latest_id,'update_available':latest_id != source['record_id'],'latest_url':latest,'action':'Review a new release, update config/transcriptomics-source.json, rebuild and deploy; no automatic promotion.'}))
        if args.require_current and latest_id != source['record_id']: raise SystemExit(2)
        return
    if not args.archive: parser.error('--archive is required to build (download the fixed source archive_url first)')
    build(args.archive, source)

if __name__ == '__main__': main()
