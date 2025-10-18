<?php declare(strict_types = 1);

namespace Shredio\TypeSchemaCompiler\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class CompileObjectMapper
{

	/**
	 * @param non-empty-string|null $identifier Property name to use as an identifier in arrayShape
	 */
	public function __construct(
		public ?string $identifier = null,
	)
	{
	}

}
