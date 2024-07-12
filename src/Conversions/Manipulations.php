<?php

namespace Spatie\MediaLibrary\Conversions;

use Spatie\Image\Drivers\ImageDriver;
use Spatie\Image\Enums\AlignPosition;
use Spatie\Image\Enums\BorderType;
use Spatie\Image\Enums\ColorFormat;
use Spatie\Image\Enums\Constraint;
use Spatie\Image\Enums\CropPosition;
use Spatie\Image\Enums\Fit;
use Spatie\Image\Enums\FlipDirection;
use Spatie\Image\Enums\Orientation;
use Spatie\Image\Enums\Unit;

/** @mixin \Spatie\Image\Drivers\ImageDriver */
class Manipulations
{
    protected array $manipulations = [];

    public function __construct(array $manipulations = [])
    {
        $this->manipulations = $manipulations;
    }

    public function __call(string $method, array $parameters): self
    {
        $this->addManipulation($method, $parameters);

        return $this;
    }

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
            if (in_array($manipulationName, ['fit', 'watermark'])) {
                $this->convertParameterToEnumIfExists($parameters, 'fit', Fit::class);
            }

            if ($manipulationName === 'border') {
                $this->convertParameterToEnumIfExists($parameters, 'type', BorderType::class);
            }

            if ($manipulationName === 'pickColor') {
                $this->convertParameterToEnumIfExists($parameters, 'colorFormat', ColorFormat::class);
            }

            if ($manipulationName === 'flip') {
                $this->convertParameterToEnumIfExists($parameters, 'flip', FlipDirection::class);
            }

            if (in_array($manipulationName, ['resize', 'width', 'height'])) {
                $this->convertParameterToEnumIfExists($parameters, 'constraints', Constraint::class);
            }

            if ($manipulationName === 'orientation') {
                $this->convertParameterToEnumIfExists($parameters, 'orientation', Orientation::class);
            }

            if ($manipulationName === 'watermark') {
                $this->convertParameterToEnumIfExists($parameters, 'paddingUnit', Unit::class);
                $this->convertParameterToEnumIfExists($parameters, 'widthUnit', Unit::class);
                $this->convertParameterToEnumIfExists($parameters, 'heightUnit', Unit::class);
            }

            if ($manipulationName === 'crop') {
                $this->convertParameterToEnumIfExists($parameters, 'position', CropPosition::class);
            } elseif (in_array($manipulationName, ['watermark', 'resizeCanvas', 'insert'])) {
                $this->convertParameterToEnumIfExists($parameters, 'position', AlignPosition::class);
            }

            $image->$manipulationName(...$parameters);
        }
    }

    public function mergeManipulations(self $manipulations): self
    {
        foreach ($manipulations->toArray() as $name => $parameters) {
            $this->manipulations[$name] = array_merge($this->manipulations[$name] ?? [], $parameters ?: []);
        }

        return $this;
    }

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
     * @param array $parameters
     * @param string $parameterName
     * @param class-string $enum
     * @return array
     */
    private function convertParameterToEnumIfExists(array &$parameters, string $parameterName, string $enum): array
    {
        if (isset($parameters[$parameterName]) && ! ($parameters[$parameterName] instanceof $enum)) {
            $parameters[$parameterName] = $enum::from($parameters[$parameterName]);
        }

        return $parameters;
    }
}
