<?php
declare(strict_types=1);

/** Local application profile checks; intentionally not a claim of full ontology validation. */
final class LinkedDataValidator
{
    public static function issues(array $document): array
    {
        $issues = [];
        $url = static fn(mixed $v): bool => is_string($v) && preg_match('~^https?://~', $v) && filter_var($v, FILTER_VALIDATE_URL);
        $add = static function (string $message) use (&$issues): void { $issues[] = ['severity' => 'critical', 'code' => 'semantic_model', 'message' => $message]; };
        $walk = static function (mixed $node) use (&$walk, $url, $add): void {
            if (!is_array($node)) return;
            if (isset($node['@id']) && (!$url($node['@id']) || LinkedData::uri($node['@id']) !== $node['@id'])) $add('Entity @id must be an absolute HTTP(S) URI with unsafe characters encoded');
            foreach ((array)($node['sameAs'] ?? []) as $identity) if (!$url($identity)) $add('sameAs must contain absolute reference URLs');
            $types = (array)($node['@type'] ?? []);
            if (in_array('ScholarlyArticle', $types, true)) {
                if (empty($node['@id']) || empty($node['name'])) $add('ScholarlyArticle requires a stable @id and name');
                if (str_starts_with((string)($node['@id'] ?? ''), 'https://clinicaltrials.gov/study/')) $add('A registry study must not be typed as ScholarlyArticle');
            }
            if (array_intersect($types, ['MedicalTrial', 'MedicalStudy'])) {
                if (empty($node['@id']) || empty($node['name'])) $add('Registry study requires a stable @id and name');
                foreach (['author', 'datePublished', 'creativeWorkStatus', 'citation'] as $key) if (isset($node[$key])) $add('Registry study must not carry article property ' . $key);
            }
            if (in_array('DefinedTerm', $types, true)) {
                if (empty($node['@id']) || empty($node['termCode']) || empty($node['inDefinedTermSet']['@id'])) $add('DefinedTerm requires identity, code and term-set relationship');
            }
            if (in_array('PropertyValue', $types, true) && (empty($node['propertyID']) || !isset($node['value']) || trim((string)$node['value']) === '')) $add('Identifier requires propertyID and value');
            if (isset($node['datePublished']) && parse_date_or_null((string)$node['datePublished']) === null) $add('datePublished must be a valid calendar date');
            if (($node['@id'] ?? '') === LinkedData::base() . '#live-dataset') {
                foreach ((array)($node['sameAs'] ?? []) as $same) if (str_contains($same, 'zenodo')) $add('The living dataset must not be identical to a fixed Zenodo snapshot');
            }
            foreach (['abstract', 'keywords', 'raw_json', 'abstract_redistributed'] as $forbidden) if (array_key_exists($forbidden, $node)) $add('Forbidden source-text or payload field in public graph: ' . $forbidden);
            foreach ($node as $key => $child) if ($key !== '@context') $walk($child);
        };
        $walk($document);
        return array_values(array_unique($issues, SORT_REGULAR));
    }
}
