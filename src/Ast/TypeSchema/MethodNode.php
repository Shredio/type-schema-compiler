<?php declare(strict_types = 1);

namespace Shredio\TypeSchemaCompiler\Ast\TypeSchema;

final readonly class MethodNode implements TypeSchemaNode
{

	/**
	 * @param array<TypeSchemaNode> $nodes
	 */
	public function __construct(
		public string $method,
		public array $nodes = [],
	)
	{
	}

}
