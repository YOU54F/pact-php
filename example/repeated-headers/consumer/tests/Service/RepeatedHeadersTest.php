<?php

namespace RepeatedHeadersConsumer\Tests\Service;

use PhpPact\Consumer\InteractionBuilder;
use PhpPact\Consumer\Matcher\Matcher;
use PhpPact\Consumer\Matcher\Model\MatcherInterface;
use PhpPact\Consumer\Model\ConsumerRequest;
use PhpPact\Consumer\Model\ProviderResponse;
use PhpPact\Log\Enum\LogLevel;
use PhpPact\Log\Logger;
use PhpPact\Log\Model\Buffer;
use PhpPact\Standalone\MockService\MockServerConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RepeatedHeadersConsumer\Service\HttpClientService;

/**
 * Repeated request headers, matched by the mock server following RFC 9110 §5.3:
 * a header sent as several field lines is equivalent to one line with the
 * values joined by ", ".
 *
 * The expected values are added one per index, by passing an array to `addHeader`.
 * The request is sent over a raw socket so that each field line reaches the
 * mock server as written.
 */
class RepeatedHeadersTest extends TestCase
{
    private const DEPRECATION_WARNING = 'RFC 9110 §5.3';

    private static Matcher $matcher;

    public static function setUpBeforeClass(): void
    {
        self::$matcher = new Matcher();

        // Capture the FFI logs in memory, so the tests can assert on warnings.
        // The buffer sink must be applied before any mock server is started.
        $logger = Logger::instance();
        $logger->attach(new Buffer(LogLevel::WARN));
        $logger->apply();
    }

    /**
     * @return iterable<string, array{string, array<MatcherInterface|string>, array<array{string, string}>, bool}>
     */
    public static function repeatedHeaderProvider(): iterable
    {
        yield 'H1 two field lines match two values' => [
            'X-Request',
            ['a', 'b'],
            [['X-Request', 'a'], ['X-Request', 'b']],
            true,
        ];
        yield 'H2 one comma-joined field line matches two values' => [
            'X-Request',
            ['a', 'b'],
            [['X-Request', 'a, b']],
            true,
        ];
        yield 'H3 values containing commas, one per field line' => [
            'X-Sunset',
            ['Sat, 01 Jan 2028 00:00:00 GMT', 'Sun, 02 Jan 2028 00:00:00 GMT'],
            [['X-Sunset', 'Sat, 01 Jan 2028 00:00:00 GMT'], ['X-Sunset', 'Sun, 02 Jan 2028 00:00:00 GMT']],
            true,
        ];
        yield 'H4 Content-Type is not a list, so a comma-joined line does not match' => [
            'Content-Type',
            ['text/plain', 'text/html'],
            [['Content-Type', 'text/plain, text/html']],
            false,
        ];
        yield 'H5 Cache-Control comma-joined field line matches two values' => [
            'Cache-Control',
            ['no-cache', 'no-store'],
            [['Cache-Control', 'no-cache, no-store']],
            true,
        ];
        yield 'H8a Link values containing commas, one per field line' => [
            'Link',
            ['<https://a.example/x,y>; rel="a"', '<https://b.example/>; rel="b"'],
            [['Link', '<https://a.example/x,y>; rel="a"'], ['Link', '<https://b.example/>; rel="b"']],
            true,
        ];
        yield 'H8b Link values containing commas, one comma-joined field line' => [
            'Link',
            ['<https://a.example/x,y>; rel="a"', '<https://b.example/>; rel="b"'],
            [['Link', '<https://a.example/x,y>; rel="a", <https://b.example/>; rel="b"']],
            true,
        ];
    }

    /**
     * @param array<MatcherInterface|string> $expectedValues
     * @param array<array{string, string}>   $fieldLines
     */
    #[DataProvider('repeatedHeaderProvider')]
    public function testRepeatedHeader(string $header, array $expectedValues, array $fieldLines, bool $shouldMatch): void
    {
        [$status, $matched, $mismatches] = $this->runInteraction((string) $this->dataName(), $header, $expectedValues, $fieldLines);

        if ($shouldMatch) {
            $this->assertSame(200, $status);
            $this->assertTrue($matched, $mismatches);
        } else {
            $this->assertNotSame(200, $status);
            $this->assertFalse($matched);
            $this->assertNotEmpty(json_decode($mismatches, true), $mismatches);
        }
    }

    public function testH6aRegexMatcherPerIndexMatchesWithoutWarning(): void
    {
        $regex = '^first-\d+$';
        [$status, $matched, $mismatches, $logs] = $this->runInteraction(
            'H6a one regex matcher per index',
            'X-Request',
            [self::$matcher->regex('first-1', $regex), self::$matcher->regex('first-2', $regex)],
            [['X-Request', 'first-42'], ['X-Request', 'first-99']],
        );

        $this->assertSame(200, $status);
        $this->assertTrue($matched, $mismatches);
        $this->assertStringNotContainsString(self::DEPRECATION_WARNING, $logs);
    }

    public function testH6bQuotedValueMatchesPerLineWithDeprecationWarning(): void
    {
        [$status, $matched, $mismatches, $logs] = $this->runInteraction(
            'H6b quoted value containing a comma, one per field line',
            'X-Request',
            ['"x , y"', 'z'],
            [['X-Request', '"x, y"'], ['X-Request', 'z']],
        );

        $this->assertSame(200, $status);
        $this->assertTrue($matched, $mismatches);
        $this->assertStringContainsString(self::DEPRECATION_WARNING, $logs);
        $this->assertStringContainsString('only matched when its field lines were compared one at a time', $logs);
    }

    /**
     * @param array<MatcherInterface|string> $expectedValues
     * @param array<array{string, string}>   $fieldLines
     *
     * @return array{int, bool, string, string} status, matched, mismatches, mock server logs
     */
    private function runInteraction(string $description, string $header, array $expectedValues, array $fieldLines): array
    {
        $request = new ConsumerRequest();
        $request
            ->setMethod('GET')
            ->setPath('/headers')
            ->addHeader($header, $expectedValues);

        $response = new ProviderResponse();
        $response->setStatus(200);

        $config = new MockServerConfig();
        $config
            ->setConsumer('repeatedHeadersConsumer')
            ->setProvider('repeatedHeadersProvider')
            ->setPactSpecificationVersion('3.0.0')
            ->setPactDir(__DIR__ . '/../../../pacts');

        $builder = new InteractionBuilder($config);
        $builder
            ->uponReceiving($description)
            ->with($request)
            ->willRespondWith($response);

        $status = (new HttpClientService($config->getBaseUri()))->getHeaders($fieldLines);

        // The mock server is cleaned up by `verify()`. Its mismatches and logs (it logs
        // to its own buffer, not the one returned by `Logger::fetchBuffer()`) are kept
        // in the verify result.
        $matched = $builder->verify();
        $result = $builder->getVerifyResult();
        $this->assertNotNull($result);

        return [$status, $matched, $result->mismatches, $result->logs];
    }
}
