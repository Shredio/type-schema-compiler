<?php declare(strict_types = 1);

namespace Tests\Unit;

use Shredio\TypeSchema\Conversion\Converter\Bool\LenientBoolConverter;
use Shredio\TypeSchema\Conversion\Converter\Number\LenientNumberConverter;
use Shredio\TypeSchema\Conversion\Converter\String\StrictStringConverter;
use Shredio\TypeSchemaCompiler\Attribute\CompileObjectMapper;
use Shredio\TypeSchemaCompiler\Attribute\CompilePropertyOptions;
use Shredio\TypeSchemaCompiler\Attribute\TypeConverters;
use Tests\TestCase;

final class ObjectTest extends TestCase
{

	public function testPersonCompile(): void
	{
		$this->assertCompiledSameAsFile(
			__DIR__ . '/expected/object/PersonCompile.php',
			Person::class,
		);

		$this->assertCreatedMapperCount(2); // Person and Address mappers
	}

	public function testPersonCompileWithObjectMapper(): void
	{
		$this->assertCompiledFromObjectMapperProviderSameAsFile(
			__DIR__ . '/expected/object/PersonCompileWithObjectMapper.php',
			Person2::class,
		);

		$this->assertCreatedMapperCount(2); // Person2 and Address mappers
	}

	public function testPersonCompileWithObjectMapperWithoutAddress(): void
	{
		$this->assertCompiledFromObjectMapperProviderSameAsFile(
			__DIR__ . '/expected/object/PersonCompileWithObjectMapperWithoutAddress.php',
			Person3::class,
			[Address::class],
		);

		$this->assertCreatedMapperCount(1); // Person3
	}

	public function testAddressCompile(): void
	{
		$this->assertCompiledSameAsFile(
			__DIR__ . '/expected/object/AddressCompile.php',
			Address::class,
		);

		$this->assertCreatedMapperCount(1); // Address has no nested objects
	}

	public function testAddressDiscardExtraItemsCompile(): void
	{
		$this->assertCompiledSameAsFile(
			__DIR__ . '/expected/object/AddressDiscardExtraItemsMapper.php',
			AddressDiscardExtraItems::class,
		);

		$this->assertCreatedMapperCount(1); // Address has no nested objects
	}

	public function testCompileAsObjectType(): void
	{
		$this->assertCompiledSameAsFile(
			__DIR__ . '/expected/object/CompileAsObjectType.php',
			CompileAsObjectType::class,
		);

		$this->assertCreatedMapperCount(1);
	}

	public function testReindexObjectCompile(): void
	{
		$this->assertCompiledSameAsFile(
			__DIR__ . '/expected/object/ReindexObjectMapper.php',
			ReindexObject::class,
		);

		$this->assertCreatedMapperCount(1);
	}

	public function testTypeConverters(): void
	{
		$this->assertCompiledSameAsFile(
			__DIR__ . '/expected/object/CustomTypeConverters.php',
			CustomTypeConverters::class,
		);

		$this->assertCreatedMapperCount(1);
	}

}

class CustomTypeConverters
{

	#[CompilePropertyOptions(typeConverters: new TypeConverters(string: new StrictStringConverter()))]
	public ?string $string;

	#[CompilePropertyOptions(typeConverters: new TypeConverters(float: new LenientNumberConverter()))]
	public float $float;

	#[CompilePropertyOptions(typeConverters: new TypeConverters(int: new LenientNumberConverter(roundingMode: \RoundingMode::HalfAwayFromZero)))]
	public int $int;

	#[CompilePropertyOptions(typeConverters: new TypeConverters(bool: new LenientBoolConverter(['yes'], ['no'])))]
	public bool $bool;

}

class Person
{
	public int $id;
	public string $name;
	public Address $address;
}

class Person2
{
	public int $id;
	public string $name;
	public Address $address;
}

class Person3
{
	public int $id;
	public string $name;
	public Address $address;
}

class Address
{
	public string $street;
	public string $city;
}

#[CompileObjectMapper(discardExtraItems: true)]
class AddressDiscardExtraItems
{
	public function __construct(
		public string $city,
		public string $street,
	)
	{
	}

}

class CompileAsObjectType {

	public function __construct(
		#[CompilePropertyOptions(compileAsObjectType: true)]
		public Address $address,
		#[CompilePropertyOptions(compileAsObjectType: true)]
		public Address|string $unionBuiltIn,
		#[CompilePropertyOptions(compileAsObjectType: true)]
		public Address|Person $union,
		#[CompilePropertyOptions(compileAsObjectType: true)]
		public ?Address $nullableAddress = null,
	)
	{
	}

}

class ReindexObject
{

	#[CompilePropertyOptions(name: 'good_will')]
	public ?float $goodWill;

	#[CompilePropertyOptions(name: 'other_assets')]
	public ?float $otherAssets = null;

	public function __construct(
		#[CompilePropertyOptions(name: 'currency_code')]
		public string $currencyCode,
		#[CompilePropertyOptions(name: 'earning_assets')]
		public ?float $earningAssets = null,
	)
	{
	}

}
