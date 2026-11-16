<?php

namespace KLXM\YFormFeedGenerator\Api;

use KLXM\YFormFeedGenerator\Delivery;
use rex_api_function;
use rex_api_result;

/**
 * Ersatz-Endpunkt ohne Rewrite: index.php?rex-api-call=yform_feed&key=<key>[&token=…]
 */
final class Feed extends rex_api_function
{
    protected $published = true;

    public function execute(): rex_api_result
    {
        Delivery::serve(rex_get('key', 'string', ''));
    }
}
