<?php

namespace KLXM\YFormFeedGenerator;

use DateTimeImmutable;
use DateTimeZone;
use rex;
use rex_media;
use rex_media_manager;
use rex_sql;
use Throwable;
use XMLWriter;

/**
 * Erzeugt RSS 2.0 mit atom:link, content:encoded und Media RSS.
 */
final class Renderer
{
    /** @var array<string, array<int, string>> */
    private array $labelCache = [];

    /** @var array<string, string> */
    private array $userCache = [];

    public function __construct(private Feed $feed) {}

    /**
     * @return array{xml: string, items: int, last: int}
     */
    public function render(): array
    {
        $rows = $this->rows();
        $c = $this->feed->config;
        $tz = new DateTimeZone(date_default_timezone_get());

        $items = [];
        $last = 0;
        foreach ($rows as $row) {
            $item = $this->item($row, $tz);
            if (null !== $item) {
                $items[] = $item;
                $last = max($last, $item['timestamp']);
            }
        }
        if (0 === $last) {
            $last = strtotime($this->feed->updated ?: 'now') ?: time();
        }

        $w = new XMLWriter();
        $w->openMemory();
        $w->setIndent(true);
        $w->setIndentString('  ');
        $w->startDocument('1.0', 'UTF-8');
        $w->startElement('rss');
        $w->writeAttribute('version', '2.0');
        $w->writeAttribute('xmlns:atom', 'http://www.w3.org/2005/Atom');
        $w->writeAttribute('xmlns:content', 'http://purl.org/rss/1.0/modules/content/');
        $w->writeAttribute('xmlns:media', 'http://search.yahoo.com/mrss/');
        $w->writeAttribute('xmlns:dc', 'http://purl.org/dc/elements/1.1/');
        $w->startElement('channel');

        $w->writeElement('title', $this->feed->title);
        $w->writeElement('link', $this->absolute('' !== $c['channel_link'] ? $c['channel_link'] : '/'));
        $w->writeElement('description', '' !== $this->feed->description ? $this->feed->description : $this->feed->title);
        $w->writeElement('language', $c['language']);
        $w->writeElement('lastBuildDate', date(DATE_RSS, $last));
        $w->writeElement('generator', 'REDAXO yform_feed_generator');
        $w->writeElement('ttl', (string) max(1, (int) ceil($this->feed->ttl / 60)));
        $w->startElement('atom:link');
        $w->writeAttribute('href', $this->feed->url(false));
        $w->writeAttribute('rel', 'self');
        $w->writeAttribute('type', 'application/rss+xml');
        $w->endElement();

        foreach ($items as $item) {
            $w->startElement('item');
            $w->writeElement('title', $item['title']);
            $w->writeElement('link', $item['link']);
            $w->startElement('guid');
            $w->writeAttribute('isPermaLink', 'false');
            $w->text($item['guid']);
            $w->endElement();
            $w->writeElement('pubDate', $item['pubDate']);
            if ('' !== $item['description']) {
                $w->startElement('description');
                $w->text($item['description']);
                $w->endElement();
            }
            if ('' !== $item['content']) {
                $w->startElement('content:encoded');
                // "]]>" im Inhalt würde CDATA beenden
                $w->writeCdata(str_replace(']]>', ']]]]><![CDATA[>', $item['content']));
                $w->endElement();
            }
            if ('' !== $item['author']) {
                $w->writeElement('dc:creator', $item['author']);
            }
            foreach ($item['categories'] as $category) {
                $w->writeElement('category', $category);
            }
            if (null !== $item['image']) {
                $img = $item['image'];
                $w->startElement('enclosure');
                $w->writeAttribute('url', $img['url']);
                $w->writeAttribute('length', (string) $img['length']);
                $w->writeAttribute('type', $img['type']);
                $w->endElement();

                $w->startElement('media:content');
                $w->writeAttribute('url', $img['url']);
                $w->writeAttribute('medium', 'image');
                $w->writeAttribute('type', $img['type']);
                if ($img['width'] > 0) {
                    $w->writeAttribute('width', (string) $img['width']);
                    $w->writeAttribute('height', (string) $img['height']);
                }
                if ('' !== $item['image_alt']) {
                    $w->startElement('media:description');
                    $w->writeAttribute('type', 'plain');
                    $w->text($item['image_alt']);
                    $w->endElement();
                }
                $w->endElement();

                if (null !== $item['thumb']) {
                    $w->startElement('media:thumbnail');
                    $w->writeAttribute('url', $item['thumb']['url']);
                    if ($item['thumb']['width'] > 0) {
                        $w->writeAttribute('width', (string) $item['thumb']['width']);
                        $w->writeAttribute('height', (string) $item['thumb']['height']);
                    }
                    $w->endElement();
                }
            }
            $w->endElement();
        }

        $w->endElement();
        $w->endElement();
        $w->endDocument();

        return ['xml' => $w->outputMemory(), 'items' => count($items), 'last' => $last];
    }

