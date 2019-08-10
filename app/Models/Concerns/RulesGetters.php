<?php

namespace Bchalier\LaravelOpenapiDoc\App\Models\Concerns;

trait RulesGetters
{
    /**
     * @return string|null
     */
    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * @return array|null
     */
    public function getRules(): ?array
    {
        return $this->rules;
    }

    /**
     * @return string|null
     */
    public function getType(): ?string
    {
        return $this->type;
    }

    /**
     * @return array|null
     */
    public function getEnum(): ?array
    {
        return $this->enum;
    }

    /**
     * @return mixed
     */
    public function getExample()
    {
        return $this->example;
    }

    /**
     * @return bool
     */
    public function isRequired(): bool
    {
        return $this->required;
    }

    /**
     * @return mixed
     */
    public function getMessages()
    {
        return $this->messages;
    }
}