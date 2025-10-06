<?php declare(strict_types = 1);

namespace Shredio\TypeSchemaCompiler\Ast\TypeSchema;

final class LiteralNode implements TypeSchemaNode
{

	public function __construct(
		public string $value,
	)
	{
	}

}
