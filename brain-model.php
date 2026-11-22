<?php
declare(strict_types=1);
require_once __DIR__ . '/src/helpers.php';

ResearchModelPage::render([
    'title' => 'Interactive Brain Model',
    'description' => 'A planned evidence-linked brain explorer for examining regions, networks, modalities, tasks, and reported effects across psilocybin and psilocin studies.',
    'path' => 'brain-model.php',
    'current' => 'brain-model',
    'kind' => 'brain',
    'icon' => 'brain',
    'eyebrow' => 'From publications to brain systems',
    'about' => 'Psilocybin neuroimaging and neurophysiology',
    'capabilities' => [
        ['icon' => 'brain', 'title' => 'Anatomy and networks', 'description' => 'Navigate brain regions and large-scale networks while keeping anatomical labels and study context visible.'],
        ['icon' => 'list-filter', 'title' => 'Modality-aware filtering', 'description' => 'Separate fMRI, PET, EEG, MEG, connectivity, perfusion, and other measurement families.'],
        ['icon' => 'book-open', 'title' => 'Evidence-linked observations', 'description' => 'Trace every displayed observation back to indexed publications, cohorts, tasks, and reported contrasts.'],
    ],
    'inputs' => ['Rights-safe bibliographic records', 'Curated brain-region and network labels', 'Study modality, task, cohort, and dose context'],
    'outputs' => ['Interactive spatial evidence view', 'Study-level source trails', 'Exportable, filter-aware summaries'],
]);
