<?php declare(strict_types = 1);

namespace Shredio\TypeSchemaCompiler\Attribute;

use Attribute;
use Shredio\TypeSchema\Context\TypeContext;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class CompilePropertyOptions
{

	/** @var (callable(mixed $valueToParse, TypeContext $context): mixed)|null */
	public mixed $before;

	/**
	 * @param non-empty-string|null $name The name of the property in the source data (e.g. JSON key). If null, the property name will be used.
	 * @param list<mixed> $nullValues Values that should be treated as null when mapping
	 * @param bool $compileAsObjectType Whether to compile the property as an ObjectType instead of MapperType
	 * @param (callable(mixed $valueToParse, TypeContext $context): mixed)|null $before A callback to process the value before mapping
	 */
	public function __construct(
		public ?string $name = null,
		public ?bool $optional = null,
		public array $nullValues = [],
		public bool $compileAsObjectType = false,
		?callable $before = null,
	)
	{
		$this->before = $before;
	}

}
