<?php

namespace PhpPactTest\CompatibilitySuite\Context\V4;

use Behat\Behat\Context\Context;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Gherkin\Node\PyStringNode;
use Behat\Gherkin\Node\TableNode;
use Behat\Hook\AfterScenario;
use Behat\Hook\BeforeScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use FFI;
use FFI\CData;
use PhpPact\FFI\Client;
use PhpPactTest\CompatibilitySuite\Constant\Path;
use PHPUnit\Framework\Assert;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Steps for the V4 plugin features (features/V4/plugins in pact-compatibility-suite), driving the FFI directly
 * through the FFI client, as the plugin interface is not part of the pact-php consumer/provider API.
 */
final class PluginContext implements Context
{
    private Client $client;
    private string $pactDir;
    /** @var array<string, string> plugin versions from the "is installed" steps */
    private array $versions = [];
    private ?int $pact = null;
    /** @var string[] */
    private array $loaded = [];
    /** @var int[] */
    private array $interactions = [];
    private ?int $pluginResult = null;
    private ?string $pluginError = null;
    private ?int $port = null;
    private ?bool $matched = null;
    /** @var array<mixed> */
    private array $mismatches = [];
    private int $status = 0;
    private string $body = '';
    /** @var array<mixed> */
    private array $pactJson = [];
    private ?Process $provider = null;
    private ?int $providerPort = null;
    /** @var string[] */
    private array $pactFiles = [];
    private ?int $exit = null;
    /** @var array<mixed> */
    private array $results = [];
    private string $output = '';
    private ?string $tcpReply = null;
    private ?string $grpcStatus = null;
    private string $grpcData = '';

    public function __construct(private string $suiteRoot)
    {
        $this->client = new Client();
    }

    #[BeforeScenario]
    public function setUp(BeforeScenarioScope $scope): void
    {
        $slug = preg_replace('/[^A-Za-z0-9]+/', '-', $scope->getScenario()->getTitle() ?? 'scenario');
        $this->pactDir = Path::PACTS_PATH . '/plugins/' . trim((string) $slug, '-');
        $this->removeDir($this->pactDir);
    }

    #[AfterScenario]
    public function tearDown(): void
    {
        $this->provider?->stop(0);
        $this->provider = null;
        if (null !== $this->port) {
            $this->client->cleanupMockServer($this->port);
            $this->port = null;
        }
        if (null !== $this->pact) {
            $this->client->cleanupPlugins($this->pact);
            $this->client->freePactHandle($this->pact);
            $this->pact = null;
        }
    }

    // ---- Consumer ----

    #[Given('/^the "(.*)" plugin is not installed$/')]
    public function thePluginIsNotInstalled(string $name): void
    {
        Assert::assertSame([], $this->installed($name), "plugin $name is installed");
    }

    #[Given('/^the "(.*)" plugin version "(.*)" is installed$/')]
    public function thePluginVersionIsInstalled(string $name, string $version): void
    {
        Assert::assertContains("$name-$version", $this->installed($name), "plugin $name-$version is not installed in {$this->pluginDir()}");
        $this->versions[$name] = $version;
    }

    #[Given('an HTTP interaction is being defined for a consumer test')]
    public function anHttpInteractionIsBeingDefined(): void
    {
        $this->interactions[] = $this->client->newInteraction($this->newV4Pact(), 'plugin interaction 1');
    }

    #[Given('another HTTP interaction is being defined for the same consumer test')]
    public function anotherHttpInteractionIsBeingDefined(): void
    {
        $n = count($this->interactions) + 1;
        $this->interactions[] = $this->client->newInteraction($this->pact(), "plugin interaction $n");
    }

    #[When('/^the "(.*)" plugin is loaded for the test$/')]
    public function thePluginIsLoaded(string $name): void
    {
        $this->loadPlugin($name, null);
    }

    #[When('/^the "(.*)" plugin version "(.*)" is loaded for the test$/')]
    public function thePluginVersionIsLoaded(string $name, string $version): void
    {
        $this->loadPlugin($name, $version);
    }

