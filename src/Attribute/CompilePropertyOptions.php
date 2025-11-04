<?php declare(strict_types = 1);

namespace Shredio\TypeSchemaCompiler\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class CompilePropertyOptions
{

	/**
	 * @param list<mixed> $nullValues Values that should be treated as null when mapping
	 * @param bool $compileAsObjectType Whether to compile the property as an ObjectType instead of MapperType
	 */
	public function __construct(
		public ?bool $optional = null,
		public array $nullValues = [],
		public bool $compileAsObjectType = false,
	)
	{
	}

}
