<?php declare(strict_types = 1);

namespace Tests\Unit;

use Shredio\TypeSchema\Context\TypeContext;
use Shredio\TypeSchemaCompiler\Attribute\CompileObjectMapper;
use Shredio\TypeSchemaCompiler\Attribute\CompilePropertyOptions;
use Tests\CompilerTestCase;

final class SpecialOptionsTest extends CompilerTestCase
{

	public function testPersonCompile(): void
	{
		$this->assertCompiledSameAsFile(
			__DIR__ . '/expected/special-options/ContextFactory.php',
			ContextFactory::class,
		);

		$this->assertCreatedMapperCount(1);
	}

	public function testPropertyBefore(): void
	{
		$this->assertCompiledSameAsFile(
			__DIR__ . '/expected/special-options/PropertyBefore.php',
			PropertyBefore::class,
		);

		$this->assertCreatedMapperCount(1);
	}

}

#[CompileObjectMapper(contextFactory: 'createContext')]
class ContextFactory
{
	public int $id;

	public static function createContext(TypeContext $context): TypeContext
	{
		return $context;
	}

}

class PropertyBefore
{

	#[CompilePropertyOptions(before: [PropertyBefore::class, 'handleNan'])]
	public float $value;

	public static function handleNan(mixed $valueToParse, TypeContext $context): mixed
	{
		if (is_float($valueToParse) && is_nan($valueToParse)) {
			return 0.0;
		}

		return $valueToParse;
	}

}
