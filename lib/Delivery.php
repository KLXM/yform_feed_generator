<?php

namespace KLXM\YFormFeedGenerator;

use rex;
use rex_addon;
use rex_file;
use rex_path;
use rex_response;
use rex_yrewrite;
use Throwable;

/**
 * Öffentliche Ausgabe eines Feeds: Datei-Cache mit TTL, ETag/304, optionaler Token.
 * Andere Abfrageparameter als "token" werden ignoriert, damit der Cache nicht umgangen werden kann.
 */
final class Delivery
{
    public static function baseUrl(): string
    {
        if (rex_addon::get('yrewrite')->isAvailable()) {
            try {
                $domain = rex_yrewrite::getCurrentDomain() ?? rex_yrewrite::getDefaultDomain();
                if (null !== $domain) {
                    return rtrim($domain->getUrl(), '/') . '/';
                }
            } catch (Throwable) {
            }
        }
        return rtrim(rex::getServer(), '/') . '/';
    }

    /** Erkennt /<prefix>/<key>.xml und liefert den Schlüssel. */
    public static function keyFromPath(string $uri): ?string
    {
        $path = (string) parse_url($uri, PHP_URL_PATH);
        $prefix = preg_quote(trim((string) rex_addon::get('yform_feed_generator')->getConfig('path_prefix', 'rss'), '/'), '~');
        if (1 === preg_match('~/' . $prefix . '/([a-z0-9][a-z0-9_-]{0,63})\.xml$~', $path, $m)) {
            return $m[1];
        }
        return null;
    }

    private static function cacheFile(string $key): string
    {
        return rex_path::addonCache('yform_feed_generator', 'feed_' . $key . '.xml');
    }

    public static function clearCache(?string $key = null): void
    {
        if (null !== $key) {
            rex_file::delete(self::cacheFile($key));
            rex_file::delete(self::cacheFile($key) . '.json');
            return;
        }
        foreach (glob(rex_path::addonCache('yform_feed_generator', 'feed_*')) ?: [] as $file) {
            rex_file::delete($file);
        }
    }

    /** Leert den Cache aller Feeds, die auf eine Tabelle zugreifen. */
    public static function clearCacheForTable(string $table): void
    {
        foreach (Feed::all() as $feed) {
            if ($feed->table === $table) {
                self::clearCache($feed->key);
            }
        }
    }

    /**
     * Liefert XML aus dem Cache oder baut neu.
     *
     * @return array{xml: string, etag: string, last: int}
     */
    public static function get(Feed $feed): array
    {
        $file = self::cacheFile($feed->key);
        $metaFile = $file . '.json';
        $meta = is_file($metaFile) ? json_decode((string) rex_file::get($metaFile), true) : null;
        $fresh = is_array($meta) && is_file($file) && (time() - (int) ($meta['built'] ?? 0)) < max(1, $feed->ttl)
            && ($meta['version'] ?? '') === $feed->updated;
        if ($fresh) {
            return ['xml' => (string) rex_file::get($file), 'etag' => (string) $meta['etag'], 'last' => (int) $meta['last']];
        }

        // Sperre: nur ein Prozess baut neu, andere bekommen ggf. die alte Fassung
        rex_file::put($file . '.lock', '');
        $lock = fopen($file . '.lock', 'c');
        $locked = false !== $lock && flock($lock, LOCK_EX | LOCK_NB);
        if (!$locked && is_array($meta) && is_file($file)) {
            return ['xml' => (string) rex_file::get($file), 'etag' => (string) $meta['etag'], 'last' => (int) $meta['last']];
        }
        if (!$locked && false !== $lock) {
            flock($lock, LOCK_EX);
        }

        try {
            $result = (new Renderer($feed))->render();
            $etag = sha1($result['xml']);
            rex_file::put($file, $result['xml']);
            rex_file::put($metaFile, (string) json_encode(['built' => time(), 'etag' => $etag, 'last' => $result['last'], 'version' => $feed->updated]));
            return ['xml' => $result['xml'], 'etag' => $etag, 'last' => $result['last']];
        } finally {
            if (false !== $lock) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /** Beendet den Request mit der Feed-Ausgabe (oder 404/403/304). */
    public static function serve(string $key): never
    {
        rex_response::cleanOutputBuffers();
        $feed = Feed::getByKey($key);

        if (null === $feed || !$feed->active || !Schema::isTable($feed->table)) {
            self::plain(rex_response::HTTP_NOT_FOUND, 'Feed nicht gefunden.');
        }
        if ('' !== $feed->token) {
            $given = (string) ($_GET['token'] ?? '');
            if (!hash_equals($feed->token, $given)) {
                self::plain(rex_response::HTTP_FORBIDDEN, 'Zugriff verweigert.');
            }
        }

        try {
            $data = self::get($feed);
        } catch (Throwable $e) {
            \rex_logger::logException($e);
            self::plain(rex_response::HTTP_INTERNAL_ERROR, 'Feed konnte nicht erzeugt werden.');
        }

        $etag = '"' . $data['etag'] . '"';
        $ttl = max(0, $feed->ttl);
        // Der Feed braucht keine Sitzung; ein von anderen AddOns gesetztes Cookie würde Proxy-Caches ausschalten
        header_remove('Set-Cookie');
        header('Content-Type: application/rss+xml; charset=utf-8');
        header('ETag: ' . $etag);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $data['last']) . ' GMT');
        header('Cache-Control: ' . ('' !== $feed->token ? 'private' : 'public') . ', max-age=' . $ttl);
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex');

        $ifNoneMatch = (string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
        $ifModifiedSince = strtotime((string) ($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '')) ?: 0;
        $notModified = '' !== $ifNoneMatch
            ? in_array($etag, array_map(static fn (string $e): string => trim(str_replace('W/', '', $e)), explode(',', $ifNoneMatch)), true)
            : $ifModifiedSince > 0 && $data['last'] <= $ifModifiedSince;
        if ($notModified) {
            http_response_code(304);
            exit;
        }

        http_response_code(200);
        header('Content-Length: ' . strlen($data['xml']));
        if ('HEAD' !== ($_SERVER['REQUEST_METHOD'] ?? 'GET')) {
            echo $data['xml'];
        }
        exit;
    }

    private static function plain(string $status, string $message): never
    {
        header('HTTP/1.1 ' . $status);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex');
        echo $message;
        exit;
    }
}
