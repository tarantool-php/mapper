<?php

include '/../../vendor/autoload.php';

function resource_path()
{
    return __DIR__.DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, func_get_args());
}