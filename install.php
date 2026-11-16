<?php

rex_sql_table::get(rex::getTable('yform_feed'))
    ->ensurePrimaryIdColumn()
    ->ensureColumn(new rex_sql_column('feed_key', 'varchar(64)'))
    ->ensureColumn(new rex_sql_column('title', 'varchar(191)'))
    ->ensureColumn(new rex_sql_column('description', 'text', true))
    ->ensureColumn(new rex_sql_column('table_name', 'varchar(191)'))
    ->ensureColumn(new rex_sql_column('config', 'mediumtext', true))
    ->ensureColumn(new rex_sql_column('token', 'varchar(64)', false, ''))
    ->ensureColumn(new rex_sql_column('cache_ttl', 'int(10) unsigned', false, '600'))
    ->ensureColumn(new rex_sql_column('status', 'tinyint(1)', false, '1'))
    ->ensureColumn(new rex_sql_column('createdate', 'datetime'))
    ->ensureColumn(new rex_sql_column('createuser', 'varchar(191)'))
    ->ensureColumn(new rex_sql_column('updatedate', 'datetime'))
    ->ensureColumn(new rex_sql_column('updateuser', 'varchar(191)'))
    ->ensureIndex(new rex_sql_index('feed_key', ['feed_key'], rex_sql_index::UNIQUE))
    ->ensure();

// Media-Manager-Typen für Feed-Bilder (Größe im Media Manager anpassbar)
$types = [
    'yform_feed_image' => [1200, 'Feed-Generator: Bild (enclosure, media:content)'],
    'yform_feed_thumb' => [400, 'Feed-Generator: Vorschaubild (media:thumbnail)'],
];
foreach ($types as $name => [$width, $description]) {
    $sql = rex_sql::factory();
    $sql->setQuery('SELECT id FROM ' . rex::getTable('media_manager_type') . ' WHERE name = ?', [$name]);
    if ($sql->getRows() > 0) {
        continue;
    }
    $insert = rex_sql::factory();
    $insert->setTable(rex::getTable('media_manager_type'));
    $insert->setValue('status', 0);
    $insert->setValue('name', $name);
    $insert->setValue('description', $description);
    $insert->addGlobalCreateFields('yform_feed_generator');
    $insert->addGlobalUpdateFields('yform_feed_generator');
    $insert->insert();

    $effect = rex_sql::factory();
    $effect->setTable(rex::getTable('media_manager_type_effect'));
    $effect->setValue('type_id', (int) $insert->getLastId());
    $effect->setValue('effect', 'resize');
    $effect->setValue('parameters', json_encode(['rex_effect_resize' => [
        'rex_effect_resize_width' => (string) $width,
        'rex_effect_resize_height' => '',
        'rex_effect_resize_style' => 'maximum',
        'rex_effect_resize_allow_enlarge' => 'not_enlarge',
    ]], JSON_THROW_ON_ERROR));
    $effect->setValue('priority', 1);
    $effect->addGlobalCreateFields('yform_feed_generator');
    $effect->addGlobalUpdateFields('yform_feed_generator');
    $effect->insert();
}
