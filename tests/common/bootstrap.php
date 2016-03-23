<?php

include implode(DIRECTORY_SEPARATOR, [dirname(dirname(__DIR__)), 'vendor', 'autoload.php']);

function resource_path() {
    return __DIR__.DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, func_get_args());
}