    /** @return list<array<string, mixed>> */
    public function rows(?int $limit = null): array
    {
        $q = (new QueryBuilder($this->feed))->build($limit);
        return rex_sql::factory()->getArray($q['query'], $q['params']);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{title: string, link: string, guid: string, pubDate: string, timestamp: int, description: string, content: string, author: string, categories: list<string>, image: array{url: string, type: string, length: int, width: int, height: int}|null, thumb: array{url: string, width: int, height: int}|null, image_alt: string}|null
     */
    private function item(array $row, DateTimeZone $tz): ?array
    {
        $map = $this->feed->config['map'];
        $title = self::plain((string) ($row[$map['title']] ?? ''));
        if ('' === $title) {
            return null;
        }
        $id = (int) $row['id'];
        $timestamp = $this->timestamp($row[$map['date']] ?? null, $map['date'], $tz);

        $description = '' !== $map['description'] ? self::plain((string) ($row[$map['description']] ?? '')) : '';
        if ('' === $description && '' !== $map['description_fallback']) {
            $description = self::plain((string) ($row[$map['description_fallback']] ?? ''));
        }
        $description = self::shorten($description, (int) $this->feed->config['excerpt_length']);

        $content = '' !== $map['content'] ? trim((string) ($row[$map['content']] ?? '')) : '';

        $image = null;
        $thumb = null;
        if ('' !== $map['image']) {
            [$image, $thumb] = $this->image((string) ($row[$map['image']] ?? ''));
        }

        return [
            'title' => $title,
            'link' => $this->link($row, $id),
            'guid' => $this->feed->table . ':' . $id . '@' . (parse_url(Delivery::baseUrl(), PHP_URL_HOST) ?: 'localhost'),
            'pubDate' => date(DATE_RSS, $timestamp),
            'timestamp' => $timestamp,
            'description' => $description,
            'content' => $content,
            'author' => '' !== $map['author'] ? implode(', ', $this->values($map['author'], $row, $id)) : '',
            'categories' => '' !== $map['category'] ? $this->values($map['category'], $row, $id) : [],
            'image' => $image,
            'thumb' => $thumb,
            'image_alt' => '' !== $map['image_alt'] ? self::plain((string) ($row[$map['image_alt']] ?? '')) : '',
        ];
    }

    private function timestamp(mixed $value, string $field, DateTimeZone $tz): int
    {
        if ('int' === Schema::dateKind($this->feed->table, $field)) {
            return max(0, (int) $value);
        }
        $value = trim((string) $value);
        if ('' === $value || str_starts_with($value, '0000')) {
            return 0;
        }
        try {
            return (new DateTimeImmutable($value, $tz))->getTimestamp();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Werte eines Feldes als Text: Relationen werden in die Anzeigewerte der Zieltabelle aufgelöst,
     * be_user in den Benutzernamen.
     *
     * @param array<string, mixed> $row
     * @return list<string>
     */
    private function values(string $field, array $row, int $id): array
    {
        $info = Schema::field($this->feed->table, $field);
        if (null === $info) {
            return [];
        }
        if (null !== $info['relation']) {
            if (!$info['relation']['supported']) {
                return [];
            }
            if ('relation_table' === $info['relation']['via']) {
                $rel = Schema::relationTableColumns($this->feed->table, $field);
                if (null === $rel) {
                    return [];
                }
                $sql = rex_sql::factory();
                $ids = array_map(static fn (array $r): int => (int) $r['t'], $sql->getArray(
                    'SELECT ' . $sql->escapeIdentifier($rel['target']) . ' AS t FROM ' . $sql->escapeIdentifier($rel['table'])
                    . ' WHERE ' . $sql->escapeIdentifier($rel['source']) . ' = ?',
                    [$id],
                ));
            } else {
                $ids = array_values(array_filter(array_map('intval', explode(',', (string) ($row[$field] ?? '')))));
            }
            $labels = $this->labelsFor($info['relation']['table'], $info['relation']['label_fields']);
            $out = [];
            foreach ($ids as $relId) {
                if (isset($labels[$relId])) {
                    $out[] = $labels[$relId];
                }
            }
            return $out;
        }
        $value = self::plain((string) ($row[$field] ?? ''));
        if ('' === $value) {
            return [];
        }
        if ('be_user' === $info['type']) {
            return [$this->userName($value)];
        }
        return [$value];
    }

    /**
     * @param list<string> $labelFields
     * @return array<int, string>
     */
    private function labelsFor(string $table, array $labelFields): array
    {
        $key = $table . '|' . implode(',', $labelFields);
        return $this->labelCache[$key] ??= Schema::labels($table, $labelFields, null, 5000);
    }

    private function userName(string $login): string
    {
        if (!isset($this->userCache[$login])) {
            $rows = rex_sql::factory()->getArray('SELECT name FROM ' . rex::getTable('user') . ' WHERE login = ?', [$login]);
            $this->userCache[$login] = trim((string) ($rows[0]['name'] ?? '')) ?: $login;
        }
        return $this->userCache[$login];
    }

    /** @param array<string, mixed> $row */
    private function link(array $row, int $id): string
    {
        $c = $this->feed->config;
        $external = '' !== $c['map']['link'] ? trim((string) ($row[$c['map']['link']] ?? '')) : '';
        if (1 === preg_match('~^https?://~i', $external)) {
            return $external;
        }
        $url = Links::forDataset((int) $c['url_profile'], $id, (int) $c['clang_id']);
        if ('' !== $url) {
            return $this->absolute($url);
        }
        if ('' !== $c['link_fallback']) {
            return $this->absolute(str_replace('{id}', (string) $id, $c['link_fallback']));
        }
        return $this->absolute('' !== $c['channel_link'] ? $c['channel_link'] : '/');
    }

    /**
     * @return array{0: array{url: string, type: string, length: int, width: int, height: int}|null, 1: array{url: string, width: int, height: int}|null}
     */
    private function image(string $value): array
    {
        $file = trim(explode(',', $value)[0]);
        if ('' === $file || null === rex_media::get($file) || !rex_media::get($file)->isImage()) {
            return [null, null];
        }
        $media = rex_media::get($file);
        $main = $this->managed($this->feed->config['media_type'], $media);
        if (null === $main) {
            return [null, null];
        }
        $thumb = '' !== $this->feed->config['thumb_type'] ? $this->managed($this->feed->config['thumb_type'], $media) : null;
        return [$main, null !== $thumb ? ['url' => $thumb['url'], 'width' => $thumb['width'], 'height' => $thumb['height']] : null];
    }

    /**
     * @return array{url: string, type: string, length: int, width: int, height: int}|null
     */
    private function managed(string $type, rex_media $media): ?array
    {
        if (!Links::mediaTypeExists($type)) {
            return null;
        }
        try {
            $manager = rex_media_manager::create($type, $media->getFileName());
            $managed = $manager->getMedia();
            $cacheFile = $manager->getCacheFilename();
            $length = is_file($cacheFile) ? (int) filesize($cacheFile) : (int) $media->getSize();
            $format = strtolower((string) $managed->getFormat());
            $mime = match ($format) {
                'jpg', 'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
                'webp' => 'image/webp',
                'avif' => 'image/avif',
                default => (string) $media->getType(),
            };
            return [
                'url' => $this->absolute(rex_media_manager::getUrl($type, $media->getFileName(), $media->getUpdateDate(), false)),
                'type' => $mime,
                'length' => $length,
                'width' => (int) $managed->getWidth(),
                'height' => (int) $managed->getHeight(),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    private function absolute(string $url): string
    {
        if (1 === preg_match('~^https?://~i', $url)) {
            return $url;
        }
        $base = Delivery::baseUrl();
        if (str_starts_with($url, '//')) {
            return (parse_url($base, PHP_URL_SCHEME) ?: 'https') . ':' . $url;
        }
        $origin = (string) preg_replace('~^(https?://[^/]+).*$~i', '$1', $base);
        return str_starts_with($url, '/') ? $origin . $url : $base . ltrim($url, './');
    }

    public static function plain(string $html): string
    {
        $text = (string) preg_replace('~<(br|/p|/div|/h[1-6]|/li)\b[^>]*>~i', ' ', $html);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);
        return trim((string) preg_replace('/[\p{Cc}\s]+/u', ' ', $text));
    }

    private static function shorten(string $text, int $length): string
    {
        if ($length < 1 || mb_strlen($text) <= $length) {
            return $text;
        }
        $cut = mb_substr($text, 0, $length);
        $space = mb_strrpos($cut, ' ');
        if (false !== $space && $space > $length * 0.6) {
            $cut = mb_substr($cut, 0, $space);
        }
        return rtrim($cut, ' ,;:.-–') . '…';
    }
}
