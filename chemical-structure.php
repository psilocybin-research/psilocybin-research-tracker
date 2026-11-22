<?php
declare(strict_types=1);
require_once __DIR__ . '/src/helpers.php';

ResearchModelPage::render([
    'title' => 'Chemical Structure Explorer',
    'description' => 'A planned compound explorer connecting psilocybin, psilocin, related structures, chemical identifiers, stereochemistry, transformations, and research records.',
    'path' => 'chemical-structure.php',
    'current' => 'chemical-structure',
    'kind' => 'chemical',
    'icon' => 'molecule',
    'eyebrow' => 'Chemical identity without ambiguity',
    'about' => 'Psilocybin and psilocin chemistry',
    'capabilities' => [
        ['icon' => 'molecule', 'title' => 'Structure-centered browsing', 'description' => 'Inspect compounds, salts, stereochemical forms, and relationships through a clear interactive structure view.'],
        ['icon' => 'braces', 'title' => 'Resolvable identifiers', 'description' => 'Connect names to validated identifiers such as InChIKey, canonical SMILES, and trusted registry records.'],
        ['icon' => 'network', 'title' => 'Compound-to-evidence links', 'description' => 'Move from a chemical entity to receptor, metabolism, trial, and publication contexts without losing provenance.'],
    ],
    'inputs' => ['Curated compound identities and synonyms', 'Validated public chemical identifiers', 'Transformation and metabolite relationships'],
    'outputs' => ['Interactive 2D structure views', 'Identifier and synonym crosswalks', 'Evidence-linked compound profiles'],
]);
