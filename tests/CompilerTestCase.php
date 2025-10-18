<?php declare(strict_types = 1);

namespace Tests;

use JetBrains\PhpStorm\Language;
use Nette\PhpGenerator\PhpNamespace;
use ReflectionClass;
use Shredio\TypeSchema\Mapper\Jit\ClassMapperCompileHashedTargetProvider;
use Shredio\TypeSchema\Mapper\Jit\ObjectMapperCompileHashedInfoProvider;
use Shredio\TypeSchema\Mapper\Jit\ObjectMapperCompilerContext;
use Shredio\TypeSchemaCompiler\Ast\TypeSchema\TypeSchemaCodeFormatter;
use Shredio\TypeSchemaCompiler\CompiledProperty;
use Shredio\TypeSchemaCompiler\MapperCompiler;
use Shredio\TypeSchemaCompiler\NamespaceResolver;

abstract class CompilerTestCase extends TestCase
{

	/**
	 * @return array<string, array{ non-empty-string, bool }
	 */
	protected function compileAnonymous(object $object): array
	{
		$compiler = MapperCompiler::create();
		$formatter = new TypeSchemaCodeFormatter(new NamespaceResolver(new PhpNamespace('TestNamespace')));
		$properties = $compiler->compileOnlyProperties(new ReflectionClass($object), new ObjectMapperCompilerContext(
			new ClassMapperCompileHashedTargetProvider(__DIR__ . '/tmp', 'TestNamespace\\Mapper\\%s'),
			fn () => false,
		));
		foreach ($properties as $property) {
			$this->assertFalse($property->isInConstructor, sprintf('Property %s is in constructor', $property->name));
		}

		return array_map(
			fn (CompiledProperty $property) => [$formatter->format($property->typeSchemaNode), $property->isRequired],
			$properties
		);
	}

	/**
	 * @return array{ non-empty-string, bool }
	 */
	protected function requiredProperty(#[Language('PHP')] string $code): array
	{
		return [$code, true];
	}

	/**
	 * @return array{ non-empty-string, bool }
	 */
	protected function optionalProperty(#[Language('PHP')] string $code): array
	{
		return [$code, false];
	}

}
