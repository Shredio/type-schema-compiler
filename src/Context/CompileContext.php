<?php declare(strict_types = 1);

namespace Shredio\TypeSchemaCompiler\Context;

use Shredio\TypeSchemaCompiler\Attribute\CompileObjectMapper;

final readonly class CompileContext
{

	/**
	 * @param class-string $sourceClassName
	 */
	public function __construct(
		public string $sourceClassName,
		public CompileObjectMapper $attribute,
	)
	{
	}

}
