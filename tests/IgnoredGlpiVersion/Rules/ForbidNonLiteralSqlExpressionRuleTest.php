<?php

declare(strict_types=1);

namespace PHPStanGlpi\Tests\IgnoredGlpiVersion\Rules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPStanGlpi\Rules\ForbidNonLiteralSqlExpressionRule;
use PHPStanGlpi\Tests\IgnoredGlpiVersion\TestIgnoredRuleTrait;
use PHPStanGlpi\Tests\TestTrait;

/**
 * @extends RuleTestCase<ForbidNonLiteralSqlExpressionRule>
 */
class ForbidNonLiteralSqlExpressionRuleTest extends RuleTestCase
{
    use TestIgnoredRuleTrait;
    use TestTrait;

    protected function getRule(): Rule
    {
        return new ForbidNonLiteralSqlExpressionRule(
            $this->getGlpiVersionResolver('11.0.0'), // should be ignored in GLPI < 12.0.0
            true
        );
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [
            __DIR__ . '/../../data/query-element-stubs.neon',
        ];
    }
}
