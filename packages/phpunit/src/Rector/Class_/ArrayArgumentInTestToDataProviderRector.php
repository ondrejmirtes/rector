<?php

declare(strict_types=1);

namespace Rector\PHPUnit\Rector\Class_;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\PhpDocParser\Ast\PhpDoc\GenericTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\Type\ArrayType;
use PHPStan\Type\MixedType;
use PHPStan\Type\Type;
use PHPStan\Type\UnionType;
use Rector\AttributeAwarePhpDoc\Ast\PhpDoc\AttributeAwareParamTagValueNode;
use Rector\BetterPhpDocParser\PhpDocInfo\PhpDocInfo;
use Rector\Core\Exception\ShouldNotHappenException;
use Rector\Core\Rector\AbstractPHPUnitRector;
use Rector\Core\RectorDefinition\ConfiguredCodeSample;
use Rector\Core\RectorDefinition\RectorDefinition;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\NodeTypeResolver\PHPStan\Type\TypeFactory;
use Rector\PHPUnit\NodeFactory\DataProviderClassMethodFactory;
use Rector\PHPUnit\ValueObject\DataProviderClassMethodRecipe;
use Rector\PHPUnit\ValueObject\ParamAndArgValueObject;

/**
 * @see \Rector\PHPUnit\Tests\Rector\Class_\ArrayArgumentInTestToDataProviderRector\ArrayArgumentInTestToDataProviderRectorTest
 *
 * @see why → https://blog.martinhujer.cz/how-to-use-data-providers-in-phpunit/
 */
final class ArrayArgumentInTestToDataProviderRector extends AbstractPHPUnitRector
{
    /**
     * @var mixed[]
     */
    private $configuration = [];

    /**
     * @var DataProviderClassMethodRecipe[]
     */
    private $dataProviderClassMethodRecipes = [];

    /**
     * @var DataProviderClassMethodFactory
     */
    private $dataProviderClassMethodFactory;

    /**
     * @var TypeFactory
     */
    private $typeFactory;

    /**
     * @param mixed[] $configuration
     */
    public function __construct(
        DataProviderClassMethodFactory $dataProviderClassMethodFactory,
        TypeFactory $typeFactory,
        array $configuration = []
    ) {
        $this->dataProviderClassMethodFactory = $dataProviderClassMethodFactory;
        $this->typeFactory = $typeFactory;
        $this->configuration = $configuration;
    }

