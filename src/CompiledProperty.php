<?php declare(strict_types = 1);

namespace Shredio\TypeSchemaCompiler;

use Shredio\TypeSchemaCompiler\Ast\TypeSchema\TypeSchemaNode;

final readonly class CompiledProperty
{

	public function __construct(
		public string $name,
		public bool $isInConstructor,
		public bool $isRequired,
		public TypeSchemaNode $typeSchemaNode,
	)
	{
	}

}
