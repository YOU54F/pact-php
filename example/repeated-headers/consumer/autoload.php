<?php

/** @var Composer\Autoload\ClassLoader $loader */
$loader = require __DIR__ . '/../../../vendor/autoload.php';
$loader->addPsr4('RepeatedHeadersConsumer\\', __DIR__ . '/src');
$loader->addPsr4('RepeatedHeadersConsumer\\Tests\\', __DIR__ . '/tests');