    public function getDefinition(): RectorDefinition
    {
        return new RectorDefinition('Move array argument from tests into data provider [configurable]', [
            new ConfiguredCodeSample(
                <<<'PHP'
class SomeServiceTest extends \PHPUnit\Framework\TestCase
{
    public function test()
    {
        $this->doTestMultiple([1, 2, 3]);
    }
}
PHP
                ,
                <<<'PHP'
class SomeServiceTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @dataProvider provideData()
     */
    public function test(int $number)
    {
        $this->doTestSingle($number);
    }

    public function provideData(): \Iterator
    {
        yield [1];
        yield [2];
        yield [3];
    }
}
PHP

                ,
                [
                    '$configuration' => [
                        [
                            'class' => 'PHPUnit\Framework\TestCase',
                            'old_method' => 'doTestMultiple',
                            'new_method' => 'doTestSingle',
                            'variable_name' => 'number',
                        ],
                    ],
                ]
            ),
        ]);
    }

    /**
     * @return string[]
     */
    public function getNodeTypes(): array
    {
        return [Class_::class];
    }

    /**
     * @param Class_ $node
     */
    public function refactor(Node $node): ?Node
    {
        $this->ensureConfigurationIsSet($this->configuration);

        if (! $this->isInTestClass($node)) {
            return null;
        }

        $this->dataProviderClassMethodRecipes = [];

        $this->traverseNodesWithCallable($node->stmts, function (Node $node) {
            if (! $node instanceof MethodCall) {
                return null;
            }

            foreach ($this->configuration as $singleConfiguration) {
                if (! $this->isMethodCallMatch($node, $singleConfiguration)) {
                    continue;
                }

                if (count($node->args) !== 1) {
                    throw new ShouldNotHappenException();
                }

                // resolve value types
                $firstArgumentValue = $node->args[0]->value;
                if (! $firstArgumentValue instanceof Array_) {
                    // nothing we can do
                    return null;
                }

                // rename method to new one handling non-array input
                $node->name = new Identifier($singleConfiguration['new_method']);

                $dataProviderMethodName = $this->createDataProviderMethodName($node);

                $this->dataProviderClassMethodRecipes[] = new DataProviderClassMethodRecipe(
                    $dataProviderMethodName,
                    $node->args,
                    $this->resolveUniqueArrayStaticType($firstArgumentValue)
                );

                $node->args = [];
                $paramAndArgs = $this->collectParamAndArgsFromArray(
                    $firstArgumentValue,
                    $singleConfiguration['variable_name']
                );
                foreach ($paramAndArgs as $paramAndArg) {
                    $node->args[] = new Arg($paramAndArg->getVariable());
                }

                /** @var ClassMethod $classMethod */
                $classMethod = $node->getAttribute(AttributeKey::METHOD_NODE);
                $this->refactorTestClassMethodParams($classMethod, $paramAndArgs);

                // add data provider annotation
                $dataProviderTagNode = $this->createDataProviderTagNode($dataProviderMethodName);

                /** @var PhpDocInfo $phpDocInfo */
                $phpDocInfo = $classMethod->getAttribute(AttributeKey::PHP_DOC_INFO);
                $phpDocInfo->addPhpDocTagNode($dataProviderTagNode);

                return null;
            }

            return null;
        });

        if ($this->dataProviderClassMethodRecipes === []) {
            return null;
        }

        $dataProviderClassMethods = $this->createDataProviderClassMethodsFromRecipes();

        $node->stmts = array_merge($node->stmts, $dataProviderClassMethods);

        return $node;
    }

    /**
     * @param mixed[] $configuration
     */
    private function ensureConfigurationIsSet(array $configuration): void
    {
        if ($configuration !== []) {
            return;
        }

        throw new ShouldNotHappenException(sprintf(
            'Add configuration via "%s" argument for "%s"',
            '$configuration',
            self::class
        ));
    }

    /**
     * @param string[] $singleConfiguration
     */
    private function isMethodCallMatch(MethodCall $methodCall, array $singleConfiguration): bool
    {
        if (! $this->isObjectType($methodCall->var, $singleConfiguration['class'])) {
            return false;
        }

        return $this->isName($methodCall->name, $singleConfiguration['old_method']);
    }

    private function createDataProviderMethodName(Node $node): string
    {
        /** @var string $methodName */
        $methodName = $node->getAttribute(AttributeKey::METHOD_NAME);

        return 'provideDataFor' . ucfirst($methodName);
    }

    private function resolveUniqueArrayStaticType(Array_ $array): Type
    {
        $isNestedArray = $this->isNestedArray($array);

        $uniqueArrayStaticType = $this->resolveUniqueArrayStaticTypes($array);

        if ($isNestedArray && $uniqueArrayStaticType instanceof ArrayType) {
            // unwrap one level up
            return $uniqueArrayStaticType->getItemType();
        }

        return $uniqueArrayStaticType;
    }

    /**
     * @return ParamAndArgValueObject[]
     */
    private function collectParamAndArgsFromArray(Array_ $array, string $variableName): array
    {
        // multiple arguments
        $i = 1;

        $paramAndArgs = [];

        $isNestedArray = $this->isNestedArray($array);

        $itemsStaticType = $this->resolveItemStaticType($array, $isNestedArray);

        if (! $isNestedArray) {
            foreach ($array->items as $arrayItem) {
                $variable = new Variable($variableName . ($i === 1 ? '' : $i));

                $paramAndArgs[] = new ParamAndArgValueObject($variable, $itemsStaticType);
                ++$i;

                if (! $arrayItem->value instanceof Array_) {
                    break;
                }
            }
        } else {
            foreach ($array->items as $arrayItem) {
                /** @var Array_ $nestedArray */
                $nestedArray = $arrayItem->value;
                foreach ($nestedArray->items as $nestedArrayItem) {
                    $variable = new Variable($variableName . ($i === 1 ? '' : $i));

                    $itemsStaticType = $this->getStaticType($nestedArrayItem->value);
                    $paramAndArgs[] = new ParamAndArgValueObject($variable, $itemsStaticType);
                    ++$i;
                }
            }
        }

        return $paramAndArgs;
    }

    /**
     * @param ParamAndArgValueObject[] $paramAndArgs
     */
    private function refactorTestClassMethodParams(ClassMethod $classMethod, array $paramAndArgs): void
    {
        $classMethod->params = $this->createParams($paramAndArgs);

        /** @var PhpDocInfo $phpDocInfo */
        $phpDocInfo = $classMethod->getAttribute(AttributeKey::PHP_DOC_INFO);

        foreach ($paramAndArgs as $paramAndArg) {
            $staticType = $paramAndArg->getType();

            if (! $staticType instanceof UnionType) {
                continue;
            }

            /** @var string $paramName */
            $paramName = $this->getName($paramAndArg->getVariable());

            /** @var TypeNode $staticTypeNode */
            $staticTypeNode = $this->staticTypeMapper->mapPHPStanTypeToPHPStanPhpDocTypeNode($staticType);

            $paramTagValueNode = $this->createParamTagNode($paramName, $staticTypeNode);
            $phpDocInfo->addTagValueNode($paramTagValueNode);
        }
    }

    private function createDataProviderTagNode(string $dataProviderMethodName): PhpDocTagNode
    {
        return new PhpDocTagNode('@dataProvider', new GenericTagValueNode($dataProviderMethodName . '()'));
    }

    /**
     * @return ClassMethod[]
     */
    private function createDataProviderClassMethodsFromRecipes(): array
    {
        $dataProviderClassMethods = [];

        foreach ($this->dataProviderClassMethodRecipes as $dataProviderClassMethodRecipe) {
            $dataProviderClassMethods[] = $this->dataProviderClassMethodFactory->createFromRecipe(
                $dataProviderClassMethodRecipe
            );
        }

        return $dataProviderClassMethods;
    }

    private function isNestedArray(Array_ $array): bool
    {
        foreach ($array->items as $arrayItem) {
            if ($arrayItem->value instanceof Array_) {
                return true;
            }
        }

        return false;
    }

    private function resolveUniqueArrayStaticTypes(Array_ $array): Type
    {
        $itemStaticTypes = [];
        foreach ($array->items as $arrayItem) {
            $arrayItemStaticType = $this->getStaticType($arrayItem->value);
            if ($arrayItemStaticType instanceof MixedType) {
                continue;
            }

            $itemStaticTypes[] = new ArrayType(new MixedType(), $arrayItemStaticType);
        }

        return $this->typeFactory->createMixedPassedOrUnionType($itemStaticTypes);
    }

    private function resolveItemStaticType(Array_ $array, bool $isNestedArray): Type
    {
        $staticTypes = [];
        if (! $isNestedArray) {
            foreach ($array->items as $arrayItem) {
                $arrayItemStaticType = $this->getStaticType($arrayItem->value);
                if ($arrayItemStaticType) {
                    $staticTypes[] = $arrayItemStaticType;
                }
            }
        }

        return $this->typeFactory->createMixedPassedOrUnionType($staticTypes);
    }

    /**
     * @param ParamAndArgValueObject[] $paramAndArgs
     * @return Param[]
     */
    private function createParams(array $paramAndArgs): array
    {
        $params = [];
        foreach ($paramAndArgs as $paramAndArg) {
            $param = new Param($paramAndArg->getVariable());

            $staticType = $paramAndArg->getType();

            if ($staticType !== null && ! $staticType instanceof UnionType) {
                $phpNodeType = $this->staticTypeMapper->mapPHPStanTypeToPhpParserNode($staticType);
                if ($phpNodeType !== null) {
                    $param->type = $phpNodeType;
                }
            }

            $params[] = $param;
        }

        return $params;
    }

    private function createParamTagNode(string $name, TypeNode $typeNode): AttributeAwareParamTagValueNode
    {
        return new AttributeAwareParamTagValueNode($typeNode, false, '$' . $name, '', false);
    }
}
