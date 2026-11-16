<?php

rex_sql_table::get(rex::getTable('yform_feed'))->drop();
rex_dir::delete(rex_path::addonCache('yform_feed_generator'));
// Die Media-Manager-Typen bleiben bestehen, falls sie anderweitig genutzt werden.
