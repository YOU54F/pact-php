<?php

namespace PhpPact\Consumer\Matcher\Formatters;

use PhpPact\Consumer\Matcher\Exception\InvalidValueException;
use PhpPact\Consumer\Matcher\Model\MatcherInterface;
use PhpPact\Xml\XmlElement;

class XmlElementFormatter extends ValueOptionalFormatter
{
    /**
     * @return array<string, mixed>
     */
    public function format(MatcherInterface $matcher): array
    {
        $value = $matcher->getValue();
        if (!$value instanceof XmlElement) {
            throw new InvalidValueException('Value must be xml element');
        }

        $result = parent::format($matcher);
        $examples = $value->getExamples();

        if (null !== $examples) {
            $result['examples'] = $examples;
            $value->setExamples(null);
        }

        return $result;
    }
}
