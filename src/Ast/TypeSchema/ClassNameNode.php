<?php declare(strict_types = 1);

namespace Shredio\TypeSchemaCompiler\Ast\TypeSchema;

final readonly class ClassNameNode implements TypeSchemaNode
{

	/**
	 * @param class-string $value
	 */
	public function __construct(
		public string $value,
	)
	{
	}

}
