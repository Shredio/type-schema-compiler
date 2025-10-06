<?php declare(strict_types = 1);

namespace Shredio\TypeSchemaCompiler\Ast\TypeSchema;

final readonly class NewClassNode implements TypeSchemaNode
{

	/**
	 * @param class-string $className
	 */
	public function __construct(
		public string $className,
	)
	{
	}

}
