<?php

/** @var Composer\Autoload\ClassLoader $loader */
$loader = require __DIR__ . '/../../../vendor/autoload.php';
$loader->addPsr4('OrMatcherConsumer\\', __DIR__ . '/src');
$loader->addPsr4('OrMatcherConsumer\\Tests\\', __DIR__ . '/tests');