    #[Then('/^loading the plugin will fail with an error that mentions "(.*)"$/')]
    public function loadingThePluginWillFail(string $text): void
    {
        Assert::assertNotNull($this->pluginResult, 'the plugin was not loaded');
        Assert::assertNotSame(0, $this->pluginResult, 'the plugin loaded');
        Assert::assertStringContainsString($text, (string) $this->pluginError);
    }

    #[Then('the test will not have panicked')]
    public function theTestWillNotHavePanicked(): void
    {
        Assert::assertNotSame(1, $this->pluginResult, "pactffi_using_plugin panicked: {$this->pluginError}");
    }

    #[Given('/^the HTTP request is "(.*)" "(.*)"$/')]
    public function theHttpRequestIs(string $method, string $path): void
    {
        $this->client->withRequest($this->interaction(), $method, $path);
    }

    #[Given('/^the HTTP request body is set with the "(.*)" plugin for "(.*)" with:$/')]
    public function theHttpRequestBodyIsSetWithThePlugin(string $name, string $contentType, PyStringNode $contents): void
    {
        $this->setPluginBody($this->client->getInteractionPartRequest(), $name, $contentType, $contents->getRaw());
    }

    #[Given('/^the HTTP response body is set with the "(.*)" plugin for "(.*)" with:$/')]
    public function theHttpResponseBodyIsSetWithThePlugin(string $name, string $contentType, PyStringNode $contents): void
    {
        $this->setPluginBody($this->client->getInteractionPartResponse(), $name, $contentType, $contents->getRaw());
    }

    #[Given('/^the HTTP response body is "(.*)" with content type "(.*)"$/')]
    public function theHttpResponseBodyIs(string $body, string $contentType): void
    {
        $this->client->withBody($this->interaction(), $this->client->getInteractionPartResponse(), $contentType, $this->bodyCell($body));
    }

    #[Given('a message interaction is being defined for a consumer test')]
    public function aMessageInteractionIsBeingDefined(): void
    {
        $this->interactions[] = $this->client->newMessageInteraction($this->newV4Pact(), 'plugin message 1');
    }

    #[Given('a synchronous message interaction is being defined for a consumer test')]
    public function aSynchronousMessageInteractionIsBeingDefined(): void
    {
        $this->interactions[] = $this->client->newSyncMessageInteraction($this->newV4Pact(), 'plugin message 1');
    }

    /**
     * The plugin's configuration gives the request and, for a synchronous message, the response, so it is set once,
     * on the request part.
     */
    #[Given('/^the (?:synchronous )?message contents are set with the "(.*)" plugin for "(.*)" with:$/')]
    public function theMessageContentsAreSetWithThePlugin(string $name, string $contentType, PyStringNode $contents): void
    {
        $this->setPluginBody($this->client->getInteractionPartRequest(), $name, $contentType, $contents->getRaw());
    }

    #[When('/^the mock server is started with (?:all the interactions|the plugin interaction)$/')]
    public function theMockServerIsStarted(): void
    {
        $this->startMockServer('http');
    }

    #[When('/^a "(.*)" request is made to "(.*)" with content type "(.*)" and body "(.*)"$/')]
    public function aRequestIsMadeWithBody(string $method, string $path, string $contentType, string $body): void
    {
        $this->send($method, $path, $contentType, $body);
    }

    #[When('/^a "([A-Z]+)" request is made to "([^"]*)"$/')]
    public function aRequestIsMade(string $method, string $path): void
    {
        $this->send($method, $path);
    }

    #[Then('a 200 success response is returned')]
    public function aSuccessResponseIsReturned(): void
    {
        Assert::assertSame(200, $this->status, $this->body);
    }

    #[Then('/^the response body will be "(.*)"$/')]
    public function theResponseBodyWillBe(string $body): void
    {
        $expected = $this->bodyCell($body);
        if ($expected !== $this->body) {
            // The mock server re-serialises JSON bodies, so JSON is compared as values
            $json = json_decode($expected, true);
            Assert::assertNotNull($json, 'response body differs');
            Assert::assertEquals($json, json_decode($this->body, true));
        }
    }

