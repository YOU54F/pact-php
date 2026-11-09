<?php

namespace PhpPactTest\Consumer\Matcher\Matchers;

use PhpPact\Consumer\Matcher\Exception\InvalidValueException;
use PhpPact\Consumer\Matcher\Exception\MatcherNotSupportedException;
use PhpPact\Consumer\Matcher\Formatters\Expression\ExpressionFormatter;
use PhpPact\Consumer\Matcher\Matchers\Integer;
use PhpPact\Consumer\Matcher\Matchers\MatchAll;
use PhpPact\Consumer\Matcher\Matchers\MatchAny;
use PhpPact\Consumer\Matcher\Matchers\NullValue;
use PhpPact\Consumer\Matcher\Matchers\Regex;
use PhpPact\Consumer\Matcher\Matchers\Type;
use PhpPact\Consumer\Matcher\Model\MatcherInterface;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

class MatchAnyTest extends TestCase
{
    public function testNestedMatchAnyMatchers(): void
    {
        $this->expectException(MatcherNotSupportedException::class);
        $this->expectExceptionMessage("Nested 'matchAny' or 'matchAll' matcher is not supported");
        new MatchAny('abc', [new MatchAny('abc', [new Type('abc')])]);
    }

    public function testNestedMatchAllMatchers(): void
    {
        $this->expectException(MatcherNotSupportedException::class);
        $this->expectExceptionMessage("Nested 'matchAny' or 'matchAll' matcher is not supported");
        new MatchAny([], [new MatchAll([], [new Type(123)])]);
    }

    public function testMatcherAsValue(): void
    {
        $this->expectException(MatcherNotSupportedException::class);
        $this->expectExceptionMessage("Value of 'matchAny' matcher can not be a matcher, add it to the list of matchers instead");
        new MatchAny(new Regex('^\d+$', '100'), [new NullValue()]);
    }

    public function testMissingMatchers(): void
    {
        $this->expectException(InvalidValueException::class);
        $this->expectExceptionMessage('Matchers should not be empty');
        new MatchAny('value', []);
    }

    #[TestWith([new MatchAny('Fred', [new Type(null), new NullValue()]), <<<JSON
        {
            "pact:matcher:type": [
                {
                    "pact:matcher:type": "type",
                    "value": null
                },
                {
                    "pact:matcher:type": "null"
                }
            ],
            "pact:matcher:combine": "OR",
            "value": "Fred"
        }
        JSON
    ])]
    #[TestWith([new MatchAny(null, [new Regex('^\d+$', '100'), new NullValue()]), <<<JSON
        {
            "pact:matcher:type": [
                {
                    "pact:matcher:type": "regex",
                    "regex": "^\\\\d+$",
                    "value": "100"
                },
                {
                    "pact:matcher:type": "null"
                }
            ],
            "pact:matcher:combine": "OR",
            "value": null
        }
        JSON
    ])]
    #[TestWith([new MatchAny(['street' => 'Main St', 'number' => new Integer(42)], [new Type(null), new NullValue()]), <<<JSON
        {
            "pact:matcher:type": [
                {
                    "pact:matcher:type": "type",
                    "value": null
                },
                {
                    "pact:matcher:type": "null"
                }
            ],
            "pact:matcher:combine": "OR",
            "value": {
                "street": "Main St",
                "number": {
                    "pact:matcher:type": "integer",
                    "value": 42
                }
            }
        }
        JSON
    ])]
    public function testFormatJson(MatcherInterface $matcher, string $json): void
    {
        $jsonEncoded = json_encode($matcher);
        $this->assertIsString($jsonEncoded);
        $this->assertJsonStringEqualsJsonString(
            $json,
            $jsonEncoded
        );
    }

    public function testFormatExpressionIsNotSupported(): void
    {
        $matcher = (new MatchAny('Fred', [new Type(null), new NullValue()]))->withFormatter(new ExpressionFormatter());
        $this->expectException(MatcherNotSupportedException::class);
        $this->expectExceptionMessage('Matcher does not support expression format');
        json_encode($matcher);
    }
}
