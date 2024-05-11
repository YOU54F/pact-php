<?php

namespace PhpPact\Consumer\Matcher\Matchers;

use PhpPact\Consumer\Matcher\Formatters\Expression\SemverFormatter;
use PhpPact\Consumer\Matcher\Formatters\Json\HasGeneratorFormatter;
use PhpPact\Consumer\Matcher\Generators\Regex;
use PhpPact\Consumer\Matcher\Model\ExpressionFormatterInterface;
use PhpPact\Consumer\Matcher\Model\JsonFormatterInterface;

/**
 * Value must be valid based on the semver specification
 */
class Semver extends GeneratorAwareMatcher
{
    public function __construct(private ?string $value = null)
    {
        if ($value === null) {
            $this->setGenerator(new Regex('\d+\.\d+\.\d+'));
        }
        parent::__construct();
    }

    public function getType(): string
    {
        return 'semver';
    }

    protected function getAttributesData(): array
    {
        return [];
    }

    public function getValue(): ?string
    {
        return $this->value;
    }

    public function createJsonFormatter(): JsonFormatterInterface
    {
        return new HasGeneratorFormatter();
    }

    public function createExpressionFormatter(): ExpressionFormatterInterface
    {
        return new SemverFormatter();
    }
}
