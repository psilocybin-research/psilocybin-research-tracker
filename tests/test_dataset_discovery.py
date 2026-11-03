"""Detect stale or incomplete human/SEO download listings without making network requests."""
import copy
from html import escape
import importlib.util
import json
from pathlib import Path
import unittest
from unittest.mock import patch
import io
from email.message import Message

spec = importlib.util.spec_from_file_location('research_access', Path(__file__).resolve().parents[1] / 'bin/check-research-access.py')
access = importlib.util.module_from_spec(spec)
spec.loader.exec_module(access)
BASE = 'https://example.org/'


def fixture(snapshot='a' * 64):
    manifest = {'snapshot_id': snapshot, 'artifacts': {},
                'manifest_url': BASE + 'dataset-download.php?snapshot=' + snapshot + '&format=manifest'}
    for name in access.REQUIRED:
        manifest['artifacts'][name] = {'url': BASE + 'dataset-download.php?snapshot=' + snapshot + '&format=' + name,
                                      'filename': name + '.data', 'bytes': 123,
                                      'mime_type': 'application/octet-stream', 'sha256': 'b' * 64}
    return manifest


def markup(manifest):
    links = ''.join('<a href="' + escape(entry['url']) + '" download="' + entry['filename'] + '">Download</a>'
                    for entry in manifest['artifacts'].values())
    links += '<a href="' + escape(manifest['manifest_url']) + '">Snapshot manifest</a>'
    distributions = [{'contentUrl': entry['url'], 'encodingFormat': entry['mime_type'],
                      'contentSize': str(entry['bytes']) + ' bytes', 'sha256': entry['sha256']}
                     for entry in manifest['artifacts'].values()]
    return links + '<script type="application/ld+json">' + json.dumps({
        '@graph': [{'@type': 'Dataset', 'distribution': distributions}]
    }) + '</script>'


def parse(body):
    page = access.DownloadPage()
    page.feed(body)
    return page


class DiscoveryTests(unittest.TestCase):
    def test_all_human_links_and_seo_distributions_agree(self):
        manifest = fixture()
        access.verify_download_page(parse(markup(manifest)), manifest, BASE)

    def test_rds_must_be_present(self):
        manifest = fixture()
        del manifest['artifacts']['rds']
        with self.assertRaisesRegex(ValueError, 'missing a required format'):
            access.verify_download_page(parse(markup(manifest)), manifest, BASE)

    def test_mixed_snapshot_link_is_rejected(self):
        manifest = fixture()
        stale = copy.deepcopy(manifest)
        stale['artifacts']['rds']['url'] = fixture('c' * 64)['artifacts']['rds']['url']
        with self.assertRaisesRegex(ValueError, 'mix snapshots'):
            access.verify_download_page(parse(markup(stale)), manifest, BASE)

    def test_seo_rds_size_and_checksum_must_match(self):
        manifest = fixture()
        for field, wrong in [('bytes', 321), ('sha256', 'c' * 64)]:
            with self.subTest(field=field):
                stale = copy.deepcopy(manifest)
                stale['artifacts']['rds'][field] = wrong
                with self.assertRaisesRegex(ValueError, 'SEO download metadata differs'):
                    access.verify_download_page(parse(markup(stale)), manifest, BASE)

    def test_stable_manifest_cannot_disagree_with_page(self):
        initial, page_manifest = fixture(), fixture('c' * 64)
        body = io.BytesIO(markup(page_manifest).encode('utf-8'))
        body.headers = Message()
        body.headers['Content-Type'] = 'text/html; charset=utf-8'
        with patch.object(access, 'open_public', return_value=body), patch.object(
                access, 'json_response', side_effect=[page_manifest, initial]):
            with self.assertRaisesRegex(ValueError, 'stable current manifest disagree'):
                access.check_download_page(BASE, initial)

    def test_publication_between_requests_is_allowed(self):
        initial, page_manifest = fixture(), fixture('c' * 64)
        body = io.BytesIO(markup(page_manifest).encode('utf-8'))
        body.headers = Message()
        body.headers['Content-Type'] = 'text/html; charset=utf-8'
        with patch.object(access, 'open_public', return_value=body), patch.object(
                access, 'json_response', side_effect=[page_manifest, page_manifest]):
            result = access.check_download_page(BASE, initial)
        self.assertEqual(result['snapshot_id'], page_manifest['snapshot_id'])
        self.assertEqual(result['formats'], 14)


if __name__ == '__main__':
    unittest.main()
