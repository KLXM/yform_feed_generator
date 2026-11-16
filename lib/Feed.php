<?php

namespace KLXM\YFormFeedGenerator;

use rex;
use rex_addon;
use rex_sql;

/**
 * Ein Feed: Quelle (YForm-Tabelle), Feld-Mapping, Filter, Ausgabe-Optionen.
 */
final class Feed
{
    public const OPERATORS = [
        'eq' => 'ist gleich',
        'neq' => 'ist nicht gleich',
        'empty' => 'ist leer',
        'notempty' => 'ist nicht leer',
        'any' => 'enthält einen von',
        'all' => 'enthält alle von',
        'none' => 'enthält keinen von',
    ];

    /** Mapping-Schlüssel => [Bezeichnung, Pflicht] */
    public const MAP = [
        'title' => ['Titel', true],
        'date' => ['Datum (Veröffentlichung)', true],
        'description' => ['Anriss / Beschreibung', false],
        'description_fallback' => ['Ersatz für Anriss, falls leer (z. B. Volltext)', false],
        'content' => ['Volltext (content:encoded, HTML)', false],
        'image' => ['Bild (Medienpool-Feld)', false],
        'image_alt' => ['Bildbeschreibung / Alt-Text', false],
        'author' => ['Autor', false],
        'category' => ['Kategorie', false],
        'link' => ['Externe URL (hat Vorrang vor dem URL-AddOn)', false],
    ];

    /** @param array<string, mixed> $config */
    public function __construct(
        public readonly int $id,
        public readonly string $key,
        public readonly string $title,
        public readonly string $description,
        public readonly string $table,
        public readonly array $config,
        public readonly string $token,
        public readonly int $ttl,
        public readonly bool $active,
        public readonly string $updated,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $config = json_decode((string) ($row['config'] ?? ''), true);
        return new self(
            (int) $row['id'],
            (string) $row['feed_key'],
            (string) $row['title'],
            (string) ($row['description'] ?? ''),
            (string) $row['table_name'],
            self::normalize(is_array($config) ? $config : []),
            (string) $row['token'],
            max(0, (int) $row['cache_ttl']),
            1 === (int) $row['status'],
            (string) $row['updatedate'],
        );
    }

    public static function get(int $id): ?self
    {
        $rows = rex_sql::factory()->getArray('SELECT * FROM ' . rex::getTable('yform_feed') . ' WHERE id = ?', [$id]);
        return isset($rows[0]) ? self::fromRow($rows[0]) : null;
    }

    public static function getByKey(string $key): ?self
    {
        if (!self::isValidKey($key)) {
            return null;
        }
        $rows = rex_sql::factory()->getArray('SELECT * FROM ' . rex::getTable('yform_feed') . ' WHERE feed_key = ?', [$key]);
        return isset($rows[0]) ? self::fromRow($rows[0]) : null;
    }

    /** @return list<self> */
    public static function all(): array
    {
        $rows = rex_sql::factory()->getArray('SELECT * FROM ' . rex::getTable('yform_feed') . ' ORDER BY title');
        return array_map(self::fromRow(...), $rows);
    }

