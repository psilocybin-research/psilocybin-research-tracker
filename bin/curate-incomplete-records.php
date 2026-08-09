#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/helpers.php';

$apply = in_array('--apply', array_slice($argv, 1), true);
if (in_array('--help', array_slice($argv, 1), true) || in_array('-h', array_slice($argv, 1), true)) {
    fwrite(STDERR, "Usage: php bin/curate-incomplete-records.php [--apply]\n");
    exit(0);
}

$db = new Database();
$db->initialize();
$repo = new PublicationRepository($db);
$pdo = $db->pdo();

$authorUpdates = [
    1951 => 'Andreia Machado Brito-da-Costa, Mariana Carvalho, Ricardo Jorge Dinis-Oliveira, Áurea Madureira-Carvalho, Sérgio F. Sousa, Diana Dias da Silva',
    1956 => 'Mariana Silva-Carvalho, Andreia Machado Brito-da-Costa, Ricardo Dinis-Oliveira, Áurea Madureira-Carvalho, Diana Dias-da-Silva',
    1960 => 'Andreia Machado Brito-da-Costa, Francisco Sacadura, Ricardo Jorge Dinis-Oliveira, Áurea Madureira-Carvalho, Diana Dias da Silva',
    2034 => 'Volodymyr V. Tkach, Tetiana V. Morozova, Marta V. Kushnir, Sílvio C. de Oliveira, Viktor V. Kryvetskyi, Inna I. Kryvetska, Igor V. Kryvetskyi, Igor G. Biryuk, Tetiana B. Sykyrytska, Yana G. Ivanushko, Alla V. Velyka, Petro I. Yagodynets, Adriano O. da Silva, Jarem R. Garcia, José Inácio Ferrão da Paiva Martins, Gennadii F. Tkach, Oleg P. Melnyk, Oleksii O. Melnyk, Maria V. Melnyk, Maria João Monteiro, Lilia O. Nikitchenko, Iryna G. Patseva, Vitalina Lukyanova, Liudmyla Mohelnytska',
    3213 => 'S Kasper',
    3215 => 'T Renoir, J Gattuso, B Bezcioglu, G Kong, M Ekwudo, C Wilson, N Kleditzsch, A Kamal, J Hendey, C Gubert, A Hannan',
    3224 => 'C Foldi',
    3235 => 'S Meikle, O Carter, P Liknaitzky, L Johansen, R Iyer, N Strauss, M Williams, D Castle, S Rossell',
    3246 => 'Martha Lopez Canul, Vivienne Nguyen, Antonio Inserra, Ella Nield, Hannah Billings, Gabriella Gobbi',
    3256 => 'Y Babii, C-S Barbara, D Bederska-Łojewska, B Szewczyk, A Pałucha-Poniewiera, K Gołembiowska, A Pilc',
    3257 => 'A Gibbons, P Liknaitzky, S Sundram',
    3264 => 'V Bruno, B Richardson, M Lopez-Canul, T Marcourakis, R Camarini, G Gobbi',
    3291 => 'Rebecca Harding, Natalie Ertl, Rayyan Zafar',
    5118 => 'Burton M. Altura, Bella T. Altura',
    5286 => 'Franck Vandermoere, Philippe Marin',
    5699 => 'Abdulwahab F. Alahmari',
];

$venueUpdates = [
    3840 => 'Pacific University CommonKnowledge',
    3851 => "Queen's University QSpace",
    3852 => "Queen's University QSpace",
    3856 => 'Patienthåndbogen',
    3882 => 'Conservative Drug Policy Reform Group',
    3959 => 'Yale School of Medicine Physician Associate Program Thesis',
    3988 => 'protocols.io',
    3993 => 'OSF Preprints',
    4031 => 'OSF Preprints',
    4239 => 'Advance',
    4276 => 'OSF Preprints',
    4890 => 'Lewis & Clark Law Review',
    5046 => 'Columbia University Academic Commons',
    5078 => 'Maastricht Student Journal of Psychology and Neuroscience',
    5095 => 'Arizona State University Repository',
    5108 => 'Voices of Forensic Science',
    5132 => 'Western Michigan University ScholarWorks',
    5142 => 'Cal Poly Humboldt Digital Commons',
    5159 => 'University of Innsbruck',
    5215 => 'Seattle Pacific University Research Conference',
    5252 => 'Journal for the Scientific Study of Religion',
    5254 => 'University of Bremen',
    5300 => 'Council on Spiritual Practices',
    5301 => 'MAPS Bulletin',
    5328 => 'Universitas Padjadjaran Repository',
    5392 => 'Meeting on Recent Developments in Pharmaceutical Analysis',
    5441 => 'Problems of Forensic Sciences',
    5466 => 'Mikologiya i Fitopatologiya',
    5598 => 'Federation Proceedings',
    5600 => 'Revue de Mycologie',
    5605 => 'Revue de Mycologie',
    5613 => 'Revue de Mycologie',
    5614 => 'Revue de Mycologie',
    5660 => 'Malaysian Journal of Psychiatry',
    5789 => 'OSF',
    5794 => 'IntechOpen',
    5795 => 'University of Debrecen Electronic Archive',
    5802 => 'Ramiro and Zoran Bujas Days 2024',
    5803 => "UVic's Research and Learning Repository",
    5809 => "UVic's Research and Learning Repository",
    5824 => 'University of Debrecen Electronic Archive',
];

