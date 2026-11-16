<?php

namespace KLXM\YFormFeedGenerator;

use rex_sql;

/**
 * Baut die Abfrage für einen Feed. Spaltennamen kommen ausschließlich aus Schema (geprüft),
 * Werte werden als Parameter übergeben.
 */
final class QueryBuilder
{
    private rex_sql $sql;

    /** @var list<scalar> */
    private array $params = [];

    public function __construct(private Feed $feed)
    {
        $this->sql = rex_sql::factory();
    }

    private function col(string $column): string
    {
        return 't.' . $this->sql->escapeIdentifier($column);
    }

    /**
     * @return array{query: string, params: list<scalar>, columns: list<string>}
     */
    public function build(?int $limit = null): array
    {
        $table = $this->feed->table;
        $c = $this->feed->config;
        $this->params = [];

        // nur gemappte Spalten lesen
        $columns = ['id'];
        foreach ($c['map'] as $field) {
            if ('' !== $field && Schema::hasColumn($table, $field)) {
                $columns[] = $field;
            }
        }
        $columns = array_values(array_unique($columns));

        $where = [];

        if ('' !== $c['status_field'] && Schema::hasColumn($table, $c['status_field'])) {
            $where[] = $this->col($c['status_field']) . ' = ?';
            $this->params[] = $c['status_value'];
        }

        $date = $c['map']['date'];
        if ($c['hide_future'] && Schema::hasColumn($table, $date)) {
            $where[] = $this->notAfterNow($date);
        }
        if ('' !== $c['start_field'] && Schema::hasColumn($table, $c['start_field'])) {
            $where[] = '(' . $this->isEmptyDate($c['start_field']) . ' OR ' . $this->notAfterNow($c['start_field']) . ')';
        }
        if ('' !== $c['end_field'] && Schema::hasColumn($table, $c['end_field'])) {
            $end = '(' . $this->isEmptyDate($c['end_field']) . ' OR ' . $this->col($c['end_field']) . ' >= ?)';
            $endParams = [$this->nowValue($c['end_field'])];
            if ('' !== $c['end_cond_field'] && Schema::hasColumn($table, $c['end_cond_field'])) {
                // Ende gilt nur, wenn das Bedingungsfeld den Wert hat (z. B. Timer = 1)
                $end = '(COALESCE(' . $this->col($c['end_cond_field']) . ", '') <> ? OR " . $end . ')';
                array_unshift($endParams, $c['end_cond_value']);
            }
            $where[] = $end;
            array_push($this->params, ...$endParams);
        }

        foreach ($c['filters'] as $filter) {
            $condition = $this->filter($filter);
            if (null !== $condition) {
                $where[] = $condition;
            }
        }

        $query = 'SELECT ' . implode(', ', array_map(fn (string $col): string => $this->col($col), $columns))
            . ' FROM ' . $this->sql->escapeIdentifier($table) . ' t'
            . ([] !== $where ? ' WHERE ' . implode(' AND ', $where) : '')
            . (Schema::hasColumn($table, $date) ? ' ORDER BY ' . $this->col($date) . ' DESC, t.id DESC' : ' ORDER BY t.id DESC')
            . ' LIMIT ' . ($limit ?? (int) $c['limit']);

        return ['query' => $query, 'params' => $this->params, 'columns' => $columns];
    }

    private function nowValue(string $column): string|int
    {
        return match (Schema::dateKind($this->feed->table, $column)) {
            'date' => date('Y-m-d'),
            'int' => time(),
            default => date('Y-m-d H:i:s'),
        };
    }

    private function notAfterNow(string $column): string
    {
        $this->params[] = $this->nowValue($column);
        return $this->col($column) . ' <= ?';
    }

    private function isEmptyDate(string $column): string
    {
        $col = $this->col($column);
        return match (Schema::dateKind($this->feed->table, $column)) {
            'date', 'datetime' => '(' . $col . ' IS NULL OR ' . $col . " < '1000-01-01')",
            'int' => '(' . $col . ' IS NULL OR ' . $col . ' = 0)',
            default => '(' . $col . ' IS NULL OR ' . $col . " = '')",
        };
    }

    /**
     * @param array{field: string, op: string, value: string, values: list<int>} $filter
     */
    private function filter(array $filter): ?string
    {
        $table = $this->feed->table;
        $info = Schema::field($table, $filter['field']);
        if (null === $info) {
            return null;
        }
        $op = $filter['op'];
        $hasColumn = Schema::hasColumn($table, $filter['field']);

        if (in_array($op, ['eq', 'neq', 'empty', 'notempty'], true)) {
            if (!$hasColumn) {
                return null;
            }
            $col = $this->col($filter['field']);
            switch ($op) {
                case 'eq':
                    $this->params[] = $filter['value'];
                    return $col . ' = ?';
                case 'neq':
                    $this->params[] = $filter['value'];
                    return '(' . $col . ' IS NULL OR ' . $col . ' <> ?)';
                case 'empty':
                    return '(' . $col . " IS NULL OR CAST(" . $col . " AS CHAR) IN ('', '0'))";
                default:
                    return '(' . $col . " IS NOT NULL AND CAST(" . $col . " AS CHAR) NOT IN ('', '0'))";
            }
        }

        // enthält einen/alle/keinen von
        $values = null !== $info['relation']
            ? array_map('strval', $filter['values'])
            : array_values(array_filter(array_map('trim', explode(',', $filter['value'])), static fn (string $v): bool => '' !== $v));
        if ([] === $values) {
            return null;
        }

        $parts = [];
        if (null !== $info['relation'] && 'relation_table' === $info['relation']['via']) {
            $rel = Schema::relationTableColumns($table, $filter['field']);
            if (null === $rel) {
                return '1 = 0';
            }
            foreach ($values as $v) {
                $this->params[] = (int) $v;
                $parts[] = 'EXISTS (SELECT 1 FROM ' . $this->sql->escapeIdentifier($rel['table']) . ' r WHERE r.'
                    . $this->sql->escapeIdentifier($rel['source']) . ' = t.id AND r.' . $this->sql->escapeIdentifier($rel['target']) . ' = ?)';
            }
        } elseif ($hasColumn) {
            // Spalte enthält eine ID oder eine kommagetrennte Liste
            $col = 'REPLACE(COALESCE(' . $this->col($filter['field']) . ", ''), ' ', '')";
            foreach ($values as $v) {
                $this->params[] = $v;
                $parts[] = 'FIND_IN_SET(?, ' . $col . ') > 0';
            }
        } else {
            return null;
        }

        return match ($op) {
            'all' => '(' . implode(' AND ', $parts) . ')',
            'none' => 'NOT (' . implode(' OR ', $parts) . ')',
            default => '(' . implode(' OR ', $parts) . ')',
        };
    }
}
