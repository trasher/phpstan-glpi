<?php

declare(strict_types=1);

namespace PHPStanGlpi\Rules;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\UnionType;
use PHPStanGlpi\Services\EarlierRulesAdoptionResolver;

/**
 * Forbids building a raw SQL fragment from anything but a literal string.
 *
 * `QueryExpression` is the only escape hatch that injects verbatim SQL into a query. It is
 * legitimate for a hardcoded fragment, but as soon as the fragment is assembled at runtime
 * the safety of the whole statement depends on the caller, and nothing can verify it.
 *
 * Identifiers belong in `QueryIdentifier`, values in `QueryValue` (or in the `values:`
 * argument, bound as statement parameters), and SQL fragments in `QueryFunction` /
 * `QuerySubQuery`.
 *
 * @implements Rule<New_>
 */
final class ForbidNonLiteralSqlExpressionRule implements Rule
{
    /**
     * Version that made the recommended alternatives available.
     */
    private const AVAILABLE_SINCE = '12.0.0-dev';

    /**
     * Version from which the rule is unconditionally applied.
     */
    private const ENFORCED_SINCE = '13.0.0-dev';

    /**
     * Classes whose first constructor argument is raw SQL, mapped to that argument name.
     *
     * @var array<string, string>
     */
    private const RAW_SQL_CLASSES = [
        'Glpi\DBAL\QueryExpression' => 'expression',
    ];

    /**
     * Interface implemented by the query elements that render their own SQL.
     */
    private const QUERY_ELEMENT_INTERFACE = 'Glpi\DBAL\QueryElementInterface';

    /**
     * A SQL fragment that is nothing but a reference to a column, optionally prefixed by its
     * table, e.g. `` `id` ``, `id`, `` `glpi_tickets`.`id` ``, `glpi_tickets.id`
     * or `` `glpi_tickets`.* ``.
     */
    private const IDENTIFIER_PATTERN
        = '/^\s*(?:`[^`]+`|[a-z_][a-z0-9_$]*)(?:\.(?:`[^`]+`|[a-z_][a-z0-9_$]*|\*))?\s*$/i';

    /**
     * SQL keywords that match the identifier pattern but are values, not identifiers.
     *
     * @var list<string>
     */
    private const RESERVED_LITERALS = [
        'CURRENT_DATE',
        'CURRENT_TIME',
        'CURRENT_TIMESTAMP',
        'CURRENT_USER',
        'DEFAULT',
        'DUAL', // the MySQL pseudo-table, that must not be quoted
        'FALSE',
        'LOCALTIME',
        'LOCALTIMESTAMP',
        'NULL',
        'TRUE',
        'UNKNOWN',
        'UTC_DATE',
        'UTC_TIME',
        'UTC_TIMESTAMP',
    ];

    private EarlierRulesAdoptionResolver $earlierRulesAdoptionResolver;

    private bool $treatPhpDocTypesAsCertain;

    public function __construct(
        EarlierRulesAdoptionResolver $earlierRulesAdoptionResolver,
        bool $treatPhpDocTypesAsCertain
    ) {
        $this->earlierRulesAdoptionResolver = $earlierRulesAdoptionResolver;
        $this->treatPhpDocTypesAsCertain = $treatPhpDocTypesAsCertain;
    }

    public function getNodeType(): string
    {
        return New_::class;
    }

    /**
     * @param New_ $node
     * @param Scope $scope
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$this->earlierRulesAdoptionResolver->isRuleEnabled(self::ENFORCED_SINCE, self::AVAILABLE_SINCE)) {
            return [];
        }

        if (!($node->class instanceof Name)) {
            // Only direct instanciations (e.g. `new QueryExpression()`) are handled here.
            return [];
        }

        $class_name = $scope->resolveName($node->class);
        $arg_name = self::RAW_SQL_CLASSES[$class_name] ?? null;
        if ($arg_name === null) {
            return [];
        }

        $arg = $this->getArgument($node->args, $arg_name);
        if ($arg === null) {
            // No argument, or arguments spread from an array: nothing to analyse.
            return [];
        }

        $type = $this->treatPhpDocTypesAsCertain
            ? $scope->getType($arg->value)
            : $scope->getNativeType($arg->value);

        if (!$this->isTypeSafe($type)) {
            return [
                RuleErrorBuilder::message(
                    \sprintf(
                        'Building a %s from a non-literal SQL string is forbidden.'
                        . ' Use `QueryIdentifier` for an identifier, `QueryValue` for a value,'
                        . ' `QueryFunction` or `QuerySubQuery` for a SQL fragment,'
                        . ' or pass the dynamic parts through the `values:` argument to have them bound'
                        . ' as statement parameters.',
                        $class_name
                    )
                )
                    ->identifier('glpi.forbidNonLiteralSqlExpression')
                    ->line($arg->getStartLine())
                    ->build(),
            ];
        }

        foreach ($type->getConstantStrings() as $constant_string) {
            if ($this->isBareIdentifier($constant_string->getValue())) {
                return [
                    RuleErrorBuilder::message(
                        \sprintf(
                            'Building a %s from a bare SQL identifier is forbidden.'
                            . ' Use `QueryIdentifier` instead.',
                            $class_name
                        )
                    )
                        ->identifier('glpi.forbidSqlExpressionIdentifier')
                        ->line($arg->getStartLine())
                        ->build(),
                ];
            }
        }

        return [];
    }

    private function isTypeSafe(Type $type): bool
    {
        if ($type instanceof UnionType) {
            // A union type is safe only if all of the possible types are safe.
            foreach ($type->getTypes() as $sub_type) {
                if (!$this->isTypeSafe($sub_type)) {
                    return false;
                }
            }
            return true;
        }

        $query_element_type = new ObjectType(self::QUERY_ELEMENT_INTERFACE);
        if ($query_element_type->isSuperTypeOf($type)->yes()) {
            // A query element renders its own SQL and carries its own parameters.
            return true;
        }

        // A literal SQL string. Concatenation and interpolation of constants are folded into a
        // constant string by PHPStan, so `'FOO(' . self::SEP . ')'` is accepted too.
        return \count($type->getConstantStrings()) === 1;
    }

    /**
     * Indicates whether the given SQL fragment is nothing but an identifier reference, that
     * should be built with a `QueryIdentifier`.
     */
    private function isBareIdentifier(string $expression): bool
    {
        if (\preg_match(self::IDENTIFIER_PATTERN, $expression) !== 1) {
            return false;
        }

        // A single unquoted token may be a reserved literal (e.g. `NULL`), not an identifier.
        return !\in_array(\strtoupper(\trim($expression)), self::RESERVED_LITERALS, true);
    }

    /**
     * @param array<Arg|Node\VariadicPlaceholder> $args
     */
    private function getArgument(array $args, string $name): ?Arg
    {
        foreach ($args as $arg) {
            if (!($arg instanceof Arg)) {
                // First-class callable syntax (`...`): there is no argument to analyse.
                return null;
            }
            if ($arg->unpack) {
                // Argument unpacking (`...$args`): the positions cannot be resolved statically.
                return null;
            }
            if ($arg->name === null || $arg->name->name === $name) {
                return $arg;
            }
        }

        return null;
    }
}
