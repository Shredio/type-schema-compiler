<?php declare(strict_types = 1);

namespace Tests\Unit;

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
