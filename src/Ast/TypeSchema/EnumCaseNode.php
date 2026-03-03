<?php declare(strict_types = 1);

namespace Shredio\TypeSchemaCompiler\Ast\TypeSchema;

use UnitEnum;

final readonly class EnumCaseNode implements TypeSchemaNode
{

	/**
	 * @param class-string<UnitEnum> $className
	 */
	public function __construct(
		public string $className,
		public string $caseName,
	)
	{
	}

}
