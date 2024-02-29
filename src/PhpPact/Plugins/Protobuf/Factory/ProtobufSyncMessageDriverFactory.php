<?php

namespace PhpPact\Plugins\Protobuf\Factory;

use PhpPact\FFI\Client;
use PhpPact\Plugins\Protobuf\Driver\Pact\ProtobufPactDriver;
use PhpPact\Plugins\Protobuf\Registry\Interaction\ProtobufSyncMessageRegistry;
use PhpPact\Plugins\Protobuf\Service\GrpcMockServer;
use PhpPact\Standalone\MockService\MockServerConfigInterface;
use PhpPact\SyncMessage\Driver\Interaction\SyncMessageDriver;
use PhpPact\SyncMessage\Driver\Interaction\SyncMessageDriverInterface;
use PhpPact\SyncMessage\Factory\SyncMessageDriverFactoryInterface;

class ProtobufSyncMessageDriverFactory implements SyncMessageDriverFactoryInterface
{
    public function create(MockServerConfigInterface $config): SyncMessageDriverInterface
    {
        $client = new Client();
        $pactDriver = new ProtobufPactDriver($client, $config);
        $grpcMockServer = new GrpcMockServer($client, $pactDriver, $config);
        $syncMessageRegistry = new ProtobufSyncMessageRegistry($client, $pactDriver);

        return new SyncMessageDriver($syncMessageRegistry, $grpcMockServer);
    }
}
