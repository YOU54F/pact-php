<?php

namespace CsvProvider\Tests;

use GuzzleHttp\Psr7\Uri;
use PhpPact\Standalone\ProviderVerifier\Model\VerifierConfig;
use PhpPact\Standalone\ProviderVerifier\Verifier;
use PhpPactTest\Helper\PhpProcess;
use PHPUnit\Framework\TestCase;

class PactVerifyTest extends TestCase
{
    private PhpProcess $process;

    protected function setUp(): void
    {
        $this->process = new PhpProcess(__DIR__ . '/../public/');
        $this->process->start();
    }

    protected function tearDown(): void
    {
        $this->process->stop();
    }

    public function testPactVerifyConsumer(): void
    {
        $config = new VerifierConfig();
        $config->getProviderInfo()
            ->setName('csvProvider')
            ->setHost('localhost')
            ->setPort($this->process->getPort());
        $config->getProviderState()
            ->setStateChangeUrl(new Uri(sprintf('http://localhost:%d/pact-change-state', $this->process->getPort())))
        ;
        if ($logLevel = \getenv('PACT_LOGLEVEL')) {
            $config->setLogLevel($logLevel);
        }

        $verifier = new Verifier($config);
        $verifier->addFile(__DIR__ . '/../../pacts/csvConsumer-csvProvider.json');

        $verifyResult = $verifier->verify();

        $this->assertTrue($verifyResult);
    }
}
