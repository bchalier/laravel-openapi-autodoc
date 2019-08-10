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
        $this->rules = (array)$rules;

        return $this;
    }

    /**
     * @param $rules
     * @return RulesSetters
     */
    public function addRules($rules): self
    {
        $this->parseRules($rules);
        $this->rules = array_merge($this->rules, (array)$rules);

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