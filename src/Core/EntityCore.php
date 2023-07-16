<?php

namespace WebFramework\Core;

abstract class EntityCore implements EntityInterface
{
    /** @var array<string> */
    public static array $baseFields = [];

    /** @var array<string> */
    public static array $privateFields = [];

    /** @var array<string, mixed> */
    public array $originalValues = [];

    protected bool $isNewObject = true;

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $reflection = new \ReflectionClass($this);

        $data = [];

        if (!$this->isNewObject)
        {
            $property = $reflection->getProperty('id');
            $property->setAccessible(true);

            $data['id'] = $property->getValue($this);
        }

        foreach (static::$baseFields as $name)
        {
            // Skip private fields
            //
            if (in_array($name, static::$privateFields))
            {
                continue;
            }

            $function = $this->snakeToGetter($name);

            // Retrieve via getter if present
            //
            if (method_exists($this, $function))
            {
                $data[$name] = $this->{$function}();
            }
            else
            {
                $property = $reflection->getProperty($this->snakeToCamel($name));
                $property->setAccessible(true);

                $data[$name] = $property->getValue($this);
            }
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function toRawArray(): array
    {
        $reflection = new \ReflectionClass($this);

        $data = [];

        if (!$this->isNewObject)
        {
            $property = $reflection->getProperty('id');
            $property->setAccessible(true);

            $data['id'] = $property->getValue($this);
        }

        foreach (static::$baseFields as $name)
        {
            // Skip private fields
            //
            if (in_array($name, static::$privateFields))
            {
                continue;
            }

            $property = $reflection->getProperty($this->snakeToCamel($name));
            $property->setAccessible(true);

            $data[$name] = $property->getValue($this);
        }

        return $data;
    }

    // Convert snake_case to camelCase
    //
    private function snakeToCamel(string $input): string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $input))));
    }

    // Convert snake_case to getSnakeCase
    //
    private function snakeToGetter(string $input): string
    {
        return 'get'.str_replace('_', '', ucwords($input, '_'));
    }

    /**
     * @return array<string, mixed>
     */
    public function getOriginalValues(): array
    {
        return $this->originalValues;
    }

    /**
     * @param array<string, mixed> $values
     */
    public function setOriginalValues(array $values): void
    {
        $this->originalValues = $values;
    }

    public function isNewObject(): bool
    {
        return $this->isNewObject;
    }

    public function setObjectId(int $id): void
    {
        if ($this->isNewObject === false)
        {
            throw new \RuntimeException('Id already set');
        }

        $reflection = new \ReflectionClass($this);

        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($this, $id);

        $this->isNewObject = false;
    }
}
