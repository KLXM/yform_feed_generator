<?php

namespace KLXM\YFormFeedGenerator;

use rex_sql;
use rex_yform_manager_field;
use rex_yform_manager_table;
use Throwable;

/**
 * Liest Struktur aus YForm: Tabellen, Felder, Spaltentypen und Relationen.
 * Alle Spalten- und Tabellennamen, die später in SQL landen, werden hier gegen die echte Struktur geprüft.
 */
final class Schema
{
    /** Feldtypen, die keine eigene Spalte haben bzw. nicht sinnvoll mappbar sind */
    private const SKIP_TYPES = ['html', 'fieldset', 'submit', 'php', 'resetbutton', 'objparams', 'tab', 'validate', 'action'];

    /** @var array<string, array<string, string>> */
    private static array $columns = [];

    /** @return array<string, string> table_name => Bezeichnung */
    public static function tables(): array
    {
        $tables = [];
        foreach (rex_yform_manager_table::getAll() as $table) {
            $tables[$table->getTableName()] = $table->getNameLocalized() . ' [' . $table->getTableName() . ']';
        }
        asort($tables, SORT_NATURAL | SORT_FLAG_CASE);
        return $tables;
    }

    public static function isTable(string $table): bool
    {
        return '' !== $table && null !== rex_yform_manager_table::get($table);
    }

    /** @return array<string, string> Spalte => DB-Typ (klein) */
    public static function columns(string $table): array
    {
        if (!isset(self::$columns[$table])) {
            $cols = [];
            try {
                foreach (rex_sql::showColumns($table) as $col) {
                    $cols[(string) $col['name']] = strtolower((string) $col['type']);
                }
            } catch (Throwable) {
            }
            self::$columns[$table] = $cols;
        }
        return self::$columns[$table];
    }

    public static function hasColumn(string $table, string $column): bool
    {
        return '' !== $column && isset(self::columns($table)[$column]);
    }

    /** Art der Datumsspalte: date, datetime, int, string */
    public static function dateKind(string $table, string $column): string
    {
        $type = self::columns($table)[$column] ?? '';
        if (str_starts_with($type, 'datetime') || str_starts_with($type, 'timestamp')) {
            return 'datetime';
        }
        if (str_starts_with($type, 'date')) {
            return 'date';
        }
        if (preg_match('/^(tiny|small|medium|big)?int/', $type)) {
            return 'int';
        }
        return 'string';
    }

    /**
     * Felder einer Tabelle für das Mapping.
     *
     * @return list<array{name: string, label: string, type: string, db_type: string, relation: array{table: string, label_fields: list<string>, multiple: bool, via: string, supported: bool}|null}>
     */
    public static function fields(string $table): array
    {
        $yTable = rex_yform_manager_table::get($table);
        if (null === $yTable) {
            return [];
        }
        $columns = self::columns($table);
        $fields = [['name' => 'id', 'label' => 'ID', 'type' => 'id', 'db_type' => $columns['id'] ?? 'int', 'relation' => null]];

        foreach ($yTable->getValueFields() as $field) {
            $type = (string) $field->getTypeName();
            if (in_array($type, self::SKIP_TYPES, true)) {
                continue;
            }
            $name = (string) $field->getName();
            $relation = 'be_manager_relation' === $type ? self::relationInfo($table, $field) : null;
            // ohne Spalte nur Relationen über Zwischentabelle zulassen
            if (!isset($columns[$name]) && (null === $relation || 'relation_table' !== $relation['via'])) {
                continue;
            }
            $fields[] = [
                'name' => $name,
                'label' => (string) ($field->getLabel() ?: $name),
                'type' => $type,
                'db_type' => $columns[$name] ?? '',
                'relation' => $relation,
            ];
        }
        return $fields;
    }

    /** @return array{name: string, label: string, type: string, db_type: string, relation: array{table: string, label_fields: list<string>, multiple: bool, via: string, supported: bool}|null}|null */
    public static function field(string $table, string $name): ?array
    {
        foreach (self::fields($table) as $field) {
            if ($field['name'] === $name) {
                return $field;
            }
        }
        return null;
    }

