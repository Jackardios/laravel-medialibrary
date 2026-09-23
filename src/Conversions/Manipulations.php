<?php

namespace Spatie\MediaLibrary\Conversions;

use BackedEnum;
use ReflectionEnum;
use ReflectionMethod;
use ReflectionNamedType;
use Spatie\Image\Drivers\ImageDriver;
use Spatie\Image\Enums\Constraint;

/** @mixin ImageDriver */
class Manipulations
{
    protected array $manipulations = [];

    /**
     * Enum-typed parameters per image driver method, derived from the driver's signatures.
     *
     * @var array<string, array<int, array{0: string, 1: class-string<BackedEnum>, 2: bool}>>
     */
    private static array $enumParameters = [];

    public function __construct(array $manipulations = [])
    {
        $this->manipulations = $manipulations;
    }

    public function __call(string $method, array $parameters): self
    {
        $this->addManipulation($method, $parameters);

        return $this;
    }

    /**
     * @return $this
     */
    public function addManipulation(string $name, array $parameters = []): self
    {
        $this->manipulations[$name] = $parameters;

        return $this;
    }

    public function getManipulationArgument(string $manipulationName): null|string|array
    {
        return $this->manipulations[$manipulationName] ?? null;
    }

    public function getFirstManipulationArgument(string $manipulationName): null|string|int
    {
        $manipulationArgument = $this->getManipulationArgument($manipulationName);

        if (! is_array($manipulationArgument)) {
            return null;
        }

        return $manipulationArgument[0];
    }

    public function isEmpty(): bool
    {
        return count($this->manipulations) === 0;
    }

    public function apply(ImageDriver $image): void
    {
        foreach ($this->manipulations as $manipulationName => $parameters) {
            $parameters = $this->transformParameters($manipulationName, $parameters);
            $image->$manipulationName(...$parameters);
        }
    }

    /**
     * @return $this
     */
    public function mergeManipulations(self $manipulations): self
    {
        foreach ($manipulations->toArray() as $name => $parameters) {
            $this->manipulations[$name] = array_merge($this->manipulations[$name] ?? [], $parameters ?: []);
        }

        return $this;
    }

    /**
     * @return $this
     */
    public function removeManipulation(string $name): self
    {
        unset($this->manipulations[$name]);

        return $this;
    }

    public function toArray(): array
    {
        return $this->manipulations;
    }

    /**
     * Cast the plain values that manipulations stored in the database carry (after the JSON
     * round trip an enum is just its backing value) back to the enums the image driver expects.
     * Works for arguments passed by name and by position.
     */
    public function transformParameters(int|string $manipulationName, mixed $parameters): mixed
    {
        if (! is_string($manipulationName) || ! is_array($parameters)) {
            return $parameters;
        }

        foreach (self::enumParameters($manipulationName) as $position => [$name, $enumClass, $isList]) {
            $key = match (true) {
                array_key_exists($name, $parameters) => $name,
                array_key_exists($position, $parameters) => $position,
                default => null,
            };

            if ($key === null) {
                continue;
            }

            $parameters[$key] = $isList && is_array($parameters[$key])
                ? array_map(fn (mixed $value) => self::castToEnum($value, $enumClass), $parameters[$key])
                : self::castToEnum($parameters[$key], $enumClass);
        }

        return $parameters;
    }

    /**
     * The enum-typed parameters of an image driver method, by position.
     *
     * @return array<int, array{0: string, 1: class-string<BackedEnum>, 2: bool}>
     */
    protected static function enumParameters(string $manipulationName): array
    {
        if (array_key_exists($manipulationName, self::$enumParameters)) {
            return self::$enumParameters[$manipulationName];
        }

        $enumParameters = [];

        if (method_exists(ImageDriver::class, $manipulationName)) {
            foreach ((new ReflectionMethod(ImageDriver::class, $manipulationName))->getParameters() as $parameter) {
                $type = $parameter->getType();

                if ($type instanceof ReflectionNamedType && is_subclass_of($type->getName(), BackedEnum::class)) {
                    $enumParameters[$parameter->getPosition()] = [$parameter->getName(), $type->getName(), false];
                }

                // `resize()`, `width()` and `height()` take a plain array of Constraint enums.
                if ($parameter->getName() === 'constraints') {
                    $enumParameters[$parameter->getPosition()] = [$parameter->getName(), Constraint::class, true];
                }
            }
        }

        return self::$enumParameters[$manipulationName] = $enumParameters;
    }

    /** @param class-string<BackedEnum> $enumClass */
    protected static function castToEnum(mixed $value, string $enumClass): mixed
    {
        if (! is_string($value) && ! is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value) && (string) (new ReflectionEnum($enumClass))->getBackingType() === 'int') {
            $value = (int) $value;
        }

        return $enumClass::from($value);
    }
}
