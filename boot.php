<?php

use KLXM\YFormFeedGenerator\Api\Backend;
use KLXM\YFormFeedGenerator\Api\Feed as FeedApi;
use KLXM\YFormFeedGenerator\Delivery;

/** @var rex_addon $this */

rex_api_function::register('yform_feed_generator', Backend::class);
rex_api_function::register('yform_feed', FeedApi::class);

if (rex::isFrontend()) {
    // /rss/<key>.xml – nach allen anderen AddOns, damit URL-AddOn und YRewrite ihre Umschreibungen registriert haben
    rex_extension::register('PACKAGES_INCLUDED', static function (): void {
        $key = Delivery::keyFromPath((string) ($_SERVER['REQUEST_URI'] ?? ''));
        if (null !== $key) {
            Delivery::serve($key);
        }
    }, rex_extension::LATE);
}

// Cache leeren, wenn sich Daten einer Quelltabelle ändern
$clear = static function (rex_extension_point $ep): void {
    $table = $ep->getParam('table');
    if ($table instanceof rex_yform_manager_table) {
        Delivery::clearCacheForTable($table->getTableName());
    }
};
foreach (['YFORM_DATA_ADDED', 'YFORM_DATA_UPDATED', 'YFORM_DATA_DELETED'] as $ep) {
    rex_extension::register($ep, $clear);
}

if (rex::isBackend() && rex::getUser() && str_starts_with((string) rex_be_controller::getCurrentPage(), 'yform_feed_generator')) {
    rex_view::addCssFile($this->getAssetsUrl('backend.css'));
    rex_view::addJsFile($this->getAssetsUrl('backend.js'));
}
