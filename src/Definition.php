<?php

namespace LaravelApi;

use Calcinai\Strut\Definitions\Schema;
use Calcinai\Strut\Definitions\Schema\Properties\Properties;

class Definition extends Schema
{
    protected string $name;

    public function __construct($data = [])
    {
        parent::__construct($data);
        $this->setProperties(Properties::create());
    }

    /**
     * @param string $name
     * @return Definition
     */
    public function setName(string $name): Definition
    {
        $this->name = $name;
        return $this;
    }

    /**
     * @param string $name
     * @param string $type
     * @param string|null $description
     * @param string|null $default
     * @return Definition
     * @throws \Exception
     */
    public function addProperty(
        string $name,
        string $description = null,
        string $default = null,
        string $type = 'string'
    ): Definition
    {
        $property = Schema::create(compact('type', 'description', 'default'));

        $this->getProperties()->set($name, $property);

        return $this;
    }


    /**
     * @return Schema
     */
    public function toRef(): Schema
    {
        return Schema::create()->setRef("#/definitions/{$this->name}");
    }
}
