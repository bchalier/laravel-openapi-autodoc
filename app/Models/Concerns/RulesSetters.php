<?php

namespace Bchalier\LaravelOpenapiDoc\App\Models\Concerns;

trait RulesSetters
{
    /**
     * @param string|null $name
     * @return RulesSetters
     */
    public function setName(?string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /**
     * @param array|null $rules
     * @return RulesSetters
     */
    public function setRules(?array $rules): self
    {
        $this->parseRules($rules);

        return $this;
    }

    public function addEnum(string $value): self
    {
        $this->enum[] = $value;

        return $this;
    }

    public function setEnum(array $enum): self
    {
        $this->enum = $enum;

        return $this;
    }

    /**
     * @param $rules
     * @return RulesSetters
     */
    public function addRules($rules): self
    {
        $this->parseRules($rules);

        return $this;
    }

    /**
     * @param $type
     * @return RulesSetters
     */
    public function setType($type): self
    {
        $this->type = $type;

        return $this;
    }

    /**
     * @param string $character
     * @return $this
     */
    public function addRequiredCharacter(string $character): self
    {
        $this->requiredCharacters[] = $character;

        return $this;
    }
}