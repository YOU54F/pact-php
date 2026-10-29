<?php

use Behat\Config\Config;
use Behat\Config\Filter\TagFilter;
use Behat\Config\Profile;
use Behat\Config\Suite;
use PhpPactTest\CompatibilitySuite\Context\Shared\Hook\SetUpContext;
use PhpPactTest\CompatibilitySuite\Context\V4\PluginContext;

// The plugin features are not in the pinned compatibility-suite submodule yet, so they are read from a
// pact-compatibility-suite checkout named by PACT_COMPAT_SUITE. The suite is skipped when it is not set.
//
// To run them, install the plugins the scenarios pin and point PACT_COMPAT_SUITE at the checkout:
//
//   pact plugin install matt --version 0.1.1
//   pact plugin install csv --version 0.0.6
//   pact plugin install avro --version 0.1.3
//   pact plugin install protobuf --version 0.8.0
//   PACT_COMPAT_SUITE=/path/to/pact-compatibility-suite vendor/bin/behat --suite=v4_plugins
//
// The providers are PHP subprocesses (php -S and stream servers) and the gRPC client needs curl with HTTP/2.
$root = getenv('PACT_COMPAT_SUITE');
if (!$root || !is_dir($root . '/features/V4/plugins')) {
    return new Config();
}

return (new Config())
    ->withProfile((new Profile('default'))
        ->withSuite((new Suite('v4_plugins'))
            ->addContext(SetUpContext::class)
            ->addContext(PluginContext::class, [realpath($root)])
            ->withPaths(realpath($root) . '/features/V4/plugins')
            ->withFilter(new TagFilter('~@wip'))));
