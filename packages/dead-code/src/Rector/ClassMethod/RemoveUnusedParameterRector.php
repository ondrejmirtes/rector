<?php

declare(strict_types=1);

namespace Rector\DeadCode\Rector\ClassMethod;

use PhpParser\Node;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use Rector\Core\PhpParser\Node\Manipulator\ClassManipulator;
use Rector\Core\PhpParser\Node\Manipulator\ClassMethodManipulator;
use Rector\Core\Rector\AbstractRector;
use Rector\Core\RectorDefinition\CodeSample;
use Rector\Core\RectorDefinition\RectorDefinition;
use Rector\NodeTypeResolver\Node\AttributeKey;

/**
 * @see https://www.php.net/manual/en/function.compact.php
 * @see \Rector\DeadCode\Tests\Rector\ClassMethod\RemoveUnusedParameterRector\RemoveUnusedParameterRectorTest
 */
final class RemoveUnusedParameterRector extends AbstractRector
{
    /**
     * @var string[]
     */
    private $magicMethods = [
        '__call',
        '__callStatic',
        '__clone',
        '__debugInfo',
        '__destruct',
        '__get',
        '__invoke',
        '__isset',
        '__set',
        '__set_state',
        '__sleep',
        '__toString',
        '__unset',
        '__wakeup',
    ];

    /**
     * @var ClassManipulator
     */
    private $classManipulator;

    /**
     * @var ClassMethodManipulator
     */
    private $classMethodManipulator;

    public function __construct(
        ClassManipulator $classManipulator,
        ClassMethodManipulator $classMethodManipulator
    ) {
        $this->classManipulator = $classManipulator;
        $this->classMethodManipulator = $classMethodManipulator;
    }

    public function getDefinition(): RectorDefinition
    {
        return new RectorDefinition('Remove unused parameter, if not required by interface or parent class', [
            new CodeSample(
                <<<'PHP'
class SomeClass
{
    public function __construct($value, $value2)
    {
         $this->value = $value;
    }
}
PHP
                ,
                <<<'PHP'
class SomeClass
{
    public function __construct($value)
    {
         $this->value = $value;
    }
}
PHP
            ),
        ]);
    }

    /**
     * @return string[]
     */
    public function getNodeTypes(): array
    {
        return [ClassMethod::class];
    }

    /**
     * @param ClassMethod $node
     */
    public function refactor(Node $node): ?Node
    {
        $classNode = $node->getAttribute(AttributeKey::CLASS_NODE);
        if (! $classNode instanceof Class_ || $this->isAnonymousClass($classNode)) {
            return null;
        }

        if ($node->params === []) {
            return null;
        }

        if ($this->isNames($node, $this->magicMethods)) {
            return null;
        }

        $class = $this->getName($classNode);
        if ($class === null) {
            return null;
        }

        $methodName = $this->getName($node);
        if ($this->classManipulator->hasParentMethodOrInterface($class, $methodName)) {
            return null;
        }

        $childrenOfClass = $this->classLikeParsedNodesFinder->findChildrenOfClass($class);
        $unusedParameters = $this->getUnusedParameters($node, $methodName, $childrenOfClass);

        foreach ($childrenOfClass as $childClassNode) {
            $methodOfChild = $childClassNode->getMethod($methodName);
            if ($methodOfChild !== null) {
                $this->removeNodes($this->getParameterOverlap($methodOfChild->params, $unusedParameters));
            }
        }

        $this->removeNodes($unusedParameters);

        return $node;
    }

    /**
     * @param Class_[]    $childrenOfClass
     * @return Param[]
     */
    private function getUnusedParameters(ClassMethod $classMethod, string $methodName, array $childrenOfClass): array
    {
        $unusedParameters = $this->resolveUnusedParameters($classMethod);
        if ($unusedParameters === []) {
            return [];
        }

        foreach ($childrenOfClass as $childClassNode) {
            $methodOfChild = $childClassNode->getMethod($methodName);
            if ($methodOfChild !== null) {
                $unusedParameters = $this->getParameterOverlap(
                    $unusedParameters,
                    $this->resolveUnusedParameters($methodOfChild)
                );
            }
        }
        return $unusedParameters;
    }

    /**
     * @param Param[] $parameters1
     * @param Param[] $parameters2
     * @return Param[]
     */
    private function getParameterOverlap(array $parameters1, array $parameters2): array
    {
        return array_uintersect(
            $parameters1,
            $parameters2,
            function (Param $a, Param $b): int {
                return $this->areNodesEqual($a, $b) ? 0 : 1;
            }
        );
    }

    /**
     * @return Param[]
     */
    private function resolveUnusedParameters(ClassMethod $classMethod): array
    {
        $unusedParameters = [];

        foreach ((array) $classMethod->params as $i => $param) {
            if ($this->classMethodManipulator->isParameterUsedMethod($param, $classMethod)) {
                // reset to keep order of removed arguments, if not construtctor - probably autowired
                if (! $this->isName($classMethod, '__construct')) {
                    $unusedParameters = [];
                }

                continue;
            }

            $unusedParameters[$i] = $param;
        }

        return $unusedParameters;
    }
}
