<?php

namespace Bchalier\LaravelOpenapiDoc\App\Models;

use Bchalier\LaravelOpenapiDoc\App\Contracts\Parsable;
use Illuminate\Contracts\Validation\Rule as RuleContract;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Concerns\FormatsMessages;
use Illuminate\Validation\ValidationRuleParser;

class ValidationExtractor
{
    use Concerns\ParseRules,
        Concerns\RulesGetters,
        Concerns\RulesSetters,
        Concerns\GuessBlanks,
        FormatsMessages;

    private const GUESSABLE = ['example'];

    public const TYPE_STRING = 'string';
    public const TYPE_BOOLEAN = 'boolean';
    public const TYPE_INTEGER = 'integer';
    public const TYPE_NUMBER = 'number';
    public const TYPE_OBJECT = 'object';
    public const TYPE_ARRAY = 'array';
    public const TYPE_FILE = 'file'; // NOT YET SUPPORTED

    /**
     * The array of custom error messages.
     *
     * @var array
     */
    public $customMessages = [];

    /**
     * The size related validation rules.
     *
     * @var array
     */
    protected $sizeRules = ['Size', 'Between', 'Min', 'Max', 'Gt', 'Lt', 'Gte', 'Lte'];

    /** @var string */
    protected $name;

    /** @var array */
    protected $rules = [];

    /** @var \Faker\Generator */
    protected $faker;

    /** @var Translator */
    protected $translator;

    /** @var array */
    protected $messages = [];

    /**
     * ValidationExtractor constructor.
     *
     * @param string|null $name
     */
    public function __construct(?string $name)
    {
        $this->faker = \Faker\Factory::create();
        $this->setName($name);

        $this->translator = app('translator');
    }

    /**
     * Find all the attributes not already set by the rules based on self::GUESSABLE
     */
    public function guessTheBlanks(): void
    {
        foreach (self::GUESSABLE as $attribute) {
            if (empty($this->$attribute)) {
                $attribute = ucfirst($attribute);
                $method = "guess{$attribute}";

                $this->$method();
            }
        }
    }

    /**
     * @return array
     */
    public function toArray(): array
    {
        return [
            'rules' => $this->rules,
            'type' => $this->type,
            'required' => $this->required,
            'min' => $this->min,
            'max' => $this->max,
            'enum' => $this->enum,
            'example' => $this->example,
            'validCharacters' => $this->validCharacters,
            'requiredCharacters' => $this->requiredCharacters,
            'endsWith' => $this->endsWith,
        ];
    }

    /**
     * @param array $rules
     */
    protected function parseRules(array $rules): void
    {
        foreach ($rules as $rule) {
            $this->parseRule($rule);
        }
    }

    /**
     * @param $rule
     */
    protected function parseRule($rule): void
    {
        [$rule, $parameters] = ValidationRuleParser::parse($rule);

        if ($rule == '') {
            return;
        }

        if ($rule instanceof RuleContract) {
            $this->parseUsingCustomRule($rule, $parameters);
            return;
        }

        $this->messages[] = $this->makeReplacements(
            $this->messageFromRule($rule), $this->getName(), $rule, $parameters
        );

        $method = "parse{$rule}";
        $this->$method($parameters);
    }

    /**
     * @param RuleContract $rule
     * @param array        $parameters
     */
    protected function parseUsingCustomRule(RuleContract $rule, array $parameters = []): void
    {
        if (!$rule instanceof Parsable) {
            Log::notice('The rule ' . get_class($rule) . ' does not implement ' . Parsable::class . ' and will not be documented.');
            return;
        }

        $rule->parse($this);
    }

    /**
     * @param $rule
     * @return string
     */
    protected function messageFromRule($rule): ?string
    {
        $lowerRule = Str::snake($rule);

        $customMessage = $this->getCustomMessageFromTranslator(
            $customKey = "validation.custom.{$this->getName()}.{$lowerRule}"
        );

        // First we check for a custom defined validation message for the attribute
        // and rule. This allows the developer to specify specific messages for
        // only some attributes and rules that need to get specially formed.
        if ($customMessage !== $customKey) {
            return $customMessage;
        }

        // If the rule being validated is a "size" rule, we will need to gather the
        // specific error message for the type of attribute being validated such
        // as a number, file or string which all have different message types.
        elseif (in_array($rule, $this->sizeRules)) {
            return $this->getSizeMessage($this->getName(), $rule);
        }

        // Finally, if no developer specified messages have been set, and no other
        // special messages apply for this rule, we will just pull the default
        // messages out of the translator service for this validation rule.
        $key = "validation.{$lowerRule}";

        if ($key != ($value = $this->translator->trans($key))) {
            return $value;
        }

        return null;
    }

    /**
     * Get the data type of the given attribute (here for compatibility with the FormatsMessages trait).
     *
     * @param string $attribute
     * @return string
     */
    protected function getAttributeType($attribute): string
    {
        return $this->getType() ?? self::TYPE_STRING;
    }

    /**
     * Get the primary attribute name (here for compatibility with the FormatsMessages trait).
     *
     * For example, if "name.0" is given, "name.*" will be returned.
     *
     * @param string $attribute
     * @return string
     */
    protected function getPrimaryAttribute($attribute): string
    {
        return $attribute;
    }

    /**
     * Get the value of a given attribute (here for compatibility with the FormatsMessages trait).
     *
     * @param string $attribute
     * @return mixed
     */
    protected function getValue($attribute): void
    {
        return;
    }
}