    #[When('the pact test is done')]
    public function thePactTestIsDone(): void
    {
        $port = $this->port ?? throw new RuntimeException('mock server not started');
        $this->port = null;
        $this->matched = $this->client->mockServerMatched($port);
        $mismatches = json_decode($this->client->mockServerMismatches($port), true);
        $this->mismatches = is_array($mismatches) ? $mismatches : [];
        try {
            if ($this->matched) {
                $rc = $this->client->writePactFile($port, $this->pactDir, false);
                Assert::assertSame(0, $rc, 'pactffi_write_pact_file failed');
                $this->readPact();
            }
        } finally {
            $this->client->cleanupMockServer($port);
        }
    }

    #[Then('the mock server status will be OK')]
    public function theMockServerStatusWillBeOk(): void
    {
        Assert::assertTrue($this->matched, 'mock server did not match: ' . json_encode($this->mismatches));
    }

    #[Then('the mock server status will be mismatches')]
    public function theMockServerStatusWillBeMismatches(): void
    {
        Assert::assertFalse($this->matched, 'mock server had no mismatches');
    }

    #[Then('/^the mismatches will contain a mismatch with error "(.*)"$/')]
    public function theMismatchesWillContain(string $error): void
    {
        Assert::assertTrue($this->anyStringContains($this->mismatches, $error), "no mismatch with \"$error\": " . json_encode($this->mismatches));
    }

    #[When('the Pact file for the test is generated')]
    public function thePactFileIsGenerated(): void
    {
        Assert::assertSame(0, $this->client->pactHandleWriteFile($this->pact(), $this->pactDir, false), 'pactffi_pact_handle_write_file failed');
        $this->readPact();
    }

    #[When('the plugins are cleaned up')]
    public function thePluginsAreCleanedUp(): void
    {
        $pact = $this->pact();
        $this->pact = null;
        $this->client->cleanupPlugins($pact);
        $this->client->freePactHandle($pact);
    }

    /** Plugin processes are stopped asynchronously, so this waits a little for them to go. */
    #[Then('/^no "(.*)" plugin process will still be running$/')]
    public function noPluginProcessWillBeRunning(string $name): void
    {
        $deadline = microtime(true) + 10;
        while ($this->pluginRunning($name)) {
            Assert::assertLessThan($deadline, microtime(true), "a $name plugin process is still running");
            usleep(200_000);
        }
    }

    #[Then('/^the pact file will contain (\d+) interactions$/')]
    public function thePactFileWillContainInteractions(int $n): void
    {
        Assert::assertCount($n, self::arr(self::dig($this->pactJson, 'interactions')));
    }

    #[Then('the second interaction in the pact file will not have plugin configuration')]
    public function theSecondInteractionWillNotHavePluginConfiguration(): void
    {
        Assert::assertEmpty(self::dig($this->pactJson, 'interactions', 1, 'pluginConfiguration'));
    }

    #[Then('/^the first interaction in the pact file will have plugin configuration for "(.*)"$/')]
    public function theFirstInteractionWillHavePluginConfiguration(string $name): void
    {
        Assert::assertArrayHasKey($name, self::arr(self::dig($this->firstInteraction(), 'pluginConfiguration')));
    }

    #[Then('/^the Pact file metadata will list the "(.*)" plugin with version "(.*)"$/')]
    public function theMetadataWillListThePlugin(string $name, string $version): void
    {
        $plugins = self::arr(self::dig($this->pactJson, 'metadata', 'plugins'));
        $listed = array_filter($plugins, fn (mixed $p) => self::dig($p, 'name') === $name && self::dig($p, 'version') === $version);
        Assert::assertNotEmpty($listed, "$name $version is not in the metadata plugins: " . json_encode($plugins));
    }

    #[Then('/^the first interaction in the pact file (request|response|contents) content type will be "(.*)"$/')]
    public function theContentTypeWillBe(string $part, string $contentType): void
    {
        Assert::assertSame($contentType, $this->partContents($part)['contentType'] ?? null);
    }

    #[Then('/^the first interaction (request|response) in the pact file will have a matching rule for "(.*)" of type "(.*)"$/')]
    public function thePartWillHaveAMatchingRule(string $part, string $key, string $rule): void
    {
        $this->assertMatchingRule($part, $key, $rule);
    }

    #[Then('/^the first interaction contents in the pact file will have a matching rule for "(.*)" of type "(.*)"$/')]
    public function theContentsWillHaveAMatchingRule(string $key, string $rule): void
    {
        $this->assertMatchingRule('contents', $key, $rule);
    }

