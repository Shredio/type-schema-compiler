<?php declare(strict_types = 1);

namespace Tests\Unit;

use Symfony\Component\Validator\Constraints as Assert;
use Tests\TestCase;

final class ValidatorTest extends TestCase
{

	public function testUserCompile(): void
	{
		$this->assertCompiledSameAsFile(
			__DIR__ . '/expected/validator/UserCompile.php',
			User::class,
		);

		$this->assertCreatedMapperCount(1);
	}

}

final class User
{

	public function __construct(
		#[Assert\NotBlank]
		#[Assert\Length(min: 2, max: 100)]
		public string $name = '',
		#[Assert\Positive]
		public int $age = 0,
	)
	{
	}

}

