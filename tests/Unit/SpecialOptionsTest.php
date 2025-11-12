<?php declare(strict_types = 1);

namespace Tests\Unit;

use Shredio\TypeSchema\Context\TypeContext;
use Shredio\TypeSchemaCompiler\Attribute\CompileObjectMapper;
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
