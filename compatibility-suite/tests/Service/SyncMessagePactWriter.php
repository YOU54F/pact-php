<?php

namespace PhpPactTest\CompatibilitySuite\Service;

use PhpPact\Config\PactConfigInterface;
use PhpPact\Consumer\Driver\Pact\PactDriver;
use PhpPact\Consumer\Model\Message;
use PhpPact\FFI\Client;
use PhpPact\Standalone\MockService\MockServerConfig;
use PhpPact\SyncMessage\Registry\Interaction\SyncMessageRegistry;
use PhpPactTest\CompatibilitySuite\Constant\Path;
use PhpPactTest\CompatibilitySuite\Model\PactPath;

class SyncMessagePactWriter implements SyncMessagePactWriterInterface
{
    public function __construct(
        private string $specificationVersion,
    ) {
    }

    public function write(Message $message, PactPath $pactPath, string $mode = PactConfigInterface::MODE_OVERWRITE): void
    {
        $config = new MockServerConfig();
        $config
            ->setConsumer($pactPath->getConsumer())
            ->setProvider(PactPath::PROVIDER)
            ->setPactDir(Path::PACTS_PATH)
            ->setPactSpecificationVersion($this->specificationVersion)
            ->setPactFileWriteMode($mode);
        $client = new Client();
        $pactDriver = new PactDriver($client, $config);
        $messageRegistry = new SyncMessageRegistry($client, $pactDriver);

        $messageRegistry->registerMessage($message);
        $pactDriver->writePact();
        $pactDriver->cleanUp();
    }
}
