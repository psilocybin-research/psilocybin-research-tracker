#!/usr/bin/env python3
"""Check public MCP lifecycle, all research tools, source privacy and download metadata."""
import argparse
import json
from pathlib import Path
from urllib.error import HTTPError
from urllib.parse import urljoin, urlsplit
from urllib.request import Request, urlopen

from importlib.util import module_from_spec, spec_from_file_location
_spec = spec_from_file_location('research_access', Path(__file__).with_name('check-research-access.py'))
_http = module_from_spec(_spec)
_spec.loader.exec_module(_http)
require = _http.require
TOOLS = {'search', 'fetch', 'search_publications', 'search_trials', 'get_citation', 'get_citation_relationships', 'get_annual_statistics', 'get_dataset_downloads'}
VERSION = '2025-11-25'


class Client:
    def __init__(self, endpoint):
        self.endpoint, self.counter = endpoint, 0

    def post(self, payload, expected=200, extra=None):
        headers = {'User-Agent': 'PsilocybinMcpAccessCheck/1.0', 'Content-Type': 'application/json',
                   'Accept': 'application/json, text/event-stream', 'MCP-Protocol-Version': VERSION,
                   'Accept-Encoding': 'identity'} | (extra or {})
        request = Request(self.endpoint, data=json.dumps(payload).encode(), headers=headers, method='POST')
        for attempt in range(2):
            try:
                response = urlopen(request, timeout=45)
                break
            except HTTPError as error:
                delay = error.headers.get('Retry-After', '10')
                if error.code in (429, 503) and error.code != expected and not attempt and delay.isdigit() and int(delay) <= 30:
                    error.close()
                    _http.time.sleep(max(1, int(delay)))
                    continue
                response = error
                break
        with response:
            require(response.status == expected, f'MCP HTTP {response.status}, expected {expected}')
            raw = response.read(2 * 1024 * 1024 + 1)
            require(len(raw) <= 2 * 1024 * 1024, 'MCP response exceeded 2 MiB')
            require(not response.headers.get('Set-Cookie') and not response.headers.get('Mcp-Session-Id'), 'Stateless public endpoint unexpectedly issued a cookie/session')
            if expected == 202:
                require(not raw, 'Notification acknowledgement must be empty')
                return None
            require(response.headers.get_content_type() == 'application/json', 'MCP must return JSON')
            return json.loads(raw)

    def rpc(self, method, params=None):
        self.counter += 1
        reply = self.post({'jsonrpc': '2.0', 'id': self.counter, 'method': method, 'params': params or {}})
        require(reply.get('jsonrpc') == '2.0' and reply.get('id') == self.counter and 'error' not in reply, f'MCP RPC error for {method}: {reply.get("error")}')
        return reply['result']

    def tool(self, name, args=None):
        result = self.rpc('tools/call', {'name': name, 'arguments': args or {}})
        require(result.get('isError') is False, f'MCP tool failed: {name}')
        data = result['structuredContent']
        require(data == json.loads(result['content'][0]['text']), f'Text/structured result disagreement: {name}')
        return data


