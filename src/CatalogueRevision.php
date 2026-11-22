<?php
declare(strict_types=1);

/** Opaque identity shared by live aggregates and published dataset snapshots. */
final class CatalogueRevision
{
    public static function read(Database $db): string
    {
        $pdo = $db->pdo();
        $databases = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? $pdo->query('PRAGMA database_list')->fetchAll() : [];
        $file = (string)($databases[0]['file'] ?? '');
        $identity = $file !== '' && is_file($file) ? $file . ':' . fileinode($file) : 'connection:' . spl_object_id($pdo);
        $revision = (string)$pdo->query('SELECT revision FROM app_cache_revision WHERE id=1')->fetchColumn();
        return hash('sha256', $identity . ':' . $revision);
    }
}
