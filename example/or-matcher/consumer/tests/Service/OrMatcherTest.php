<?php

namespace OrMatcherConsumer\Tests\Service;

use OrMatcherConsumer\Service\HttpClientService;
use PhpPact\Consumer\InteractionBuilder;
use PhpPact\Consumer\Matcher\Matcher;
use PhpPact\Consumer\Model\ConsumerRequest;
use PhpPact\Consumer\Model\ProviderResponse;
use PhpPact\Standalone\MockService\MockServerConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OrMatcherTest extends TestCase
{
    private Matcher $matcher;
    private MockServerConfig $config;
    private InteractionBuilder $builder;

    public function setUp(): void
    {
        $this->matcher = new Matcher();

        $request = new ConsumerRequest();
        $request
            ->setMethod('PUT')
            ->setPath('/users/1')
            // A query value that is either a number, or 'all'.
            // Every alternative is checked against the example value ('10'), so `equal('all')` would not work here.
            ->addQueryParameter('limit', $this->matcher->matchAny('10', [
                $this->matcher->regex(null, '^\d+$'),
                $this->matcher->regex(null, '^all$'),
            ]))
            // A header value that is either a number, or 'latest'
            ->addHeader('X-Api-Version', $this->matcher->matchAny('2', [
                $this->matcher->regex(null, '^\d+$'),
                $this->matcher->regex(null, '^latest$'),
            ]))
            ->addHeader('Content-Type', 'application/json')
            ->setBody([
                // A string, or null
                'nickname' => $this->matcher->likeOrNull('Fred'),
                // An object, or null. The rules inside the object still apply when it is not null.
                'address' => $this->matcher->likeOrNull([
                    'street' => 'Main St',
                    'number' => $this->matcher->integerV3(42),
                ]),
            ]);

        $response = new ProviderResponse();
        $response
            ->setStatus(200)
            ->addHeader('Content-Type', 'application/json')
            ->setBody([
                'id' => $this->matcher->integerV3(1),
                'nickname' => $this->matcher->likeOrNull('Fred'),
                // Same as `likeOrNull`, using `matchAny` and `nullValue` directly
                'address' => $this->matcher->matchAny(
                    [
                        'street' => 'Main St',
                        'number' => $this->matcher->integerV3(42),
                    ],
                    [
                        $this->matcher->like(null),
                        $this->matcher->nullValue(),
                    ]
                ),
            ]);

        $this->config = new MockServerConfig();
        $this->config
            ->setConsumer('orMatcherConsumer')
            ->setProvider('orMatcherProvider')
            ->setPactDir(__DIR__.'/../../../pacts')
            ->setPactSpecificationVersion('4.0.0');
        if ($logLevel = \getenv('PACT_LOGLEVEL')) {
            $this->config->setLogLevel($logLevel);
        }
        $this->builder = new InteractionBuilder($this->config);
        $this->builder
            ->uponReceiving('A put request to /users/{id}')
            ->with($request)
            ->willRespondWith($response);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function matchingRequests(): iterable
    {
        yield 'example values' => [['nickname' => 'Fred', 'address' => ['street' => 'Main St', 'number' => 42]], '10', '2'];
        yield 'first alternatives' => [['nickname' => 'Bob', 'address' => ['street' => 'High St', 'number' => 7]], '25', '3'];
        yield 'second alternatives' => [['nickname' => null, 'address' => null], 'all', 'latest'];
    }

    /**
     * @param array<string, mixed> $user
     */
    #[DataProvider('matchingRequests')]
    public function testMatchingRequest(array $user, string $limit, string $apiVersion): void
    {
        $service = new HttpClientService($this->config->getBaseUri());
        $response = $service->updateUser($user, $limit, $apiVersion);
        $verifyResult = $this->builder->verify();

        $this->assertTrue($verifyResult);
        $this->assertSame(200, $response->getStatusCode());
        // Only the example value is returned by the mock server, so the consumer code is only tested with it.
        $this->assertEquals([
            'id' => 1,
            'nickname' => 'Fred',
            'address' => ['street' => 'Main St', 'number' => 42],
        ], \json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR));

        $this->assertPactFileHasOrRules();
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function nonMatchingRequests(): iterable
    {
        $user = ['nickname' => 'Fred', 'address' => ['street' => 'Main St', 'number' => 42]];
        yield 'nickname is neither a string nor null' => [['nickname' => 123] + $user, '10', '2'];
        yield 'address is neither an object nor null' => [['address' => 'nowhere'] + $user, '10', '2'];
        yield 'address number is not an integer' => [['address' => ['street' => 'Main St', 'number' => '42']] + $user, '10', '2'];
        yield 'limit is neither a number nor all' => [$user, 'many', '2'];
        yield 'api version is neither a number nor latest' => [$user, '10', 'beta'];
    }

    /**
     * @param array<string, mixed> $user
     */
    #[DataProvider('nonMatchingRequests')]
    public function testNonMatchingRequest(array $user, string $limit, string $apiVersion): void
    {
        $service = new HttpClientService($this->config->getBaseUri());
        $response = $service->updateUser($user, $limit, $apiVersion);
        $verifyResult = $this->builder->verify();

        $this->assertFalse($verifyResult);
        $this->assertSame(500, $response->getStatusCode());
    }

    private function assertPactFileHasOrRules(): void
    {
        $pactFile = $this->config->getPactDir() . '/orMatcherConsumer-orMatcherProvider.json';
        $this->assertFileExists($pactFile);
        $pact = \json_decode((string) \file_get_contents($pactFile), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($pact);
        $this->assertIsArray($pact['interactions']);
        $this->assertCount(1, $pact['interactions']);
        $interaction = $pact['interactions'][0];
        $this->assertIsArray($interaction);
        $this->assertIsArray($interaction['request']);
        $this->assertIsArray($interaction['response']);

        $typeOrNull = ['combine' => 'OR', 'matchers' => [['match' => 'type'], ['match' => 'null']]];
        $numberOr = static fn (string $regex): array => [
            'combine' => 'OR',
            'matchers' => [['match' => 'regex', 'regex' => '^\d+$'], ['match' => 'regex', 'regex' => $regex]],
        ];

        $this->assertEquals([
            'body' => [
                '$.nickname' => $typeOrNull,
                '$.address' => $typeOrNull,
                '$.address.number' => ['combine' => 'AND', 'matchers' => [['match' => 'integer']]],
            ],
            'header' => [
                'X-Api-Version' => $numberOr('^latest$'),
            ],
            'query' => [
                'limit' => $numberOr('^all$'),
            ],
        ], $interaction['request']['matchingRules'] ?? null);

        $this->assertEquals([
            'body' => [
                '$.id' => ['combine' => 'AND', 'matchers' => [['match' => 'integer']]],
                '$.nickname' => $typeOrNull,
                '$.address' => $typeOrNull,
                '$.address.number' => ['combine' => 'AND', 'matchers' => [['match' => 'integer']]],
            ],
        ], $interaction['response']['matchingRules'] ?? null);
    }
}