    public static function isValidKey(string $key): bool
    {
        return 1 === preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $key);
    }

    /**
     * Bringt eine (aus dem Formular oder der DB stammende) Konfiguration in eine definierte Form.
     *
     * @param array<string, mixed> $c
     * @return array<string, mixed>
     */
    public static function normalize(array $c): array
    {
        $map = [];
        foreach (array_keys(self::MAP) as $k) {
            $map[$k] = trim((string) (($c['map'] ?? [])[$k] ?? ''));
        }
        $filters = [];
        foreach ((array) ($c['filters'] ?? []) as $f) {
            if (!is_array($f) || '' === trim((string) ($f['field'] ?? ''))) {
                continue;
            }
            $op = (string) ($f['op'] ?? 'eq');
            $values = array_values(array_unique(array_filter(array_map('intval', (array) ($f['values'] ?? [])), static fn (int $v): bool => $v > 0)));
            $filters[] = [
                'field' => trim((string) $f['field']),
                'op' => isset(self::OPERATORS[$op]) ? $op : 'eq',
                'value' => (string) ($f['value'] ?? ''),
                'values' => $values,
            ];
        }
        return [
            'map' => $map,
            'status_field' => trim((string) ($c['status_field'] ?? '')),
            'status_value' => (string) ($c['status_value'] ?? '1'),
            'hide_future' => (bool) ($c['hide_future'] ?? true),
            'start_field' => trim((string) ($c['start_field'] ?? '')),
            'end_field' => trim((string) ($c['end_field'] ?? '')),
            'end_cond_field' => trim((string) ($c['end_cond_field'] ?? '')),
            'end_cond_value' => (string) ($c['end_cond_value'] ?? '1'),
            'filters' => $filters,
            'limit' => max(1, min(500, (int) ($c['limit'] ?? 20))),
            'excerpt_length' => max(0, min(5000, (int) ($c['excerpt_length'] ?? 0))),
            'url_profile' => max(0, (int) ($c['url_profile'] ?? 0)),
            'link_fallback' => trim((string) ($c['link_fallback'] ?? '')),
            'media_type' => trim((string) ($c['media_type'] ?? 'yform_feed_image')),
            'thumb_type' => trim((string) ($c['thumb_type'] ?? 'yform_feed_thumb')),
            'channel_link' => trim((string) ($c['channel_link'] ?? '')),
            'language' => trim((string) ($c['language'] ?? 'de')) ?: 'de',
            'clang_id' => max(1, (int) ($c['clang_id'] ?? 1)),
        ];
    }

    /**
     * Prüft die Konfiguration gegen die Tabellenstruktur.
     *
     * @param array<string, mixed> $c normalisierte Konfiguration
     * @return list<string> Fehlermeldungen
     */
    public static function validate(string $table, array $c): array
    {
        $errors = [];
        if (!Schema::isTable($table)) {
            return ['Bitte eine YForm-Tabelle wählen.'];
        }
        $check = static function (string $field, string $label) use ($table, &$errors): void {
            if ('' !== $field && null === Schema::field($table, $field)) {
                $errors[] = $label . ': Feld „' . $field . '“ gibt es in der Tabelle nicht.';
            }
        };
        foreach (self::MAP as $k => [$label, $required]) {
            $field = (string) $c['map'][$k];
            if ($required && '' === $field) {
                $errors[] = $label . ' ist ein Pflichtfeld.';
            }
            $check($field, $label);
        }
        foreach (['date' => 'Datum', 'start_field' => 'Veröffentlichung ab', 'end_field' => 'Veröffentlichung bis'] as $k => $label) {
            $field = 'date' === $k ? (string) $c['map']['date'] : (string) $c[$k];
            if ('' !== $field && !Schema::hasColumn($table, $field)) {
                $errors[] = $label . ': „' . $field . '“ ist keine Spalte der Tabelle.';
            }
        }
        $check((string) $c['status_field'], 'Status-Feld');
        $check((string) $c['end_cond_field'], 'Bedingung für Ende');
        if ('' !== (string) $c['end_cond_field'] && '' === (string) $c['end_field']) {
            $errors[] = 'Eine Bedingung für das Ende braucht ein Ende-Feld.';
        }
        foreach (['category' => 'Kategorie', 'author' => 'Autor'] as $k => $label) {
            $info = '' !== $c['map'][$k] ? Schema::field($table, (string) $c['map'][$k]) : null;
            if (null !== $info && null !== $info['relation'] && !$info['relation']['supported']) {
                $errors[] = $label . ': Diese Relationsart (1:n inline) wird nicht unterstützt.';
            }
        }
        foreach ($c['filters'] as $i => $f) {
            $info = Schema::field($table, $f['field']);
            $n = $i + 1;
            if (null === $info) {
                $errors[] = 'Filter ' . $n . ': Feld „' . $f['field'] . '“ gibt es nicht.';
                continue;
            }
            if (in_array($f['op'], ['any', 'all', 'none'], true)) {
                if (null !== $info['relation'] && !$info['relation']['supported']) {
                    $errors[] = 'Filter ' . $n . ': Diese Relationsart (1:n inline) wird nicht unterstützt.';
                }
                if ([] === $f['values'] && null !== $info['relation']) {
                    $errors[] = 'Filter ' . $n . ': Bitte mindestens einen Wert auswählen.';
                }
                if (null === $info['relation'] && '' === trim($f['value'])) {
                    $errors[] = 'Filter ' . $n . ': Bitte Werte (kommagetrennt) angeben.';
                }
            } elseif ('' === $info['db_type']) {
                $errors[] = 'Filter ' . $n . ': „' . $f['field'] . '“ hat keine eigene Spalte, bitte „enthält …“ verwenden.';
            }
        }
        if ('' !== (string) $c['link_fallback'] && 1 !== preg_match('~^(https?://|/)~', (string) $c['link_fallback'])) {
            $errors[] = 'Ersatz-Link muss mit http(s):// oder / beginnen.';
        }
        return $errors;
    }

    public function url(bool $withToken = true): string
    {
        $prefix = trim((string) rex_addon::get('yform_feed_generator')->getConfig('path_prefix', 'rss'), '/');
        $url = Delivery::baseUrl() . $prefix . '/' . $this->key . '.xml';
        if ($withToken && '' !== $this->token) {
            $url .= '?token=' . rawurlencode($this->token);
        }
        return $url;
    }
}
