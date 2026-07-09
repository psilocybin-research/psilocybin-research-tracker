#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/helpers.php';

$db = new Database();
$db->initialize();
$pdo = $db->pdo();
$stmt = $pdo->query('SELECT id, title, abstract, keywords FROM publications');
$update = $pdo->prepare('UPDATE publications SET topic_tags = :topics, study_type = :study_type WHERE id = :id');
$count = 0;
foreach ($stmt->fetchAll() as $row) {
    $text = (string)$row['title'] . ' ' . (string)$row['abstract'] . ' ' . (string)$row['keywords'];
    $update->execute([
        'topics' => PublicationRepository::classifyTopics($text),
        'study_type' => PublicationRepository::classifyStudyType($text),
        'id' => (int)$row['id'],
    ]);
    $count++;
}
echo "Classified $count publications\n";