    #[Then('/^the first interaction in the Pact file will have a type of "(.*)"$/')]
    public function theFirstInteractionWillHaveAType(string $type): void
    {
        Assert::assertSame($type, $this->firstInteraction()['type'] ?? null);
    }

    #[Then('/^the first interaction in the pact file (contents|request contents|response contents) will be "(.*)"$/')]
    public function theContentsWillBe(string $part, string $expected): void
    {
        $contents = $this->partContents(str_replace(' contents', '', $part));
        $content = $contents['content'] ?? null;
        if (is_string($content) && 'base64' === strtolower(self::str(self::dig($contents, 'encoded')))) {
            $content = base64_decode($content);
        } elseif (!is_string($content)) {
            $content = json_encode($content);
        }
        Assert::assertSame($expected, $content);
    }

    #[Then('/^the first interaction in the pact file will contain (\d+) response messages?$/')]
    public function theFirstInteractionWillContainResponseMessages(int $n): void
    {
        Assert::assertCount($n, self::arr(self::dig($this->firstInteraction(), 'response')));
    }

    // ---- Provider ----

    /**
     * Builds the pact a consumer test with the plugin would have written: pactffi_using_plugin, then
     * pactffi_interaction_contents for each part the table gives.
     */
    #[Given('/^a Pact file with an HTTP "(.*)" "(.*)" interaction with plugin contents:$/')]
    public function aPactFileWithAnHttpInteraction(string $method, string $path, TableNode $table): void
    {
        $row = $table->getHash()[0];
        $this->writeProviderPact($row['plugin'], function (int $pact) use ($method, $path, $row): void {
            $interaction = $this->client->newInteraction($pact, 'plugin interaction');
            $this->client->withRequest($interaction, $method, $path);
            foreach (['request' => $this->client->getInteractionPartRequest(), 'response' => $this->client->getInteractionPartResponse()] as $col => $part) {
                if ('' !== ($row[$col] ?? '')) {
                    $rc = $this->client->interactionContents($interaction, $part, $row['content type'], $this->pluginContents($row[$col]));
                    Assert::assertSame(0, $rc, "pactffi_interaction_contents failed for the $col");
                }
            }
        });
    }

    #[Given('/^a Pact file with a "(.*)" (message|synchronous message) interaction with plugin contents:$/')]
    public function aPactFileWithAMessageInteraction(string $description, string $kind, TableNode $table): void
    {
        $row = $table->getHash()[0];
        $this->writeProviderPact($row['plugin'], function (int $pact) use ($description, $kind, $row): void {
            $interaction = 'message' === $kind
                ? $this->client->newMessageInteraction($pact, $description)
                : $this->client->newSyncMessageInteraction($pact, $description);
            $rc = $this->client->interactionContents($interaction, $this->client->getInteractionPartRequest(), $row['content type'], $this->pluginContents($row['contents']));
            Assert::assertSame(0, $rc, 'pactffi_interaction_contents failed');
        });
    }

    #[Given('/^a provider is started that can generate the "(.*)" message with "(.*)" and content type "(.*)"$/')]
    public function aMessageProviderIsStarted(string $description, string $body, string $contentType): void
    {
        $this->startHttpProvider($body, $contentType, $description);
    }

    #[Given('/^a provider is started that returns "(.*)" with content type "(.*)"$/')]
    public function aProviderIsStarted(string $body, string $contentType): void
    {
        $this->startHttpProvider($body, $contentType, null);
    }

    #[When('the verification is run')]
    public function theVerificationIsRun(): void
    {
        $this->verify(null);
    }

    #[Then('the verification will be successful')]
    public function theVerificationWillBeSuccessful(): void
    {
        Assert::assertTrue(0 === $this->exit && true === ($this->results['result'] ?? null), "verification failed (exit {$this->exit}):\n{$this->output}");
    }

    #[Then('the verification will NOT be successful')]
    public function theVerificationWillNotBeSuccessful(): void
    {
        Assert::assertTrue(0 !== $this->exit && false === ($this->results['result'] ?? null), "verification passed (exit {$this->exit})");
    }

