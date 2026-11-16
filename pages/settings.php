<?php

use KLXM\YFormFeedGenerator\Delivery;

$form = rex_config_form::factory('yform_feed_generator');

$field = $form->addInputField('text', 'path_prefix', null, ['class' => 'form-control', 'pattern' => '[a-z0-9_-]{1,32}']);
$field->setLabel('Pfad der Feeds');
$field->setNotice('Feeds sind erreichbar unter /<em>pfad</em>/<em>schluessel</em>.xml. Ohne Rewrite: index.php?rex-api-call=yform_feed&amp;key=<em>schluessel</em>');

$field = $form->addInputField('number', 'default_ttl', null, ['class' => 'form-control', 'min' => 0, 'max' => 86400]);
$field->setLabel('Standard-Cachezeit neuer Feeds (Sekunden)');

$fragment = new rex_fragment();
$fragment->setVar('class', 'edit', false);
$fragment->setVar('title', 'Einstellungen', false);
$fragment->setVar('body', $form->get(), false);
echo $fragment->parse('core/page/section.php');

if ('' !== rex_post('_csrf_token', 'string', '')) {
    Delivery::clearCache();
}
