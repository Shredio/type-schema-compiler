<?php declare(strict_types = 1);

namespace Shredio\TypeSchemaCompiler\Ast\TypeSchema;

final readonly class CallbackNode implements TypeSchemaNode
{

	public ?string $class;

	public string $method;

	public function __construct(mixed $value)
	{
		if (is_string($value)) {
			$this->class = null;
			$this->method = $value;
			return;
		}
		if (!is_array($value) || !isset($value[0]) || !isset($value[1]) || !is_string($value[0]) || !is_string($value[1])) {
			throw new \InvalidArgumentException(sprintf('Value %s is not a valid callback to dumping.', get_debug_type($value)));
		}

		if (!method_exists($value[0], $value[1])) {
			throw new \InvalidArgumentException(sprintf('Method %s::%s does not exist for dumping callback.', get_debug_type($value[0]), $value[1]));
		}

		$this->class = $value[0];
		$this->method = $value[1];
	}

}