    #[Then('/^the verification results will contain a "(.*)" error$/')]
    public function theVerificationResultsWillContainAnError(string $error): void
    {
        $types = match ($error) {
            'Body had differences' => ['BodyMismatch', 'BodyTypeMismatch'],
            'Metadata had differences' => ['MetadataMismatch'],
            default => throw new RuntimeException("unknown error $error"),
        };
        $found = false;
        foreach (self::arr(self::dig($this->results, 'interactionResults')) as $result) {
            foreach (self::arr(self::dig($result, 'mismatch', 'mismatches')) as $mismatch) {
                $found = $found || in_array(self::dig($mismatch, 'type'), $types, true);
            }
        }
        Assert::assertTrue($found, "no \"$error\" error in the verification results: " . json_encode($this->results));
    }

    #[Then('/^the verification output will contain "(.*)"$/')]
    public function theVerificationOutputWillContain(string $text): void
    {
        Assert::assertStringContainsString($text, $this->output);
    }

    // ---- Plugin transports ----

    #[When('/^a mock server is started for the "(.*)" transport$/')]
    public function aMockServerIsStartedForTheTransport(string $transport): void
    {
        $this->startMockServer($transport);
    }

    #[When('/^the line "(.*)" is sent to the mock server over TCP$/')]
    public function theLineIsSentOverTcp(string $line): void
    {
        $port = $this->port ?? throw new RuntimeException('mock server not started');
        // A mismatch can leave the mock server with nothing to reply, so a failed read is kept as no reply
        $this->tcpReply = null;
        $conn = $this->connect($port);
        stream_set_timeout($conn, 10);
        fwrite($conn, "$line\n");
        $reply = fgets($conn);
        fclose($conn);
        if (false !== $reply) {
            $this->tcpReply = rtrim($reply, "\r\n");
        }
    }

    #[Then('/^the line "(.*)" will be received from the mock server$/')]
    public function theLineWillBeReceived(string $line): void
    {
        Assert::assertSame($line, $this->tcpReply);
    }

    #[When('/^the gRPC method "(.*)" is called on the mock server with (.*)$/')]
    public function theGrpcMethodIsCalled(string $method, string $message): void
    {
        $port = $this->port ?? throw new RuntimeException('mock server not started');
        // Either a quoted body cell or "an empty message"
        $message = preg_match('/^"(.*)"$/', $message, $m) ? $this->bodyCell($m[1]) : '';
        $this->grpcCall($port, $method, $message);
    }

    #[Then('/^the gRPC call will return "(.*)"$/')]
    public function theGrpcCallWillReturn(string $message): void
    {
        Assert::assertSame('0', $this->grpcStatus, 'the gRPC call failed');
        Assert::assertSame($this->bodyCell($message), $this->grpcData);
    }

    #[Then('/^the gRPC call will fail with status "(.*)"$/')]
    public function theGrpcCallWillFail(string $name): void
    {
        $status = match ($name) {
            'OK' => '0',
            'INVALID_ARGUMENT' => '3',
            'NOT_FOUND' => '5',
            'FAILED_PRECONDITION' => '9',
            'UNIMPLEMENTED' => '12',
            'INTERNAL' => '13',
            default => throw new RuntimeException("unknown gRPC status $name"),
        };
        Assert::assertSame($status, $this->grpcStatus);
    }

    #[Then('the mock server will write out a Pact file for the interaction when done')]
    public function theMockServerWillWriteOutAPactFile(): void
    {
        // "the pact test is done" writes the file once the mock server matched
        Assert::assertNotEmpty($this->firstInteraction(), 'no Pact file was written: ' . json_encode($this->mismatches));
    }

    #[Then('/^the first interaction in the Pact file will have "(.*)" = \'(.*)\'$/')]
    public function theFirstInteractionWillHaveField(string $field, string $value): void
    {
        Assert::assertSame(json_decode($value, true), $this->firstInteraction()[$field] ?? null);
    }

