<?php declare(strict_types = 1);

namespace Shredio\TypeSchemaCompiler\Ast\TypeSchema;

final readonly class ArrayNode implements TypeSchemaNode
{

	/**
	 * @param array<TypeSchemaNode> $items
	 */
	public function __construct(
		public array $items = [],
		public bool $multiLine = false,
	)
	{
	}

}
