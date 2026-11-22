<?php
declare(strict_types=1);

/** A portable copy of the public records actually exported, with verifiable provenance. */
final class ResearchBundle
{
    public static function build(array $papers, array $filters, int $limit, bool $selected, ?string $generatedAt = null): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('Research bundle generation is unavailable.');
        }
        $papers = public_papers($papers);
        $files = [
            'records.csv' => ExportService::csv($papers),
            'records.json' => ExportService::json($papers, $generatedAt),
            'references.bib' => ExportService::bibtex($papers),
            'README.txt' => "Psilocybin Research — reproducible research bundle\n\n"
                . "Keep this archive and its manifest with your analysis. SHA256SUMS verifies the exact files included.\n"
                . "CSV, JSON and BibTeX describe the same ordered set of public bibliographic records.\n"
                . "The live catalogue can change. This download does not mint a DOI or update the fixed Zenodo deposit.\n"
                . "A reached record limit may truncate a filtered export; consult manifest.json before analysis.\n\n"
                . "Records, registry entries and derived labels are not independent participants, verified findings or clinical advice.\n"
                . "Verify original sources and retain their publication status and identifiers.\n"
                . "Source abstracts, full texts, unrestricted importer payloads and private subscriber data are excluded.\n"
                . "Software: MIT. Compiler-held dataset rights: CC BY 4.0; third-party material retains its own rights.\n"
                . "Reuse and citation: https://psilocybin-research.com/research-dataset.php\n",
        ];
        $manifest = [
            'format' => 'psilocybin-research-bundle-v1',
            'generated_at_utc' => $generatedAt ?? current_utc(),
            'source' => Config::publicBaseUrl(),
            'selection' => $selected ? 'explicit-public-record-ids' : 'filtered-records',
            'filters' => $selected ? (object)[] : $filters,
            'record_ids' => array_map(static fn(array $paper): int => (int)$paper['id'], $papers),
            'record_count' => count($papers),
            'requested_record_limit' => $selected ? null : $limit,
            'may_be_truncated' => !$selected && count($papers) >= $limit,
            'schema_variant' => 'rights-safe-core-v1',
            'fixed_archive' => ['version' => '1.1.0', 'doi' => '10.5281/zenodo.24051198', 'relationship' => 'Separate deposited snapshot; not the identity of this live export.'],
            'files' => [],
        ];
        foreach ($files as $name => $content) {
            $manifest['files'][$name] = ['sha256' => hash('sha256', $content), 'bytes' => strlen($content)];
        }
        $files['manifest.json'] = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        $files['SHA256SUMS'] = '';
        foreach ($files as $name => $content) {
            if ($name !== 'SHA256SUMS') $files['SHA256SUMS'] .= hash('sha256', $content) . '  ' . $name . "\n";
        }
        $temporary = tempnam(sys_get_temp_dir(), 'psilo-bundle-');
        if ($temporary === false) throw new RuntimeException('Cannot create research bundle.');
        $zip = new ZipArchive();
        $opened = false;
        try {
            if (!chmod($temporary, 0600) || $zip->open($temporary, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Cannot create research bundle.');
            }
            $opened = true;
            foreach ($files as $name => $content) {
                if (!$zip->addFromString($name, $content)) throw new RuntimeException('Cannot write research bundle.');
            }
            if (!$zip->close()) throw new RuntimeException('Cannot finalize research bundle.');
            $opened = false;
            $content = file_get_contents($temporary);
            if ($content === false) throw new RuntimeException('Cannot read research bundle.');
            return $content;
        } finally {
            if ($opened) $zip->close();
            unlink($temporary);
        }
    }
}
