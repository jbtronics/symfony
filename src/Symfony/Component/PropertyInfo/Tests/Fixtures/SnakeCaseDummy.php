<?php

declare(strict_types=1);


namespace Symfony\Component\PropertyInfo\Tests\Fixtures;

class SnakeCaseDummy
{
    private string $snake_property;

    private string $snake_readOnly;


    public function getSnakeProperty()
    {
        return $this->snake_property;
    }

    public function getSnakeReadOnly()
    {
        return $this->snake_readOnly;
    }

    public function setSnakeProperty($snake_property)
    {
        $this->snake_property = $snake_property;
    }


}
