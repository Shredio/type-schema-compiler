<?php declare(strict_types = 1);

namespace Shredio\TypeSchemaCompiler\Ast\TypeSchema;

use Nette\PhpGenerator\Dumper;

final readonly class TypeSchemaDumper
{

	private Dumper $dumper;

	public function __construct()
	{
		$this->dumper = new Dumper();
	}

	public function format(string $statement, mixed ...$args): string
	{
		return $this->dumper->format($statement, ...$args);
	}

	public function dump(mixed $value): string
	{
		return $this->dumper->dump($value);
	}

	public function multiLineDump(mixed $value): string
	{
		$dumper = clone $this->dumper;
		$dumper->wrapLength = 20;

		return $dumper->dump($value);
	}

}
