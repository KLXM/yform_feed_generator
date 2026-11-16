<?php

namespace KLXM\YFormFeedGenerator;

use rex;
use rex_addon;
use rex_sql;
use Throwable;
use Url\Profile;

/**
 * Anbindung an das URL-AddOn (url_generator) und Hilfen für Media-Manager-Typen.
 */
final class Links
{
    public static function urlAddonAvailable(): bool
    {
        return rex_addon::get('url')->isAvailable() && class_exists(Profile::class);
    }

    /**
     * Profile des URL-AddOns, die zur Tabelle passen.
     *
     * @return array<int, string> id => Bezeichnung
     */
    public static function profiles(string $table): array
    {
        if (!self::urlAddonAvailable()) {
            return [];
        }
        $out = [];
        try {
            foreach (Profile::getAll() as $profile) {
                if ($profile->getTableName() === $table) {
                    $out[(int) $profile->getId()] = $profile->getNamespace() . ' (Artikel ' . $profile->getArticleId() . ')';
                }
            }
        } catch (Throwable) {
        }
        return $out;
    }

    /** Relative oder absolute URL eines Datensatzes über das URL-AddOn, leer wenn nicht auflösbar. */
    public static function forDataset(int $profileId, int $id, int $clangId): string
    {
        if ($profileId < 1 || !self::urlAddonAvailable()) {
            return '';
        }
        try {
            $profile = Profile::get($profileId);
            if (null === $profile) {
                return '';
            }
            $namespace = (string) $profile->getNamespace();
            $url = (string) rex_getUrl('', $clangId, [$namespace => $id], '&');
            // nicht umgeschrieben: das Profil passt nicht zum Datensatz (z. B. durch Einschränkungen)
            if ('' === $url || str_contains($url, $namespace . '=')) {
                return '';
            }
            return $url;
        } catch (Throwable) {
            return '';
        }
    }

    public static function mediaTypeExists(string $type): bool
    {
        static $types = null;
        if (null === $types) {
            $types = array_column(rex_sql::factory()->getArray('SELECT name FROM ' . rex::getTable('media_manager_type')), 'name');
        }
        return in_array($type, $types, true);
    }

    /** @return list<string> */
    public static function mediaTypes(): array
    {
        return array_map('strval', array_column(rex_sql::factory()->getArray('SELECT name FROM ' . rex::getTable('media_manager_type') . ' ORDER BY name'), 'name'));
    }
}
