<?php

use KLXM\YFormFeedGenerator\Api\Backend;
use KLXM\YFormFeedGenerator\Delivery;
use KLXM\YFormFeedGenerator\Feed;
use KLXM\YFormFeedGenerator\Links;
use KLXM\YFormFeedGenerator\Renderer;
use KLXM\YFormFeedGenerator\Schema;

$func = rex_request('func', 'string');
$id = rex_request('id', 'int');
$table = rex::getTable('yform_feed');
$csrf = rex_csrf_token::factory('yform_feed_generator_edit');

if ('delete' === $func) {
    if ($csrf->isValid() && $feed = Feed::get($id)) {
        Delivery::clearCache($feed->key);
        rex_sql::factory()->setQuery('DELETE FROM ' . $table . ' WHERE id = ?', [$id]);
        echo rex_view::success('Feed gelöscht.');
    } else {
        echo rex_view::error(rex_i18n::msg('csrf_token_invalid'));
    }
    $func = '';
}

if ('clear' === $func) {
    if ($csrf->isValid()) {
        Delivery::clearCache();
        echo rex_view::success('Cache aller Feeds geleert.');
    }
    $func = '';
}

/**
 * @param list<array{name: string, label: string, type: string, db_type: string, relation: array<string, mixed>|null}> $fields
 */
$fieldSelect = static function (string $name, string $selected, array $fields, string $id, bool $empty = true, string $emptyLabel = '– nicht verwenden –'): string {
    $html = '<select class="form-control yfg-field" name="' . rex_escape($name) . '" id="' . rex_escape($id) . '" data-selected="' . rex_escape($selected) . '">';
    if ($empty) {
        $html .= '<option value="">' . rex_escape($emptyLabel) . '</option>';
    }
    $found = false;
    foreach ($fields as $f) {
        $sel = $f['name'] === $selected;
        $found = $found || $sel;
        $html .= '<option value="' . rex_escape($f['name']) . '"' . ($sel ? ' selected' : '') . '>' . rex_escape($f['label'] . ' [' . $f['name'] . ', ' . $f['type'] . ']') . '</option>';
    }
    if (!$found && '' !== $selected) {
        $html .= '<option value="' . rex_escape($selected) . '" selected>' . rex_escape($selected) . ' (fehlt)</option>';
    }
    return $html . '</select>';
};