    /**
     * The FFI has no call to set an interaction's transport outside a mock server, so it is added to the written
     * file, as the consumer's mock server would have recorded it.
     */
    #[Given('/^a Pact file with a "(.*)" synchronous message interaction over the "(.*)" transport with plugin contents:$/')]
    public function aPactFileWithATransportInteraction(string $description, string $transport, TableNode $table): void
    {
        $this->aPactFileWithAMessageInteraction($description, 'synchronous message', $table);
        $file = end($this->pactFiles) ?: throw new RuntimeException('no pact file written');
        // Decoded as objects, so empty JSON objects (such as plugin configuration) are written back as objects
        $pact = json_decode((string) file_get_contents($file));
        $interaction = $pact instanceof \stdClass && is_array($pact->interactions ?? null) ? $pact->interactions[0] ?? null : null;
        if (!$interaction instanceof \stdClass) {
            throw new RuntimeException("$file has no interactions");
        }
        $interaction->transport = $transport;
        file_put_contents($file, json_encode($pact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    #[Given('/^a TCP provider is started that replies to "(.*)" with "(.*)"$/')]
    public function aTcpProviderIsStarted(string $line, string $reply): void
    {
        $this->startProcess(['php', $this->script('tcp-provider.php'), (string) $this->freePort(), $line, $reply]);
    }

    #[Given('/^a gRPC provider is started that answers "(.*)" with "(.*)"$/')]
    public function aGrpcProviderIsStarted(string $method, string $message): void
    {
        $file = $this->pactDir . '/grpc-message.bin';
        @mkdir($this->pactDir, 0777, true);
        file_put_contents($file, $this->bodyCell($message));
        $this->startProcess(['php', $this->script('grpc-provider.php'), (string) $this->freePort(), $file]);
    }

    #[Given('no provider is listening')]
    public function noProviderIsListening(): void
    {
        $this->providerPort = $this->freePort();
    }

    #[When('/^the verification is run with the "(.*)" transport$/')]
    public function theVerificationIsRunWithTheTransport(string $transport): void
    {
        $this->verify($transport);
    }

    // ---- Helpers ----

    private function newV4Pact(): int
    {
        $this->pact = $this->client->newPact('c', 'p');
        $this->client->withSpecification($this->pact, $this->client->getPactSpecificationV4());

        return $this->pact;
    }

    private function pact(): int
    {
        return $this->pact ?? throw new RuntimeException('no consumer test is being defined');
    }

    private function interaction(): int
    {
        return end($this->interactions) ?: throw new RuntimeException('no interaction is being defined');
    }

    private function loadPlugin(string $name, ?string $version): int
    {
        $rc = $this->client->usingPlugin($this->pact(), $name, $version);
        $this->pluginResult = $rc;
        if (0 === $rc) {
            $this->loaded[] = $name;
        } else {
            $this->pluginError = $this->lastError();
        }

        return $rc;
    }

    private function setPluginBody(int $part, string $name, string $contentType, string $contents): void
    {
        if (!in_array($name, $this->loaded, true)) {
            $rc = $this->loadPlugin($name, $this->versions[$name] ?? null);
            Assert::assertSame(0, $rc, "pactffi_using_plugin failed: {$this->pluginError}");
        }
        $rc = $this->client->interactionContents($this->interaction(), $part, $contentType, $this->pluginContents($contents));
        Assert::assertSame(0, $rc, 'pactffi_interaction_contents failed');
    }

    private function startMockServer(string $transport): void
    {
        $port = $this->client->createMockServerForTransport($this->pact(), '127.0.0.1', 0, $transport, null);
        Assert::assertGreaterThan(0, $port, "pactffi_create_mock_server_for_transport failed: {$this->lastError()}");
        $this->port = $port;
    }

    private function send(string $method, string $path, ?string $contentType = null, ?string $body = null): void
    {
        $port = $this->port ?? throw new RuntimeException('mock server not started');
        $ch = curl_init("http://127.0.0.1:$port$path");
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method ?: 'GET',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        if (null !== $body) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: $contentType"]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $this->body = (string) curl_exec($ch);
        $this->status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    }

    /** @param callable(int): void $define */
    private function writeProviderPact(string $plugin, callable $define): void
    {
        $pact = $this->client->newPact('c', 'p');
        $this->client->withSpecification($pact, $this->client->getPactSpecificationV4());
        try {
            $rc = $this->client->usingPlugin($pact, $plugin, $this->versions[$plugin] ?? null);
            Assert::assertSame(0, $rc, "pactffi_using_plugin failed: {$this->lastError()}");
            $define($pact);
            Assert::assertSame(0, $this->client->pactHandleWriteFile($pact, $this->pactDir, false), 'pactffi_pact_handle_write_file failed');
            $this->pactFiles[] = $this->pactDir . '/c-p.json';
        } finally {
            $this->client->cleanupPlugins($pact);
            $this->client->freePactHandle($pact);
        }
    }

    private function startHttpProvider(string $body, string $contentType, ?string $description): void
    {
        @mkdir($this->pactDir, 0777, true);
        $file = $this->pactDir . '/provider-body.bin';
        file_put_contents($file, $this->bodyCell($body));
        $env = ['PROVIDER_BODY_FILE' => $file, 'PROVIDER_CONTENT_TYPE' => $contentType];
        if (null !== $description) {
            $env['PROVIDER_DESCRIPTION'] = $description;
        }
        $port = $this->freePort();
        $this->startProcess(['php', '-S', "127.0.0.1:$port", $this->script('http-provider.php')], $env, $port);
    }

    /**
     * @param string[]              $command
     * @param array<string, string> $env
     */
    private function startProcess(array $command, array $env = [], ?int $port = null): void
    {
        $port ??= (int) $command[2];
        $this->provider = new Process($command, null, $env);
        $this->provider->start();
        $deadline = microtime(true) + 10;
        while (!($conn = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2))) {
            if (!$this->provider->isRunning() || microtime(true) > $deadline) {
                throw new RuntimeException('provider did not start: ' . $this->provider->getErrorOutput());
            }
            usleep(50_000);
        }
        fclose($conn);
        $this->providerPort = $port;
    }

    private function verify(?string $transport): void
    {
        $port = $this->providerPort ?? throw new RuntimeException('no provider started');
        $handle = $this->client->verifierNewForApplication('pact-php-compatibility-suite', '1.0.0');
        Assert::assertNotNull($handle);
        $this->client->verifierSetProviderInfo($handle, 'p', 'http', '127.0.0.1', $port, '/');
        if (null !== $transport) {
            $this->client->verifierAddProviderTransport($handle, $transport, $port, '', 'grpc' === $transport ? 'http' : 'tcp');
        }
        foreach ($this->pactFiles as $file) {
            $this->client->verifierAddFileSource($handle, $file);
        }
        $this->exit = $this->client->verifierExecute($handle);
        $this->output = $this->ffiString($this->client->call('pactffi_verifier_output', $handle, 1));
        $results = json_decode((string) $this->client->verifierJson($handle), true);
        $this->results = is_array($results) ? $results : [];
        $this->client->verifierShutdown($handle);
    }

    /** @return resource */
    private function connect(int $port)
    {
        // Some plugin mock servers only listen on the IPv6 loopback
        foreach (['127.0.0.1', '[::1]'] as $host) {
            $conn = @stream_socket_client("tcp://$host:$port", $errno, $error, 5);
            if ($conn) {
                return $conn;
            }
        }
        throw new RuntimeException("could not connect to port $port: $error");
    }

    /** A unary gRPC call over HTTP/2 without TLS. */
    private function grpcCall(int $port, string $method, string $message): void
    {
        foreach (['127.0.0.1', '[::1]'] as $host) {
            $status = null;
            $ch = curl_init("http://$host:$port$method");
            curl_setopt_array($ch, [
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => "\x00" . pack('N', strlen($message)) . $message,
                CURLOPT_HTTPHEADER => ['Content-Type: application/grpc', 'TE: trailers'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                // Trailers come through the header callback, after the headers, so the last grpc-status wins
                CURLOPT_HEADERFUNCTION => function ($ch, string $header) use (&$status): int {
                    if (preg_match('/^grpc-status:\s*(\d+)/i', $header, $m)) {
                        $status = $m[1];
                    }

                    return strlen($header);
                },
            ]);
            $data = curl_exec($ch);
            if (false !== $data) {
                $this->grpcStatus = $status;
                $this->grpcData = substr((string) $data, 5);

                return;
            }
            $error = curl_error($ch);
        }
        throw new RuntimeException("gRPC call failed: $error");
    }

    private function readPact(): void
    {
        $json = json_decode((string) file_get_contents($this->pactDir . '/c-p.json'), true);
        $this->pactJson = is_array($json) ? $json : [];
    }

    /** @return array<mixed> */
    private function firstInteraction(): array
    {
        return self::arr(self::dig($this->pactJson, 'interactions', 0));
    }

    /**
     * A part of the first interaction: an HTTP request or response, a synchronous message's request or first
     * response, or (contents) an asynchronous message.
     *
     * @return array<mixed>
     */
    private function partOf(string $part): array
    {
        $interaction = $this->firstInteraction();

        return match (true) {
            'contents' === $part => $interaction,
            'response' === $part && is_array($interaction['response'] ?? null) && array_is_list($interaction['response']) => self::arr($interaction['response'][0] ?? null),
            default => self::arr($interaction[$part] ?? null),
        };
    }

    /** @return array<mixed> the body of a part: body for HTTP, contents for messages */
    private function partContents(string $part): array
    {
        $p = $this->partOf($part);

        return self::arr($p['contents'] ?? $p['body'] ?? null);
    }

    private function assertMatchingRule(string $part, string $key, string $rule): void
    {
        $rules = self::dig($this->partOf($part), 'matchingRules', 'body');
        $matchers = array_column(self::arr(self::dig($rules, $key, 'matchers')), 'match');
        Assert::assertContains($rule, $matchers, "no $rule matching rule for $key in the $part: " . json_encode($rules));
    }

    /** A body cell: "file: <fixture>" or the literal body. */
    private function bodyCell(string $cell): string
    {
        if (str_starts_with($cell, 'file:')) {
            $path = $this->fixtures() . '/' . trim(substr($cell, 5));
            $contents = file_get_contents($path);
            Assert::assertIsString($contents, "fixture $path");

            return $contents;
        }

        return $cell;
    }

    /** Plugin configuration refers to fixtures as "fixture:<path>"; the plugin needs the absolute path. */
    private function pluginContents(string $text): string
    {
        return str_replace('"fixture:', '"' . $this->fixtures() . '/', $text);
    }

    private function fixtures(): string
    {
        return $this->suiteRoot . '/fixtures';
    }

    private function script(string $name): string
    {
        return Path::PUBLIC_PATH . '/plugins/' . $name;
    }

    private function pluginDir(): string
    {
        return getenv('PACT_PLUGIN_DIR') ?: getenv('HOME') . '/.pact/plugins';
    }

    /** @return string[] */
    private function installed(string $name): array
    {
        $entries = is_dir($this->pluginDir()) ? scandir($this->pluginDir()) : [];

        return array_values(array_filter($entries ?: [], fn (string $entry) => str_starts_with($entry, "$name-")));
    }

    private function pluginRunning(string $name): bool
    {
        $process = new Process(['pgrep', '-f', $this->pluginDir() . "/$name-"]);
        $process->run();

        return $process->isSuccessful();
    }

    private function anyStringContains(mixed $value, string $text): bool
    {
        if (is_string($value)) {
            return str_contains($value, $text);
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if ($this->anyStringContains($item, $text)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function lastError(): string
    {
        $buffer = FFI::cdef()->new('char[1024]') ?? throw new RuntimeException('could not allocate an error buffer');
        $this->client->call('pactffi_get_error_message', $buffer, 1024);

        return FFI::string($buffer);
    }

    private function ffiString(mixed $value): string
    {
        return $value instanceof CData ? FFI::string($value) : self::str($value);
    }

    private function freePort(): int
    {
        $socket = socket_create_listen(0) ?: throw new RuntimeException('could not find a free port');
        socket_getsockname($socket, $addr, $port);
        socket_close($socket);

        return is_int($port) ? $port : throw new RuntimeException('could not find a free port');
    }

    /** @return array<mixed> */
    private static function arr(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /** A value nested in decoded JSON arrays, or null if any key on the path is missing. */
    private static function dig(mixed $value, int|string ...$path): mixed
    {
        foreach ($path as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) || null === $value ? (string) $value : (string) json_encode($value);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff((array) scandir($dir), ['.', '..']) as $entry) {
            $path = "$dir/$entry";
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
