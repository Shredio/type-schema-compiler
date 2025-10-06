<?php declare(strict_types = 1);

namespace Tests\Unit;

use Tests\CompilerTestCase;

final class BuiltInTest extends CompilerTestCase
{

	public function testScalarTypes(): void
	{
		$properties = $this->compileAnonymous(new class {
			public int $int;
			public float $float;
			public string $string;
			public bool $bool;
		});

		$this->assertSame([
			'int' => $this->requiredProperty('$ts->int()'),
			'float' => $this->requiredProperty('$ts->float()'),
			'string' => $this->requiredProperty('$ts->string()'),
			'bool' => $this->requiredProperty('$ts->bool()'),
		], $properties);
	}

	public function testNullableScalarTypes(): void
	{
		$properties = $this->compileAnonymous(new class {
			public ?int $int;
			public ?float $float;
			public ?string $string;
			public ?bool $bool;
		});

		$this->assertSame([
			'int' => $this->requiredProperty('$ts->nullable($ts->int())'),
			'float' => $this->requiredProperty('$ts->nullable($ts->float())'),
			'string' => $this->requiredProperty('$ts->nullable($ts->string())'),
			'bool' => $this->requiredProperty('$ts->nullable($ts->bool())'),
		], $properties);
	}

	public function testNullableAndOptionalScalarTypes(): void
	{
		$properties = $this->compileAnonymous(new class {
			public ?int $int = null;
			public ?float $float = null;
			public ?string $string = null;
			public ?bool $bool = null;
		});

		$this->assertSame([
			'int' => $this->optionalProperty('$ts->optional($ts->nullable($ts->int()))'),
			'float' => $this->optionalProperty('$ts->optional($ts->nullable($ts->float()))'),
			'string' => $this->optionalProperty('$ts->optional($ts->nullable($ts->string()))'),
			'bool' => $this->optionalProperty('$ts->optional($ts->nullable($ts->bool()))'),
		], $properties);
	}


	public function testOptionalScalarTypes(): void
	{
		$properties = $this->compileAnonymous(new class {
			public int $int = 0;
			public float $float = 0.0;
			public string $string = '';
			public bool $bool = false;
		});

		$this->assertSame([
			'int' => $this->optionalProperty('$ts->optional($ts->int())'),
			'float' => $this->optionalProperty('$ts->optional($ts->float())'),
			'string' => $this->optionalProperty('$ts->optional($ts->string())'),
			'bool' => $this->optionalProperty('$ts->optional($ts->bool())'),
		], $properties);
	}

	public function testArraysAndLists(): void
	{
		$properties = $this->compileAnonymous(new class {
			public array $unknownArray;
			/** @var array<int> */
			public array $arrayOfInts;
			/** @var array<string, int> */
			public array $arrayWithStringKeys;
			/** @var list<string> */
			public array $listOfStrings;
			/** @var list<float|null> */
			public array $listOfNullableFloats;
			/** @var non-empty-list<bool> */
			public array $nonEmptyListOfBools;
		});

		$this->assertSame([
			'unknownArray' => $this->requiredProperty('$ts->array()'),
			'arrayOfInts' => $this->requiredProperty('$ts->array($ts->arrayKey(), $ts->int())'),
			'arrayWithStringKeys' => $this->requiredProperty('$ts->array($ts->string(), $ts->int())'),
			'listOfStrings' => $this->requiredProperty('$ts->list($ts->string())'),
			'listOfNullableFloats' => $this->requiredProperty('$ts->list($ts->nullable($ts->float()))'),
			'nonEmptyListOfBools' => $this->requiredProperty('$ts->nonEmptyList($ts->bool())'),
		], $properties);
	}

}