if ('add' === $func || 'edit' === $func) {
    $feed = 'edit' === $func ? Feed::get($id) : null;
    $errors = [];

    $values = [
        'title' => $feed->title ?? '',
        'feed_key' => $feed->key ?? '',
        'description' => $feed->description ?? '',
        'table_name' => $feed->table ?? '',
        'status' => $feed->active ?? true,
        'cache_ttl' => $feed->ttl ?? (int) rex_addon::get('yform_feed_generator')->getConfig('default_ttl', 600),
        'token' => $feed->token ?? '',
    ];
    $config = $feed->config ?? Feed::normalize([]);

    if ('1' === rex_post('save', 'string')) {
        $post = rex_post('feed', 'array', []);
        $values['title'] = trim((string) ($post['title'] ?? ''));
        $values['feed_key'] = strtolower(trim((string) ($post['feed_key'] ?? '')));
        $values['description'] = trim((string) ($post['description'] ?? ''));
        $values['table_name'] = (string) ($post['table_name'] ?? '');
        $values['status'] = !empty($post['status']);
        $values['cache_ttl'] = max(0, min(86400, (int) ($post['cache_ttl'] ?? 600)));
        $tokenMode = (string) ($post['token_mode'] ?? 'keep');
        if ('new' === $tokenMode) {
            $values['token'] = bin2hex(random_bytes(16));
        } elseif ('none' === $tokenMode) {
            $values['token'] = '';
        }
        $rawConfig = (array) ($post['config'] ?? []);
        $rawConfig['filters'] = array_values((array) ($rawConfig['filters'] ?? []));
        $rawConfig['hide_future'] = !empty($rawConfig['hide_future']);
        $config = Feed::normalize($rawConfig);

        if (!$csrf->isValid()) {
            $errors[] = rex_i18n::msg('csrf_token_invalid');
        }
        if ('' === $values['title']) {
            $errors[] = 'Bitte einen Titel angeben.';
        }
        if (!Feed::isValidKey($values['feed_key'])) {
            $errors[] = 'Der Schlüssel darf nur Kleinbuchstaben, Ziffern, - und _ enthalten (max. 64 Zeichen).';
        } else {
            $dupe = rex_sql::factory()->getArray('SELECT id FROM ' . $table . ' WHERE feed_key = ? AND id <> ?', [$values['feed_key'], $id]);
            if ([] !== $dupe) {
                $errors[] = 'Der Schlüssel wird bereits von einem anderen Feed verwendet.';
            }
        }
        $errors = array_merge($errors, Feed::validate($values['table_name'], $config));

        if ([] === $errors) {
            $sql = rex_sql::factory();
            $sql->setTable($table);
            $sql->setValue('title', $values['title']);
            $sql->setValue('feed_key', $values['feed_key']);
            $sql->setValue('description', $values['description']);
            $sql->setValue('table_name', $values['table_name']);
            $sql->setValue('config', json_encode($config, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $sql->setValue('token', $values['token']);
            $sql->setValue('cache_ttl', $values['cache_ttl']);
            $sql->setValue('status', $values['status'] ? 1 : 0);
            $sql->addGlobalUpdateFields();
            if ($feed) {
                $sql->setWhere(['id' => $id]);
                $sql->update();
                Delivery::clearCache($feed->key);
            } else {
                $sql->addGlobalCreateFields();
                $sql->insert();
                $id = (int) $sql->getLastId();
            }
            Delivery::clearCache($values['feed_key']);
            echo rex_view::success('Feed gespeichert.');
            $feed = Feed::get($id);
            $func = 'edit';
        } else {
            echo rex_view::error('<ul><li>' . implode('</li><li>', array_map('rex_escape', $errors)) . '</li></ul>');
        }
    }

    $fields = Schema::isTable($values['table_name']) ? Schema::fields($values['table_name']) : [];
    $profiles = Schema::isTable($values['table_name']) ? Links::profiles($values['table_name']) : [];
    $apiUrl = rex_url::backendController(['page' => 'yform_feed_generator/feeds'] + Backend::getUrlParams(), false);

    $content = '<form method="post" action="' . rex_url::currentBackendPage(['func' => $func, 'id' => $id]) . '" class="yfg-form" data-api="' . rex_escape($apiUrl) . '">';
    $content .= $csrf->getHiddenField() . '<input type="hidden" name="save" value="1">';

    // Allgemein
    $content .= '<fieldset><legend>Allgemein</legend>';
    $content .= '<div class="row"><div class="col-sm-6 form-group"><label for="yfg-title">Titel des Feeds</label><input class="form-control" id="yfg-title" name="feed[title]" value="' . rex_escape($values['title']) . '" required></div>';
    $content .= '<div class="col-sm-3 form-group"><label for="yfg-key">Schlüssel (URL)</label><input class="form-control" id="yfg-key" name="feed[feed_key]" value="' . rex_escape($values['feed_key']) . '" pattern="[a-z0-9][a-z0-9_\-]{0,63}" required aria-describedby="yfg-key-help"><p class="help-block" id="yfg-key-help">Ergibt /' . rex_escape((string) rex_addon::get('yform_feed_generator')->getConfig('path_prefix', 'rss')) . '/<em>schluessel</em>.xml</p></div>';
    $content .= '<div class="col-sm-3 form-group"><label for="yfg-ttl">Cache (Sekunden)</label><input class="form-control" type="number" min="0" max="86400" id="yfg-ttl" name="feed[cache_ttl]" value="' . (int) $values['cache_ttl'] . '"></div></div>';
    $content .= '<div class="form-group"><label for="yfg-desc">Beschreibung</label><input class="form-control" id="yfg-desc" name="feed[description]" value="' . rex_escape($values['description']) . '"></div>';
    $content .= '<div class="row"><div class="col-sm-6 form-group"><label for="yfg-table">YForm-Tabelle</label><select class="form-control" id="yfg-table" name="feed[table_name]" required><option value="">– bitte wählen –</option>';
    foreach (Schema::tables() as $t => $label) {
        $content .= '<option value="' . rex_escape($t) . '"' . ($t === $values['table_name'] ? ' selected' : '') . '>' . rex_escape($label) . '</option>';
    }
    $content .= '</select></div>';
    $content .= '<div class="col-sm-3 form-group"><label for="yfg-token">Zugriffs-Token</label><select class="form-control" id="yfg-token" name="feed[token_mode]" aria-describedby="yfg-token-help"><option value="keep">' . ('' !== $values['token'] ? 'beibehalten' : 'keiner (öffentlich)') . '</option><option value="new">neuen Token erzeugen</option>' . ('' !== $values['token'] ? '<option value="none">Token entfernen (öffentlich)</option>' : '') . '</select><p class="help-block" id="yfg-token-help">Mit Token ist der Feed nur über ?token=… abrufbar.</p></div>';
    $content .= '<div class="col-sm-3 form-group"><div class="checkbox" style="margin-top:28px"><label><input type="checkbox" name="feed[status]" value="1"' . ($values['status'] ? ' checked' : '') . '> Feed aktiv</label></div></div></div>';
    $content .= '<p class="yfg-loading" role="status" aria-live="polite"></p>';
    $content .= '</fieldset>';

    // Mapping
    $content .= '<fieldset><legend>Felder zuordnen</legend><div class="row">';
    foreach (Feed::MAP as $k => [$label, $required]) {
        $content .= '<div class="col-sm-6 form-group"><label for="yfg-map-' . $k . '">' . rex_escape($label) . ($required ? ' *' : '') . '</label>'
            . $fieldSelect('feed[config][map][' . $k . ']', (string) $config['map'][$k], $fields, 'yfg-map-' . $k, true, $required ? '– bitte wählen –' : '– nicht verwenden –') . '</div>';
    }
    $content .= '</div><p class="help-block">Kategorie und Autor dürfen Relationsfelder sein (auch mit Mehrfachauswahl); ausgegeben werden die Anzeigewerte der Zieltabelle.</p></fieldset>';

    // Veröffentlichung
    $content .= '<fieldset><legend>Nur veröffentlichte Einträge</legend><div class="row">';
    $content .= '<div class="col-sm-6 form-group"><label for="yfg-status-field">Status-Feld</label>' . $fieldSelect('feed[config][status_field]', (string) $config['status_field'], $fields, 'yfg-status-field') . '</div>';
    $content .= '<div class="col-sm-6 form-group"><label for="yfg-status-value">Wert für „veröffentlicht“</label><input class="form-control" id="yfg-status-value" name="feed[config][status_value]" value="' . rex_escape((string) $config['status_value']) . '"></div>';
    $content .= '<div class="col-sm-12 form-group"><div class="checkbox"><label><input type="checkbox" name="feed[config][hide_future]" value="1"' . ($config['hide_future'] ? ' checked' : '') . '> Einträge mit Datum in der Zukunft ausblenden</label></div></div>';
    $content .= '<div class="col-sm-6 form-group"><label for="yfg-start">Veröffentlichung ab (optional)</label>' . $fieldSelect('feed[config][start_field]', (string) $config['start_field'], $fields, 'yfg-start') . '</div>';
    $content .= '<div class="col-sm-6 form-group"><label for="yfg-end">Veröffentlichung bis (optional)</label>' . $fieldSelect('feed[config][end_field]', (string) $config['end_field'], $fields, 'yfg-end') . '</div>';
    $content .= '<div class="col-sm-6 form-group"><label for="yfg-endcond">„Bis“ gilt nur, wenn Feld …</label>' . $fieldSelect('feed[config][end_cond_field]', (string) $config['end_cond_field'], $fields, 'yfg-endcond') . '<p class="help-block">z. B. ein Timer-Schalter</p></div>';
    $content .= '<div class="col-sm-6 form-group"><label for="yfg-endcond-value">… diesen Wert hat</label><input class="form-control" id="yfg-endcond-value" name="feed[config][end_cond_value]" value="' . rex_escape((string) $config['end_cond_value']) . '"></div>';
    $content .= '</div><p class="help-block">Leere Datumswerte (leer, 0, 0000-00-00) gelten als „keine Einschränkung“. Unterstützt werden Datum, Datum mit Uhrzeit und Unix-Zeitstempel.</p></fieldset>';

    // Filter
    $content .= '<fieldset><legend>Filter (optional)</legend><p class="help-block" id="yfg-filter-help">Alle Bedingungen müssen zutreffen. Beispiel: „Kategorie enthält einen von: Fair Play“ – auch Einträge mit mehreren Kategorien werden gefunden.</p>';
    $content .= '<table class="table yfg-filters" aria-describedby="yfg-filter-help"><thead><tr><th scope="col">Feld</th><th scope="col">Bedingung</th><th scope="col">Wert(e)</th><th scope="col"><span class="sr-only">Aktion</span></th></tr></thead><tbody>';
    $filterRows = $config['filters'];
    $renderFilter = static function (int|string $i, array $f) use ($fields, $fieldSelect, $values): string {
        $ops = '';
        foreach (Feed::OPERATORS as $op => $label) {
            $ops .= '<option value="' . $op . '"' . ($op === $f['op'] ? ' selected' : '') . '>' . rex_escape($label) . '</option>';
        }
        $checks = '';
        $info = '' !== $f['field'] && Schema::isTable($values['table_name']) ? Schema::field($values['table_name'], $f['field']) : null;
        $isRelation = null !== $info && null !== $info['relation'];
        if ($isRelation) {
            foreach (Schema::relationOptions($values['table_name'], $f['field']) as $o) {
                $checks .= '<label class="yfg-check"><input type="checkbox" name="feed[config][filters][' . $i . '][values][]" value="' . $o['id'] . '"' . (in_array($o['id'], $f['values'], true) ? ' checked' : '') . '> ' . rex_escape($o['label']) . '</label>';
            }
        }
        $n = rex_escape((string) $i);
        return '<tr class="yfg-filter">'
            . '<td><label class="sr-only" for="yfg-f-' . $n . '-field">Feld</label>' . $fieldSelect('feed[config][filters][' . $i . '][field]', $f['field'], $fields, 'yfg-f-' . $n . '-field', true, '– Feld wählen –') . '</td>'
            . '<td><label class="sr-only" for="yfg-f-' . $n . '-op">Bedingung</label><select class="form-control yfg-op" id="yfg-f-' . $n . '-op" name="feed[config][filters][' . $i . '][op]">' . $ops . '</select></td>'
            . '<td><label class="sr-only" for="yfg-f-' . $n . '-value">Wert</label><input class="form-control yfg-value" id="yfg-f-' . $n . '-value" name="feed[config][filters][' . $i . '][value]" value="' . rex_escape($f['value']) . '"' . ($isRelation || '' === $f['field'] ? ' hidden' : '') . '>'
            . '<fieldset class="yfg-values"' . ($isRelation ? '' : ' hidden') . '><legend class="sr-only">Werte auswählen</legend><div class="yfg-checks">' . $checks . '</div></fieldset>'
            . '<p class="yfg-hint help-block"' . ('' === $f['field'] ? '' : ' hidden') . '>Zuerst links ein Feld wählen. Bei Relationsfeldern (z. B. Kategorie) erscheinen hier die Werte zum Ankreuzen.</p></td>'
            . '<td><button type="button" class="btn btn-delete yfg-remove">Entfernen</button></td></tr>';
    };
    foreach ($filterRows as $i => $f) {
        $content .= $renderFilter($i, $f);
    }
    $content .= '</tbody></table>';
    $content .= '<template id="yfg-filter-template">' . $renderFilter('__i__', ['field' => '', 'op' => 'any', 'value' => '', 'values' => []]) . '</template>';
    $content .= '<button type="button" class="btn btn-default yfg-add">Filter hinzufügen</button></fieldset>';

    // Ausgabe
    $content .= '<fieldset><legend>Ausgabe</legend><div class="row">';
    $content .= '<div class="col-sm-3 form-group"><label for="yfg-limit">Anzahl Einträge</label><input class="form-control" type="number" min="1" max="500" id="yfg-limit" name="feed[config][limit]" value="' . (int) $config['limit'] . '"></div>';
    $content .= '<div class="col-sm-3 form-group"><label for="yfg-excerpt">Anriss kürzen auf (Zeichen, 0 = nicht)</label><input class="form-control" type="number" min="0" max="5000" id="yfg-excerpt" name="feed[config][excerpt_length]" value="' . (int) $config['excerpt_length'] . '"></div>';
    $content .= '<div class="col-sm-3 form-group"><label for="yfg-lang">Sprache</label><input class="form-control" id="yfg-lang" name="feed[config][language]" value="' . rex_escape((string) $config['language']) . '"></div>';
    $content .= '<div class="col-sm-3 form-group"><label for="yfg-clang">Sprach-ID für Links</label><select class="form-control" id="yfg-clang" name="feed[config][clang_id]">';
    foreach (rex_clang::getAll() as $clang) {
        $content .= '<option value="' . $clang->getId() . '"' . ($clang->getId() === (int) $config['clang_id'] ? ' selected' : '') . '>' . rex_escape($clang->getName()) . '</option>';
    }
    $content .= '</select></div>';
    $content .= '<div class="col-sm-6 form-group"><label for="yfg-profile">URL-Profil (URL-AddOn)</label><select class="form-control" id="yfg-profile" name="feed[config][url_profile]" data-selected="' . (int) $config['url_profile'] . '"><option value="0">– keins –</option>';
    foreach ($profiles as $pid => $plabel) {
        $content .= '<option value="' . $pid . '"' . ($pid === (int) $config['url_profile'] ? ' selected' : '') . '>' . rex_escape($plabel) . '</option>';
    }
    $content .= '</select>' . (Links::urlAddonAvailable() ? '' : '<p class="help-block">Das URL-AddOn ist nicht aktiv.</p>') . '</div>';
    $content .= '<div class="col-sm-6 form-group"><label for="yfg-fallback">Ersatz-Link, falls kein Profil passt</label><input class="form-control" id="yfg-fallback" name="feed[config][link_fallback]" value="' . rex_escape((string) $config['link_fallback']) . '" placeholder="/news/?id={id}" aria-describedby="yfg-fallback-help"><p class="help-block" id="yfg-fallback-help">{id} wird durch die Datensatz-ID ersetzt.</p></div>';
    $content .= '<div class="col-sm-6 form-group"><label for="yfg-channel">Link der Website (channel link)</label><input class="form-control" id="yfg-channel" name="feed[config][channel_link]" value="' . rex_escape((string) $config['channel_link']) . '" placeholder="/"></div>';
    $mediaTypes = Links::mediaTypes();
    foreach (['media_type' => 'Media-Manager-Typ Bild', 'thumb_type' => 'Media-Manager-Typ Vorschaubild'] as $k => $label) {
        $content .= '<div class="col-sm-3 form-group"><label for="yfg-' . $k . '">' . $label . '</label><select class="form-control" id="yfg-' . $k . '" name="feed[config][' . $k . ']">' . ('thumb_type' === $k ? '<option value="">– keins –</option>' : '');
        foreach ($mediaTypes as $mt) {
            $content .= '<option' . ($mt === $config[$k] ? ' selected' : '') . '>' . rex_escape($mt) . '</option>';
        }
        $content .= '</select></div>';
    }
    $content .= '</div></fieldset>';

    $content .= '<div class="yfg-actions"><button class="btn btn-save" type="submit">Speichern</button> <a class="btn btn-default" href="' . rex_url::currentBackendPage() . '">Zurück zur Liste</a></div>';
    $content .= '</form>';

    $fragment = new rex_fragment();
    $fragment->setVar('class', 'edit', false);
    $fragment->setVar('title', $feed ? 'Feed bearbeiten: ' . rex_escape($feed->title) : 'Feed anlegen', false);
    $fragment->setVar('body', $content, false);
    echo $fragment->parse('core/page/section.php');

    // Vorschau
    if ($feed) {
        $preview = '<p>Adresse: <a href="' . rex_escape($feed->url()) . '" target="_blank" rel="noopener">' . rex_escape($feed->url()) . '</a>'
            . ($feed->active ? '' : ' <strong>(inaktiv)</strong>') . '</p>';
        try {
            $renderer = new Renderer($feed);
            $rows = $renderer->rows(10);
            $result = $renderer->render();
            $doc = simplexml_load_string($result['xml']);
            $preview .= '<p>' . $result['items'] . ' Einträge im Feed (die ersten 10):</p><table class="table table-striped"><thead><tr><th>Titel</th><th>Datum</th><th>Kategorien</th><th>Link</th><th>Bild</th></tr></thead><tbody>';
            $i = 0;
            foreach ($doc->channel->item ?? [] as $item) {
                if (++$i > 10) {
                    break;
                }
                $cats = [];
                foreach ($item->category as $cat) {
                    $cats[] = (string) $cat;
                }
                $media = $item->children('http://search.yahoo.com/mrss/');
                $img = (string) ($media->thumbnail?->attributes()['url'] ?? $media->content?->attributes()['url'] ?? '');
                $preview .= '<tr><td>' . rex_escape((string) $item->title) . '</td><td>' . rex_escape(date('d.m.Y H:i', strtotime((string) $item->pubDate) ?: 0)) . '</td><td>' . rex_escape(implode(', ', $cats)) . '</td>'
                    . '<td><a href="' . rex_escape((string) $item->link) . '" target="_blank" rel="noopener">' . rex_escape((string) $item->link) . '</a></td>'
                    . '<td>' . ('' !== $img ? '<img src="' . rex_escape($img) . '" alt="" class="yfg-thumb">' : '–') . '</td></tr>';
            }
            $preview .= '</tbody></table>';
            unset($rows);
        } catch (Throwable $e) {
            $preview .= rex_view::error('Fehler beim Erzeugen: ' . rex_escape($e->getMessage()));
        }
        $fragment = new rex_fragment();
        $fragment->setVar('title', 'Vorschau', false);
        $fragment->setVar('body', $preview, false);
        echo $fragment->parse('core/page/section.php');
    }
    return;
}

$list = rex_list::factory('SELECT id, title, feed_key, table_name, status, token, cache_ttl FROM ' . $table . ' ORDER BY title', 100);
$list->addTableAttribute('class', 'table-striped table-hover');
$list->removeColumn('id');
$list->removeColumn('token');
$thIcon = '<a class="rex-link-expanded" href="' . $list->getUrl(['func' => 'add']) . '" title="Feed anlegen"><i class="rex-icon rex-icon-add"></i><span class="sr-only">Feed anlegen</span></a>';
$list->addColumn($thIcon, '<i class="rex-icon fa-rss"></i>', 0, ['<th class="rex-table-icon">###VALUE###</th>', '<td class="rex-table-icon">###VALUE###</td>']);
$list->setColumnLabel('title', 'Titel');
$list->setColumnParams('title', ['func' => 'edit', 'id' => '###id###']);
$list->setColumnLabel('feed_key', 'Adresse');
$list->setColumnFormat('feed_key', 'custom', static function (array $params): string {
    $feed = Feed::get((int) $params['list']->getValue('id'));
    return $feed ? '<a href="' . rex_escape($feed->url()) . '" target="_blank" rel="noopener">' . rex_escape($feed->url(false)) . '</a>' . ('' !== $feed->token ? ' <span class="label label-default">mit Token</span>' : '') : '';
});
$list->setColumnLabel('table_name', 'Tabelle');
$list->setColumnLabel('status', 'Aktiv');
$list->setColumnFormat('status', 'custom', static fn (array $p): string => 1 === (int) $p['list']->getValue('status') ? 'ja' : 'nein');
$list->setColumnLabel('cache_ttl', 'Cache (s)');
$list->addColumn('Funktionen', 'Bearbeiten', -1, ['<th class="rex-table-action" colspan="2">###VALUE###</th>', '<td class="rex-table-action">###VALUE###</td>']);
$list->setColumnParams('Funktionen', ['func' => 'edit', 'id' => '###id###']);
$list->addColumn('delete', 'Löschen', -1, ['', '<td class="rex-table-action">###VALUE###</td>']);
$list->setColumnParams('delete', ['func' => 'delete', 'id' => '###id###'] + $csrf->getUrlParams());
$list->addLinkAttribute('delete', 'data-confirm', 'Feed löschen?');

$fragment = new rex_fragment();
$fragment->setVar('title', 'Feeds', false);
$fragment->setVar('options', '<a class="btn btn-default btn-xs" href="' . rex_url::currentBackendPage(['func' => 'clear'] + $csrf->getUrlParams()) . '">Cache leeren</a>', false);
$fragment->setVar('content', $list->get(), false);
echo $fragment->parse('core/page/section.php');