def check(base):
    base = base.rstrip('/') + '/'
    parsed = urlsplit(base)
    require(parsed.scheme in ('http', 'https') and parsed.netloc and not parsed.username and not parsed.password and not parsed.query and not parsed.fragment, 'Use a plain HTTP(S) base URL')
    client = Client(urljoin(base, 'mcp'))
    init = client.rpc('initialize', {'protocolVersion': VERSION, 'capabilities': {}, 'clientInfo': {'name': 'research-access-check', 'version': '1.0.0'}})
    require(init['protocolVersion'] == VERSION and 'tools' in init['capabilities'], 'MCP initialization/version failed')
    client.post({'jsonrpc': '2.0', 'method': 'notifications/initialized'}, 202)
    client.rpc('ping')
    tools = client.rpc('tools/list')['tools']
    require({tool['name'] for tool in tools} == TOOLS and len(tools) == len(TOOLS), 'MCP advertised tool set differs')
    for tool in tools:
        annotations = tool['annotations']
        require(annotations['readOnlyHint'] and annotations['destructiveHint'] is False and annotations['openWorldHint'] is False, 'MCP exposes unexpected write/open-world behavior')
        require(tool['inputSchema']['type'] == 'object' and tool['outputSchema']['type'] == 'object', 'MCP tool schema missing')
    results = client.tool('search', {'query': 'psilocybin'})['results']
    require(0 < len(results) <= 20, 'MCP search must return a bounded result set')
    for item in results:
        require(isinstance(item['id'], str) and item['id'].isdigit() and item['title'] and item['url'] == urljoin(base, 'publication.php?id=' + item['id']), 'Search citation identity/URL invalid')
    record_id = results[0]['id']
    document = client.tool('fetch', {'id': record_id})
    require(document['id'] == record_id and document['text'] and document['url'] == results[0]['url'], 'Fetch does not resolve the search identity')
    require(not {'abstract', 'keywords', 'raw_json', 'email', 'full_text'} & document['metadata'].keys(), 'MCP fetch leaks source/private fields')
    publications = client.tool('search_publications', {'per_page': 5})
    require(len(publications['rows']) <= 5 and publications['total'] >= len(publications['rows']), 'Publication page bound/count failed')
    for row in publications['rows']:
        require(not {'abstract', 'keywords', 'raw_json', 'full_text'} & row.keys(), 'Publication search leaks source text')
    trials = client.tool('search_trials', {'per_page': 5})
    require(len(trials['trials']) <= 5 and 'registry_checked_at_utc' in trials, 'Trial page/observation metadata failed')
    require(client.tool('get_citation', {'id': record_id})['citation'], 'Citation missing')
    relationships = client.tool('get_citation_relationships', {'id': record_id, 'limit': 5})
    require(len(relationships['reference_dois']) <= 5 and len(relationships['indexed_citing_records']) <= 5, 'Citation relationship bounds failed')
    stats = client.tool('get_annual_statistics')
    require(stats['record_count'] == stats['dated_records'] + stats['missing_year_records'], 'Annual totals do not reconcile')
    manifest = client.tool('get_dataset_downloads')
    http_manifest = _http.json_response(urljoin(base, 'dataset-manifest.json'))
    require(manifest['snapshot_id'] == http_manifest['snapshot_id'] and manifest['artifacts'] == http_manifest['artifacts'] and manifest['complete'] is True, 'MCP download manifest differs from public HTTP')
    if manifest['catalogue_revision'] == stats['catalogue_revision']:
        require(manifest['record_count'] == stats['record_count'], 'Same-revision MCP statistics and dataset counts differ')
    resources = client.rpc('resources/list')['resources']
    require(len(resources) == 3, 'Finite MCP resource list changed')
    for resource in resources:
        require(resource['uri'].startswith(base), 'Unexpected MCP resource origin')
        content = client.rpc('resources/read', {'uri': resource['uri']})['contents'][0]
        require(content['uri'] == resource['uri'] and content['text'], 'MCP listed resource unreadable')
    bad_tool = client.rpc('tools/call', {'name': 'fetch', 'arguments': {'id': 'https://invalid.test/private'}})
    require(bad_tool['isError'] is True, 'Arbitrary URL fetch must fail')
    forbidden = client.post({'jsonrpc': '2.0', 'id': 999, 'method': 'ping'}, 403, {'Origin': 'https://invalid.test'})
    require('error' in forbidden, 'Forbidden origin lacks transport error')
    try:
        urlopen(Request(client.endpoint, headers={'User-Agent': 'PsilocybinMcpAccessCheck/1.0'}), timeout=45)
        raise ValueError('MCP GET must return 405 rather than a fake landing page')
    except HTTPError as error:
        require(error.code == 405, 'MCP GET must describe unsupported standalone SSE transport')
    return {'status': 'passed', 'endpoint': client.endpoint, 'protocol': VERSION, 'server_version': init['serverInfo']['version'],
            'tools': sorted(TOOLS), 'resources': len(resources), 'observed_at_utc': _http.datetime.now(_http.timezone.utc).isoformat(),
            'record_count': manifest['record_count'], 'snapshot_id': manifest['snapshot_id'], 'requests': client.counter}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--base-url', default='https://psilocybin-research.com/')
    parser.add_argument('--report', type=Path)
    args = parser.parse_args()
    try:
        report = check(args.base_url)
    except Exception as error:
        if args.report:
            _http.write_report(args.report, {'status': 'failed', 'error': str(error)})
        raise SystemExit('MCP check failed: ' + str(error))
    if args.report:
        _http.write_report(args.report, report)
    print(f"Verified MCP lifecycle, {len(report['tools'])} read-only tools, {report['resources']} resources and {report['record_count']} public records.")


if __name__ == '__main__':
    main()
