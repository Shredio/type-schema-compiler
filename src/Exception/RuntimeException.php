<?php declare(strict_types = 1);

namespace Shredio\TypeSchemaCompiler\Exception;

use Throwable;

abstract class RuntimeException extends \RuntimeException
{

	public function __construct(string $message = '', ?Throwable $previous = null)
	{
		parent::__construct($message, previous: $previous);
	}

}