    /**
     * @return array{table: string, label_fields: list<string>, multiple: bool, via: string, supported: bool}
     */
    private static function relationInfo(string $table, rex_yform_manager_field $field): array
    {
        $target = (string) $field->getElement('table');
        $relationType = (int) $field->getElement('type');
        $relationTable = (string) $field->getElement('relation_table');
        $labelFields = array_values(array_filter(array_map('trim', explode(',', (string) $field->getElement('field'))), static fn (string $f): bool => self::hasColumn($target, $f)));

        if ('' !== $relationTable) {
            $via = 'relation_table';
        } elseif (in_array($relationType, [4, 5], true)) {
            $via = 'inline';
        } else {
            $via = 'column';
        }

        return [
            'table' => $target,
            'label_fields' => $labelFields,
            'multiple' => in_array($relationType, [1, 3], true) || 'relation_table' === $via,
            'via' => $via,
            // 1:n-Inline-Relationen (Typ 4/5) liegen in der Zieltabelle und sind als Filter/Kategorie nicht sinnvoll
            'supported' => 'inline' !== $via && self::isTable($target),
        ];
    }

    /**
     * Spalten der Zwischentabelle (Quelle/Ziel) einer n:m-Relation.
     *
     * @return array{table: string, source: string, target: string}|null
     */
    public static function relationTableColumns(string $table, string $field): ?array
    {
        $yTable = rex_yform_manager_table::get($table);
        $relation = $yTable?->getRelation($field);
        if (null === $yTable || null === $relation || '' === (string) $relation->getElement('relation_table')) {
            return null;
        }
        try {
            $cols = $yTable->getRelationTableColumns($field);
        } catch (Throwable) {
            return null;
        }
        $relTable = (string) $relation->getElement('relation_table');
        if (!self::hasColumn($relTable, (string) $cols['source']) || !self::hasColumn($relTable, (string) $cols['target'])) {
            return null;
        }
        return ['table' => $relTable, 'source' => (string) $cols['source'], 'target' => (string) $cols['target']];
    }

    /**
     * Auswahlwerte der Zieltabelle einer Relation (für Filter im Backend).
     *
     * @return list<array{id: int, label: string}>
     */
    public static function relationOptions(string $table, string $field, int $limit = 1000): array
    {
        $info = self::field($table, $field);
        if (null === $info || null === $info['relation'] || !$info['relation']['supported']) {
            return [];
        }
        $target = $info['relation']['table'];
        $labels = self::labels($target, $info['relation']['label_fields'], null, $limit);
        $out = [];
        foreach ($labels as $id => $label) {
            $out[] = ['id' => $id, 'label' => $label];
        }
        usort($out, static fn (array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));
        return $out;
    }

    /**
     * Anzeigetexte von Datensätzen einer Tabelle.
     *
     * @param list<string> $labelFields
     * @param list<int>|null $ids
     * @return array<int, string>
     */
    public static function labels(string $table, array $labelFields, ?array $ids = null, int $limit = 1000): array
    {
        if (!self::isTable($table) || [] === $labelFields || [] === $ids) {
            return [];
        }
        $sql = rex_sql::factory();
        $select = implode(', ', array_map(static fn (string $f): string => $sql->escapeIdentifier($f), $labelFields));
        $query = 'SELECT id, ' . $select . ' FROM ' . $sql->escapeIdentifier($table);
        $params = [];
        if (null !== $ids) {
            $query .= ' WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            $params = $ids;
        }
        $query .= ' LIMIT ' . $limit;
        $labels = [];
        foreach ($sql->getArray($query, $params) as $row) {
            $parts = [];
            foreach ($labelFields as $f) {
                $v = trim(strip_tags((string) ($row[$f] ?? '')));
                if ('' !== $v) {
                    $parts[] = $v;
                }
            }
            $labels[(int) $row['id']] = [] !== $parts ? implode(' ', $parts) : '#' . $row['id'];
        }
        return $labels;
    }
}
