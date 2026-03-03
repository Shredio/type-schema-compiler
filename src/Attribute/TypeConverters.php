<?php declare(strict_types = 1);

namespace Shredio\TypeSchemaCompiler\Attribute;

use Shredio\TypeSchema\Conversion\Converter\Array\ArrayConverter;
use Shredio\TypeSchema\Conversion\Converter\Bool\BoolConverter;
use Shredio\TypeSchema\Conversion\Converter\ConstructableConverter;
use Shredio\TypeSchema\Conversion\Converter\Null\NullConverter;
use Shredio\TypeSchema\Conversion\Converter\Number\NumberConverter;
use Shredio\TypeSchema\Conversion\Converter\String\StringConverter;

final readonly class TypeConverters
{

	public function __construct(
		public (StringConverter&ConstructableConverter)|null $string = null,
		public (NumberConverter&ConstructableConverter)|null $int = null,
		public (NumberConverter&ConstructableConverter)|null $float = null,
		public (BoolConverter&ConstructableConverter)|null $bool = null,
		public (NullConverter&ConstructableConverter)|null $null = null,
		public (ArrayConverter&ConstructableConverter)|null $array = null,
	)
	{
	}

	/**
	 * @return array<non-empty-string, ConstructableConverter>
	 */
	public function toArray(): array
	{
		return array_filter([
			'string' => $this->string,
			'int' => $this->int,
			'float' => $this->float,
			'bool' => $this->bool,
			'null' => $this->null,
			'array' => $this->array,
		], static fn (?ConstructableConverter $converter): bool => $converter !== null);
	}

}
