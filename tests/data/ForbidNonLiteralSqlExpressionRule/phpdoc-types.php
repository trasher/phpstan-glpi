<?php

use Glpi\DBAL\QueryExpression;

class PhpDocTypes
{
    public function phpDocTypes(mixed $value): void
    {
        /** @var QueryExpression $element */
        $element = $value;
        new QueryExpression($element);

        /** @var 'NOW()' $literal */
        $literal = $value;
        new QueryExpression($literal);
    }
}
