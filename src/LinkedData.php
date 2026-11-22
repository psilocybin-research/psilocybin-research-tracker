<?php
declare(strict_types=1);

/** Shared public entity model. All record input passes through the rights-safe projection. */
final class LinkedData
{
    /** The film and watch page share stable identities across HTML and sitemaps. */
    public static function researchFilm(): array
    {
        $url = ResearchFilm::url();
        $video = [
            '@type' => 'VideoObject', '@id' => $url . '#video',
            'name' => ResearchFilm::NAME,
            'description' => ResearchFilm::description(),
            'url' => $url, 'contentUrl' => ResearchFilm::url(ResearchFilm::ASSETS . 'psilocybin-research-desktop.mp4'),
            'thumbnailUrl' => [ResearchFilm::url(ResearchFilm::ASSETS . 'desktop-poster.jpg')],
            'uploadDate' => ResearchFilm::uploaded(), 'duration' => 'PT' . ResearchFilm::data()['film_duration_seconds'] . 'S',
            'encodingFormat' => 'video/mp4', 'width' => 1920, 'height' => 1080,
            'inLanguage' => 'en', 'creator' => self::maintainer(),
            'hasPart' => array_map(static fn(array $chapter): array => ['@type' => 'Clip'] + $chapter + ['url' => $url . '#t=' . $chapter['startOffset']], ResearchFilm::chapters()),
        ];
        return self::document([
            ['@type' => 'WebPage', '@id' => $url . '#page', 'url' => $url,
                'name' => ResearchFilm::TITLE, 'description' => ResearchFilm::description(),
                'mainEntity' => ['@id' => $url . '#video'], 'isPartOf' => ['@id' => self::base() . '#website'],
                'breadcrumb' => ['@id' => $url . '#breadcrumb']],
            $video,
            ['@type' => 'BreadcrumbList', '@id' => $url . '#breadcrumb', 'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Research database', 'item' => self::base()],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'Research in motion', 'item' => $url]]],
        ]);
    }

    public static function base(): string { return rtrim(Config::publicBaseUrl(), '/') . '/'; }
    public static function context(): array
    {
        return ['https://schema.org', ['skos' => 'http://www.w3.org/2004/02/skos/core#', 'prov' => 'http://www.w3.org/ns/prov#', 'dct' => 'http://purl.org/dc/terms/']];
    }
    public static function document(array $nodes): array { return ['@context' => self::context(), '@graph' => array_values($nodes)]; }
    public static function maintainer(): array
    {
        return ['@type' => 'Person', '@id' => self::base() . 'editorial-policy.php#author', 'name' => 'Dr. Christopher B. Germann',
            'sameAs' => ['https://orcid.org/0000-0002-1573-4651'], 'affiliation' => ['@type' => 'CollegeOrUniversity', 'name' => 'University of Witten/Herdecke', 'url' => 'https://www.uni-wh.de/', 'department' => ['@type' => 'Organization', 'name' => 'Faculty of Health · School of Medicine']]];
    }
    public static function website(): array
    {
        return ['@type' => 'WebSite', '@id' => self::base() . '#website', 'url' => self::base(), 'name' => 'Psilocybin Research', 'funding' => self::funding()];
    }
    public static function funding(): array
    {
        return ['@type' => 'Grant', '@id' => 'https://cordis.europa.eu/project/id/604764#grant',
            'name' => 'Marie Skłodowska-Curie Actions | CogNovo', 'identifier' => 'FP7-PEOPLE-2013-ITN-604764',
            'url' => 'https://cordis.europa.eu/project/id/604764',
            'description' => 'Historical European Union FP7 support for the psilocybin research infrastructure through the CogNovo Initial Training Network, grant agreement 604764. This acknowledgement does not establish funding of individual indexed publications or current operating grants.',
            'funder' => ['@type' => 'Organization', '@id' => 'https://commission.europa.eu/', 'name' => 'European Commission', 'url' => 'https://commission.europa.eu/']];
    }
    public static function person(string $name, ?string $orcid = null, ?string $openAlex = null): array
    {
        $name = trim(clean_scientific_text($name));
        $url = self::base() . 'authors.php?author=' . rawurlencode($name);
        $node = ['@type' => 'Person', '@id' => $url . '#person', 'name' => $name, 'url' => $url];
        $identities = [];
        if ($orcid && preg_match('/^(?:https:\/\/orcid.org\/)?(\d{4}-\d{4}-\d{4}-\d{3}[\dX])$/', $orcid, $m)) $identities[] = 'https://orcid.org/' . $m[1];
        if ($openAlex && preg_match('~^https://openalex.org/A\d+$~', $openAlex)) $identities[] = $openAlex;
        if ($identities) $node['sameAs'] = $identities;
        return $node;
    }
    public static function vocabulary(): array
    {
        static $vocabulary;
        return $vocabulary ??= require Config::baseDir() . '/config/research-vocabulary.php';
    }
    public static function term(string $kind, string $label): array
    {
        $definition = self::vocabulary()[$kind][$label] ?? null;
        if ($definition === null) return ['@type' => 'Thing', 'name' => $label];
        $url = self::base() . 'linked-data.php?resource=term&kind=' . rawurlencode($kind) . '&term=' . rawurlencode($label);
        $scheme = self::base() . 'linked-data.php?resource=vocabulary#' . $kind;
        return ['@type' => ['DefinedTerm', 'skos:Concept'], '@id' => $url, 'url' => $url, 'name' => $label,
            'description' => $definition, 'termCode' => $label,
            'inDefinedTermSet' => ['@id' => $scheme], 'skos:inScheme' => ['@id' => $scheme],
            'skos:prefLabel' => ['@value' => $label, '@language' => 'en']];
    }
    public static function vocabularyGraph(): array
    {
        $nodes = [];
        foreach (self::vocabulary() as $kind => $terms) {
            $nodes[] = ['@type' => ['DefinedTermSet', 'skos:ConceptScheme'], '@id' => self::base() . 'linked-data.php?resource=vocabulary#' . $kind,
                'name' => 'Tracker ' . $kind . ' discovery vocabulary',
                'description' => 'Local navigation labels, not clinical diagnoses, independent study verification or equivalence mappings to external vocabularies.'];
            foreach ($terms as $label => $_) $nodes[] = self::term($kind, $label);
        }
        return self::document($nodes);
    }
    public static function nct(array $paper): ?string
    {
        return preg_match('/\bNCT\d{8}\b/i', (string)($paper['source_url'] ?? ''), $m) ? strtoupper($m[0]) : null;
    }
    public static function doi(?string $value): ?string
    {
        $doi = normalize_doi($value);
        return $doi && preg_match('~^10\.\d{4,9}/[^\s]+$~u', $doi) ? $doi : null;
    }
    /** Encode characters forbidden in URI references, including legacy DOI angle brackets. */
    public static function uri(string $value): string
    {
        $unsafe = ['<', '>', '"', '{', '}', '|', '\\', '^', '`', ' '];
        $value = str_replace($unsafe, array_map('rawurlencode', $unsafe), $value);
        return preg_replace_callback('/[\x00-\x20\x7F-\xFF]/', static fn(array $m): string => rawurlencode($m[0]), $value) ?? $value;
    }
    public static function recordId(array $paper): string
    {
        if (($paper['publication_status'] ?? '') === 'clinical trial') {
            $nct = self::nct($paper);
            return $nct ? 'https://clinicaltrials.gov/study/' . $nct : self::base() . 'publication.php?id=' . (int)$paper['id'] . '#study';
        }
        $doi = self::doi((string)($paper['doi'] ?? ''));
        return $doi ? self::uri('https://doi.org/' . $doi) : self::base() . 'publication.php?id=' . (int)$paper['id'] . '#work';
    }
    public static function trial(array $trial): array
    {
        $nct = strtoupper((string)$trial['nct_id']);
        $node = ['@type' => ($trial['study_type'] ?? '') === 'OBSERVATIONAL' ? 'MedicalStudy' : 'MedicalTrial',
            '@id' => 'https://clinicaltrials.gov/study/' . $nct, 'name' => (string)$trial['title'],
            'url' => self::base() . 'publication.php?id=' . (int)$trial['id'],
            'identifier' => [['@type' => 'PropertyValue', 'propertyID' => 'ClinicalTrials.gov', 'value' => $nct]],
            'studySubject' => array_map(static fn(string $label): array => ['@id' => self::base() . 'linked-data.php?resource=substance&term=' . rawurlencode($label)], array_values(array_intersect(['psilocybin', 'psilocin'], $trial['substances'] ?? [])))];
        if (!$node['studySubject']) unset($node['studySubject']);
        if (!empty($trial['status_label'])) $node['status'] = (string)$trial['status_label'];
        if (!empty($trial['sponsor'])) $node['sponsor'] = ['name' => (string)$trial['sponsor']];
        if (!empty($trial['conditions'])) $node['healthCondition'] = array_map(static fn(string $v): array => ['@type' => 'MedicalCondition', 'name' => $v], $trial['conditions']);
        $countries = [];
        foreach ($trial['sites'] ?? [] as $site) if (!empty($site['country'])) $countries[$site['country']] = ['@type' => 'Country', 'name' => $site['country']];
        if ($countries) $node['studyLocation'] = array_values($countries);
        return $node;
    }
    public static function record(array $input, array $references = [], array $datasets = [], ?array $trial = null): array
    {
        $paper = public_paper($input);
        $url = self::base() . 'publication.php?id=' . (int)$paper['id'];
        $isTrial = $paper['publication_status'] === 'clinical trial';
        $node = $isTrial && $trial ? self::trial($trial) : ['@type' => $isTrial ? 'MedicalStudy' : 'ScholarlyArticle',
            '@id' => self::recordId($paper), 'name' => (string)$paper['title'], 'url' => $url];
        $node['mainEntityOfPage'] = ['@id' => $url . '#page'];
        $identifiers = $node['identifier'] ?? [];
        foreach (['doi' => 'DOI', 'pubmed_id' => 'PMID', 'openalex_id' => 'OpenAlex'] as $key => $scheme) {
            if (!empty($paper[$key]) && ($key !== 'doi' || self::doi($paper[$key]) !== null) && ($key !== 'pubmed_id' || preg_match('/^\d+$/', $paper[$key]))) $identifiers[] = ['@type' => 'PropertyValue', 'propertyID' => $scheme, 'value' => $key === 'doi' ? self::doi($paper[$key]) : $paper[$key]];
        }
        if ($identifiers) $node['identifier'] = $identifiers;
        $sameAs = $node['sameAs'] ?? [];
        if (!$isTrial && self::doi($paper['doi']) !== null) $sameAs[] = self::uri('https://doi.org/' . self::doi($paper['doi']));
        if (preg_match('/^\d+$/', (string)$paper['pubmed_id'])) $sameAs[] = 'https://pubmed.ncbi.nlm.nih.gov/' . $paper['pubmed_id'] . '/';
        if (preg_match('~^https://openalex.org/W\d+$~', (string)$paper['openalex_id'])) $sameAs[] = $paper['openalex_id'];
        if ($sameAs) $node['sameAs'] = array_values(array_filter(array_unique($sameAs), static fn(string $url): bool => $url !== $node['@id']));
        if (!$isTrial) {
            $node['headline'] = $paper['title'];
            if (parse_date_or_null($paper['publication_date'])) $node['datePublished'] = $paper['publication_date'];
            $node['creativeWorkStatus'] = self::term('status', (string)$paper['publication_status']);
            $authors = split_tag_values((string)$paper['authors']);
            if ($authors) $node['author'] = array_map(static fn(string $v): array => self::person($v), $authors);
            if ($paper['journal'] && PublicationMetadata::venue($paper)['kind'] === 'journal') $node['isPartOf'] = ['@type' => 'Periodical', 'name' => $paper['journal']];
            $about = [];
            foreach (['substance_tags' => 'substance', 'topic_tags' => 'topic'] as $key => $kind) {
                foreach (split_tag_values((string)$paper[$key]) as $v) $about[] = self::term($kind, $v);
            }
            if ($about) $node['about'] = $about;
            if ($paper['study_type']) {
                $node['genre'] = $paper['study_type'];
                $node['about'][] = self::term('study', $paper['study_type']);
            }
            foreach (array_unique($references) as $doi) if (($doi = self::doi($doi)) !== null) $node['citation'][] = ['@id' => self::uri('https://doi.org/' . $doi)];
        }
        // External datasets are linked through their bibliographic facts, never source payloads.
        foreach ($datasets as $data) {
            if (!filter_var($data['dataset_url'] ?? '', FILTER_VALIDATE_URL)) continue;
            $node['dct:relation'][] = array_filter(['@type' => 'Dataset', '@id' => self::uri($data['dataset_url']), 'url' => self::uri($data['dataset_url']),
                'name' => $data['dataset_title'] ?: $data['repository'] . ' dataset', 'license' => $data['license_url'] ?: null]);
        }
        return $node;
    }
    public static function recordGraph(array $paper, array $references = [], array $datasets = [], ?array $trial = null, array $citing = []): array
    {
        $work = self::record($paper, $references, $datasets, $trial);
        $url = self::base() . 'publication.php?id=' . (int)$paper['id'];
        $nodes = [self::website(), ['@type' => 'WebPage', '@id' => $url . '#page', 'url' => $url, 'name' => $paper['title'],
            'mainEntity' => ['@id' => $work['@id']], 'isPartOf' => ['@id' => self::base() . '#website']], $work];
        $safe = public_paper($paper);
        $metadata = ['@type' => ['CreativeWork', 'prov:Entity'], '@id' => $url . '#metadata',
            'name' => 'Tracker metadata for: ' . $safe['title'], 'url' => $url,
            'about' => ['@id' => $work['@id']],
            'isPartOf' => ['@id' => self::base() . '#live-dataset'],
            'publisher' => ['@type' => 'Organization', '@id' => self::base() . '#metadata-publisher', 'name' => 'Psilocybin Research', 'url' => self::base()],
            'description' => $safe['source_provenance']['attribution_scope']];
        if (filter_var($safe['source_url'], FILTER_VALIDATE_URL)) $metadata['prov:wasDerivedFrom'] = ['@id' => self::uri($safe['source_url'])];
        if (parse_date_or_null($safe['date_added'])) $metadata['dateCreated'] = $safe['date_added'];
        if (!empty($safe['last_checked'])) $metadata['additionalProperty'] = [['@type' => 'PropertyValue', 'propertyID' => 'metadata_last_checked', 'name' => 'Source metadata last checked; not a scientific review', 'value' => $safe['last_checked']]];
        $nodes[] = $metadata;
        foreach ($citing as $row) {
            $other = self::record($row);
            if ($other['@type'] === 'ScholarlyArticle') $other['citation'] = [['@id' => $work['@id']]];
            $nodes[] = $other;
        }
        return self::document($nodes);
    }
    public static function dataset(): array
    {
        return ['@type' => 'Dataset', '@id' => self::base() . '#live-dataset',
            'name' => 'An integrated living dataset of psilocybin and psilocin publications, preprints, and trial records',
            'url' => self::base() . 'research-dataset.php', 'identifier' => self::base() . '#live-dataset',
            'description' => 'Living source-linked bibliographic metadata core. Public downloads exclude unverified abstracts, registry descriptions, source keywords and importer payloads. Coverage is not exhaustive.',
            'creator' => self::maintainer(), 'isAccessibleForFree' => true, 'funding' => self::funding(),
            'variableMeasured' => ['title', 'authors', 'journal', 'publication date', 'DOI', 'PubMed ID', 'source database', 'publication status', 'derived topic tags', 'study type', 'substance tags', 'abstract availability', 'text rights status'],
            'isBasedOn' => ['https://pubmed.ncbi.nlm.nih.gov/', 'https://api.crossref.org/', 'https://europepmc.org/', 'https://openalex.org/', 'https://clinicaltrials.gov/'],
            'license' => self::base() . 'data-protection.php#public-data-rights',
            'dct:relation' => ['@id' => 'https://doi.org/10.5281/zenodo.21293525'],
            'distribution' => [
                ['@type' => 'DataDownload', 'encodingFormat' => 'application/vnd.sqlite3', 'contentUrl' => self::base() . 'database.php'],
                ['@type' => 'DataDownload', 'encodingFormat' => 'application/ld+json', 'contentUrl' => self::base() . 'linked-data.php?resource=records'],
                ['@type' => 'DataDownload', 'encodingFormat' => 'application/json', 'contentUrl' => self::base() . 'export.php?format=json&range=all&limit=all'],
                ['@type' => 'DataDownload', 'encodingFormat' => 'text/csv', 'contentUrl' => self::base() . 'export.php?format=csv&range=all&limit=all'],
                ['@type' => 'DataDownload', 'encodingFormat' => 'application/x-bibtex', 'contentUrl' => self::base() . 'export.php?format=bibtex&range=all&limit=all'],
                ['@type' => 'DataDownload', 'encodingFormat' => 'application/x-research-info-systems', 'contentUrl' => self::base() . 'export.php?format=ris&range=all&limit=all'],
            ]];
    }

    /** Consistent shared identities for legacy page metadata and safe JSON-LD output. */
    public static function normalize(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        foreach ($value as $key => $child) $value[$key] = self::normalize($child);
        $type = $value['@type'] ?? null;
        if ($type === 'Person' && !empty($value['name']) && empty($value['@id'])) {
            $sameAs = (array)($value['sameAs'] ?? []);
            $value['@id'] = in_array('https://orcid.org/0000-0002-1573-4651', $sameAs, true)
                ? self::maintainer()['@id'] : self::person($value['name'])['@id'];
        }
        if ($type === 'Person' && ($value['@id'] ?? '') === self::maintainer()['@id']) $value = array_replace($value, self::maintainer());
        if ($type === 'WebSite' && ($value['url'] ?? '') === self::base()) $value['@id'] = self::base() . '#website';
        if (in_array($type, ['WebSite', 'WebApplication'], true) && ($value['url'] ?? '') === self::base()) $value['funding'] = self::funding();
        if ($type === 'CollectionPage' && isset($value['numberOfItems'])) {
            if (!isset($value['mainEntity'])) $value['mainEntity'] = ['@type' => 'ItemList', '@id' => ($value['url'] ?? self::base()) . '#items', 'numberOfItems' => $value['numberOfItems']];
            unset($value['numberOfItems']);
        }
        if (in_array($type, ['WebPage', 'CollectionPage', 'ProfilePage', 'AboutPage', 'PrivacyPolicy'], true) && !empty($value['url']) && empty($value['@id'])) $value['@id'] = $value['url'] . '#page';
        if (array_key_exists('@context', $value)) $value['@context'] = self::context();
        return $value;
    }
}
