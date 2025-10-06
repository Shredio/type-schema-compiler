<?php declare(strict_types = 1);

namespace Shredio\TypeSchemaCompiler\Ast\TypeSchema;

final readonly class DumpNode implements TypeSchemaNode
{

	public function __construct(
		public mixed $value,
	)
	{
	}

}
