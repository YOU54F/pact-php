<?php

namespace PhpPact\Standalone\MockService\Model;

class VerifyResult
{
    /**
     * @param bool   $matched    whether all requests received by the mock server matched the interactions
     * @param string $mismatches JSON encoded mismatches, empty if matched
     * @param string $logs       logs of the mock server, captured before it was cleaned up. Only filled if a buffer log sink is set up
     */
    public function __construct(
        public readonly bool $matched,
        public readonly string $mismatches,
        public readonly string $logs = '',
    ) {
    }
}