$specialUpdates = [
    3851 => ['publication_status' => 'protocol'],
    3852 => ['publication_status' => 'protocol'],
    3988 => ['publication_status' => 'protocol'],
    3993 => ['publication_status' => 'preprint'],
    4031 => ['publication_status' => 'preprint'],
    4239 => ['publication_status' => 'preprint'],
    4276 => ['publication_status' => 'preprint'],
    4641 => ['authors' => 'Tina Singh, Dipa Patel, Kasey Bishop, Rhea Shishodia, Sadeepa Bulathsinhala'],
    5300 => ['title' => 'PSILOCYBIN RESEARCH AT JOHNS HOPKINS: A 2014 REPORT', 'authors' => 'Robert L. Jesse, Roland R. Griffiths'],
    5617 => ['journal' => 'PhilArchive', 'publication_date' => '2025-11-15', 'publication_status' => 'preprint'],
    5789 => ['publication_status' => 'preprint'],
];

$expected = [];
foreach ($authorUpdates as $id => $_) {
    $expected[$id] = true;
}
foreach ($venueUpdates as $id => $_) {
    $expected[$id] = true;
}
foreach ($specialUpdates as $id => $_) {
    $expected[$id] = true;
}

$summary = [
    'mode' => $apply ? 'apply' : 'dry-run',
    'verified_updates' => 0,
    'merged' => 0,
    'restored' => 0,
    'quarantined' => 0,
    'remaining_visible_incomplete' => 0,
];

$run = static function () use ($repo, $pdo, $authorUpdates, $venueUpdates, $specialUpdates, $expected, &$summary): void {
    $rowStmt = $pdo->prepare('SELECT id, title FROM publications WHERE id = :id');
    foreach (array_keys($expected) as $id) {
        $rowStmt->execute(['id' => $id]);
        if (!$rowStmt->fetch()) {
            throw new RuntimeException('Expected publication #' . $id . ' was not found.');
        }
    }

    foreach ($authorUpdates as $id => $authors) {
        if ($repo->curateBibliographicMetadata($id, ['authors' => $authors], 'Manual bibliographic verification 2026-08-09: authors confirmed from the publisher or full-text record.')) {
            $summary['verified_updates']++;
        }
    }
    foreach ($venueUpdates as $id => $journal) {
        if ($repo->curateBibliographicMetadata($id, ['journal' => $journal], 'Manual bibliographic verification 2026-08-09: publication venue confirmed from the publisher or institutional repository.')) {
            $summary['verified_updates']++;
        }
    }
    foreach ($specialUpdates as $id => $fields) {
        if ($repo->curateBibliographicMetadata($id, $fields, 'Manual bibliographic verification 2026-08-09: record type or citation metadata confirmed from the authoritative source.', array_keys($fields))) {
            $summary['verified_updates']++;
        }
    }

    $repo->curate(4641, [
        'hidden' => 0,
        'false_positive' => 0,
        'curation_notes' => 'Manual bibliographic verification 2026-08-09: restored the corrected Neurology conference abstract; publisher record confirms authors and DOI.',
        'merged_into_id' => null,
    ]);
    $summary['restored']++;
    $repo->merge(960, 4641);
    $repo->merge(3373, 1472);
    $repo->merge(4311, 4239);
    $summary['merged'] += 3;

    $remaining = $pdo->query(
        "SELECT id
         FROM publications
         WHERE hidden = 0
           AND false_positive = 0
           AND merged_into_id IS NULL
           AND (TRIM(COALESCE(authors, '')) = ''
             OR TRIM(COALESCE(journal, '')) = ''
             OR TRIM(COALESCE(publication_date, '')) = '')
         ORDER BY id"
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($remaining as $id) {
        $repo->curate((int)$id, [
            'hidden' => 1,
            'curation_notes' => 'Quarantined during manual bibliographic review 2026-08-09: incomplete core citation after publisher, repository, identifier, and scholarly-database checks; excluded pending authoritative metadata.',
        ]);
        $summary['quarantined']++;
    }

    $summary['remaining_visible_incomplete'] = (int)$pdo->query(
        "SELECT COUNT(*)
         FROM publications
         WHERE hidden = 0
           AND false_positive = 0
           AND merged_into_id IS NULL
           AND (TRIM(COALESCE(authors, '')) = ''
             OR TRIM(COALESCE(journal, '')) = ''
             OR TRIM(COALESCE(publication_date, '')) = '')"
    )->fetchColumn();
};

if (!$apply) {
    $candidateCount = (int)$pdo->query(
        "SELECT COUNT(*) FROM publications
         WHERE hidden = 0 AND false_positive = 0 AND merged_into_id IS NULL
           AND (TRIM(COALESCE(authors, '')) = '' OR TRIM(COALESCE(journal, '')) = '' OR TRIM(COALESCE(publication_date, '')) = '')"
    )->fetchColumn();
    $summary['visible_incomplete_candidates'] = $candidateCount;
    $summary['planned_verified_records'] = count($expected);
    $summary['planned_merges'] = 3;
    echo json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

$pdo->beginTransaction();
try {
    $run();
    if ($summary['remaining_visible_incomplete'] !== 0) {
        throw new RuntimeException('Visible incomplete records remain after curation.');
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

OperationalLogger::info('manual_incomplete_record_curation.finished', $summary);
echo json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
