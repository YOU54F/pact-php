<?php

namespace PhpPact\Consumer\Matcher\Matchers;

use PhpPact\Consumer\Matcher\Exception\InvalidValueException;
use PhpPact\Consumer\Matcher\Exception\MatcherNotSupportedException;
use PhpPact\Consumer\Matcher\Model\Attributes;
use PhpPact\Consumer\Matcher\Model\Matcher\JsonFormattableInterface;
use PhpPact\Consumer\Matcher\Model\MatcherInterface;

/**
 * Match if at least one of the matchers matches (matching rules combined with OR).
 *
 * Only the example value is used in the consumer test, so the other alternatives are never exercised
 * against the consumer code.
 *
 * Requires a pact_ffi version that supports the `pact:matcher:combine` attribute.
 * TODO: replace with the pact_ffi version that ships pact-foundation/pact-reference#601
 *
 * Matching expressions (used by plugins) can not combine matchers with OR, so this matcher only supports
 * the JSON (and XML) formats.
 */
class MatchAny extends AbstractMatcher implements JsonFormattableInterface
{
    /**
     * @param MatcherInterface[] $matchers
     */
    public function __construct(private mixed $value, private array $matchers)
    {
        if ($value instanceof MatcherInterface) {
            throw new MatcherNotSupportedException("Value of 'matchAny' matcher can not be a matcher, add it to the list of matchers instead");
        }
        $this->setMatchers($matchers);
        parent::__construct();
    }

    public function formatJson(): Attributes
    {
        return new Attributes([
            'pact:matcher:type' => array_map(
                $this->getFormatter()->format(...),
                $this->matchers
            ),
            'pact:matcher:combine' => 'OR',
            'value' => $this->value,
        ]);
    }

    /**
     * @param MatcherInterface[] $matchers
     */
    private function setMatchers(array $matchers): void
    {
        if (empty($matchers)) {
            throw new InvalidValueException('Matchers should not be empty');
        }
        $this->matchers = [];
        foreach ($matchers as $matcher) {
            if ($matcher instanceof self || $matcher instanceof MatchAll) {
                throw new MatcherNotSupportedException("Nested 'matchAny' or 'matchAll' matcher is not supported");
            }
            $this->addMatcher($matcher);
        }
    }

    private function addMatcher(MatcherInterface $matcher): void
    {
        $this->matchers[] = $matcher;
    }
}
