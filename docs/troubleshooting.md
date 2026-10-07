# Troubleshooting

## Can not find/load FFI library

This is because you install Pact-PHP in one environment, then share/mount the project to other environment and use it, like virtual machine or container.

### Failed loading pact.so/pact.dll/pact.dylib

```
[FFI\Exception] Failed loading 'vendor/pact-php/bin/pact-ffi-lib/pact.so'
```

* Reason: For example, install on Windows, but use on Linux virtual machine/container
* Solution: On virtual machine or container - Simply run `composer install` or `composer update`

### Invalid ELF header

```
FFI\Exception: Failed loading 'vendor/pact-php/bin/pact-ffi-lib/pact.so' (/lib/x86_64-linux-gnu/libc.so: invalid ELF header)
```

* Reason: For example, install on Ubuntu (doesn't has musl), but use on Alpine (has musl) virtual machine/container
* Solution: On virtual machine or container - First, force remove the file with `rm vendor/pact-php/bin/pact-ffi-lib/pact.so`, then install again with `composer install` or `composer update`


## Output Logging

There are several ways to print the logs:

### Logger Singleton Instance

You can run these code (once) before running tests:

```php
use PhpPact\Log\Logger;
use PhpPact\Log\Enum\LogLevel;
use PhpPact\Log\Model\File;
use PhpPact\Log\Model\Buffer;
use PhpPact\Log\Model\Stdout;
use PhpPact\Log\Model\Stderr;

$logger = Logger::instance();
$logger->attach(new File('/path/to/file', LogLevel::DEBUG));
$logger->attach(new Buffer(LogLevel::ERROR));
$logger->attach(new Stdout(LogLevel::WARN));
$logger->attach(new Stderr(LogLevel::INFO));
$logger->apply();
```

* Pros
    * Flexible, can be used in any test framework
    * Support plugins (e.g. csv, gRPC)
    * Support multiple sinks
* Cons
    * Need to modify the code (once, before the tests)

### PHPUnit Extension

You can put these elements to PHPUnit's configuration file:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="vendor/autoload.php" colors="true">
    ...
    <php>
        <env name="PACT_LOG" value="./log/pact.txt"/>
        <env name="PACT_LOGLEVEL" value="DEBUG"/>
    </php>
    <extensions>
        <bootstrap class="PhpPact\Log\PHPUnit\PactLoggingExtension"/>
    </extensions>
</phpunit>
```

* Pros
    * Support plugins (e.g. csv, gRPC)
    * No need to modify the code
* Cons
    * Support only single sink (stdout or file, depend on the value of the environment variables)
    * Only for PHPUnit

### Config

Consumer:

```php
use PhpPact\Standalone\MockService\MockServerConfig;

$config = new VerifierConfig();
$config->setLogLevel('DEBUG');
```

Provider:

```php
use PhpPact\Standalone\ProviderVerifier\Model\VerifierConfig;

$config = new VerifierConfig();
$config->setLogLevel('DEBUG');
```

* Pros
    * Simple
    * Support plugins (e.g. csv, gRPC)
* Cons
    * Only single sink (stdout)

### Windows process isolation and Pact FFI 0.5.9

Pact FFI 0.5.9 changed `pactffi_init_with_log_level` to write to stderr instead of
stdout. PHPUnit's process-isolation runner reads the child process's stdout to
EOF before draining stderr. On Windows, DEBUG logs can fill the stderr pipe:
the child blocks writing logs while PHPUnit waits for the child to finish.

Pact-PHP uses its existing logger singleton and an explicit stdout sink for
config-based logging. This preserves the previous logging destination and DEBUG
output without removing `--process-isolation`. An already-applied logger, such as
the file sink configured through the PHPUnit extension, is left unchanged.
The first applied logger determines the destination and level for the process.

The upgrade intentionally stops at 0.5.9. The published 0.5.10 Linux x86_64 library
was observed returning byte `2` instead of `0` on false-result paths, including
`pactffi_mock_server_matched` for a nonexistent server and `pactffi_with_body` for
an invalid handle. Both PHP FFI and Python ctypes interpret this as true.
Addressing those invalid native boolean returns is a separate upstream task;
the PHP assertions must not be weakened to hide them.
