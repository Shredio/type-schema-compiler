<?php declare(strict_types = 1);

namespace Shredio\TypeSchemaCompiler\Ast\TypeSchema;

final readonly class MethodChainNode implements TypeSchemaNode
{

	/**
	 * @param array<TypeSchemaNode> $nodes
	 */
	public function __construct(
		public TypeSchemaNode $parent,
		public string $method,
		public array $nodes = [],
	)
	{
	}

}
