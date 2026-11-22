<?php
declare(strict_types=1);
require_once __DIR__ . '/src/helpers.php';

ResearchModelPage::render([
    'title' => 'Receptor Binding Profile',
    'description' => 'A planned evidence-first comparison of psilocin and related psychedelic receptor targets, assay contexts, affinity measures, and confidence boundaries.',
    'path' => 'receptor-binding.php',
    'current' => 'receptor-binding',
    'kind' => 'receptor',
    'icon' => 'target',
    'eyebrow' => 'Targets with measurement context',
    'about' => 'Psychedelic receptor pharmacology',
    'capabilities' => [
        ['icon' => 'target', 'title' => 'Target comparison', 'description' => 'Compare serotonergic and other reported targets without reducing distinct assays to one misleading rank.'],
        ['icon' => 'settings', 'title' => 'Assay context', 'description' => 'Keep species, tissue or cell system, ligand, endpoint, and measurement units attached to each value.'],
        ['icon' => 'circle-alert', 'title' => 'Confidence and gaps', 'description' => 'Distinguish directly measured evidence, transformed values, heterogeneous estimates, and missing data.'],
    ],
    'inputs' => ['Peer-reviewed pharmacology sources', 'Target and assay identifiers', 'Affinity, potency, efficacy, and uncertainty metadata'],
    'outputs' => ['Context-preserving target matrix', 'Comparable evidence views where justified', 'Direct links to supporting records'],
]);
