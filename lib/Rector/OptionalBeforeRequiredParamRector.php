<?php

declare(strict_types=1);

namespace PhpUp\Rector;

use PhpParser\Node;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\ArrowFunction;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * “可选参数写在必选参数前面”（PHP 8.0 起弃用）。
 * PHP 8 早已把这种前置可选参数当成必选参数处理（调用时必须传），所以去掉它的默认值
 * 与 PHP 8 的实际行为完全一致；对于 `Type $x = null`，PHP 会把类型视为可空，
 * 因此改成 `?Type $x`，保证传 null 仍然合法。
 */
final class OptionalBeforeRequiredParamRector extends AbstractRector
{
    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Drop default value of optional params declared before required ones', [
            new CodeSample('function f($a = 1, $b) {}', 'function f($a, $b) {}'),
        ]);
    }

    public function getNodeTypes(): array
    {
        return [ClassMethod::class, Function_::class, Closure::class, ArrowFunction::class];
    }

    public function refactor(Node $node): ?Node
    {
        $params = $node->params;
        $lastRequired = -1;
        foreach ($params as $i => $param) {
            if ($param->default === null && !$param->variadic) {
                $lastRequired = $i;
            }
        }
        $changed = false;
        for ($i = 0; $i < $lastRequired; $i++) {
            $param = $params[$i];
            if ($param->default === null) {
                continue;
            }
            $isNullDefault = $param->default instanceof ConstFetch
                && strtolower($param->default->name->toString()) === 'null';
            if ($isNullDefault && ($param->type instanceof Identifier || $param->type instanceof Name)
                && !in_array(strtolower($param->type->toString()), ['mixed', 'null'], true)) {
                $param->type = new NullableType($param->type);
            }
            $param->default = null;
            $changed = true;
        }
        return $changed ? $node : null;
    }
}
