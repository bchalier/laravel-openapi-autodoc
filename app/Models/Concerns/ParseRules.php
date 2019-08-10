<?php

namespace Bchalier\LaravelOpenapiDoc\App\Models\Concerns;

use Carbon\Carbon;
use InvalidArgumentException;

trait ParseRules
{
    /** @var string */
    protected $type;

    /** @var bool */
    protected $required = false;

    /** @var int */
    protected $max;

    /** @var int */
    protected $min;

    /** @var array */
    protected $enum;

    /** @var array */
    protected $validCharacters = [];

    /** @var array */
    protected $requiredCharacters = [];

    protected $startsWith;
    protected $endsWith;

    protected $fileExtension;
    protected $example;

    /**
     * Parse an "accepted".
     *
     * This validation rule implies the attribute is "required".
     *
     * @return void
     */
    public function parseAccepted(): void
    {
        $this->required = true;
        $this->enum = ['yes', 'on', '1', 1, true, 'true'];
    }

    /**
     * Parse an active URL.
     *
     * @return void
     */
    public function parseActiveUrl(): void
    {
        $this->type = self::TYPE_STRING;
        $this->example = 'https://google.com';
    }

    /**
     * Validate the date is after a given date.
     *
     * @param array $parameters
     * @return void
     * @throws \Exception
     */
    public function parseAfter($parameters): void
    {
        $this->requireParameterCount(1, $parameters, 'after');

        $date = new Carbon($parameters[0]);

        $this->type = self::TYPE_STRING;
        $this->example = $date->addDay()->__toString();
    }

    /**
     * Require a certain number of parameters to be present.
     *
     * @param int    $count
     * @param array  $parameters
     * @param string $rule
     * @return void
     *
     * @throws \InvalidArgumentException
     */
    public function requireParameterCount($count, $parameters, $rule)
    {
        if (count($parameters) < $count) {
            throw new InvalidArgumentException("Validation rule $rule requires at least $count parameters.");
        }
    }

    /**
     * Validate the date is equal or after a given date.
     *
     * @param array $parameters
     * @return void
     * @throws \Exception
     */
    public function parseAfterOrEqual($parameters): void
    {
        $this->requireParameterCount(1, $parameters, 'after_or_equal');

        $date = new Carbon($parameters[0]);

        $this->type = self::TYPE_STRING;
        $this->example = $date->__toString();
    }

    /**
     * Parse an attribute contains only alphabetic characters.
     *
     * @return void
     */
    public function parseAlpha(): void
    {
        $this->type = self::TYPE_STRING;
        $this->validCharacters = array_merge(range('a', 'z'), range('A', 'Z'));
    }

    /**
     * Parse an attribute contains only alpha-numeric characters, dashes, and underscores.
     *
     * @return void
     */
    public function parseAlphaDash(): void
    {
        $this->type = self::TYPE_STRING;
        $this->validCharacters = array_merge(range('a', 'z'), range('A', 'Z'), range('0', '9'));
        $this->validCharacters[] .= '_';
        $this->validCharacters[] .= '-';
    }

    /**
     * Parse an attribute contains only alpha-numeric characters.
     *
     * @return void
     */
    public function parseAlphaNum(): void
    {
        $this->type = self::TYPE_STRING;
        $this->validCharacters = array_merge(range('a', 'z'), range('A', 'Z'), range('0', '9'));
    }

    /**
     * Parse an attribute is an array.
     *
     * @return void
     */
    public function parseArray(): void
    {
        $this->type = self::TYPE_ARRAY;
    }

    /**
     * "Break" on first validation fail.
     *
     * @return void
     */
    public function parseBail(): void
    {
        return;
    }

    /**
     * Validate the date is before a given date.
     *
     * @param array $parameters
     * @return void
     * @throws \Exception
     */
    public function parseBefore($parameters): void
    {
        $this->requireParameterCount(1, $parameters, 'before');

        $date = new Carbon($parameters[0]);

        $this->type = self::TYPE_STRING;
        $this->example = $date->subDay()->__toString();
    }

    /**
     * Validate the date is before or equal a given date.
     *
     * @param array $parameters
     * @return void
     * @throws \Exception
     */
    public function parseBeforeOrEqual($parameters): void
    {
        $this->requireParameterCount(1, $parameters, 'before_or_equal');

        $date = new Carbon($parameters[0]);

        $this->type = self::TYPE_STRING;
        $this->example = $date->__toString();
    }

    /**
     * Validate the size of an attribute is between a set of values.
     *
     * @param array $parameters
     * @return void
     */
    public function parseBetween($parameters): void
    {
        $this->requireParameterCount(2, $parameters, 'between');

        $this->min = $parameters[0];
        $this->max = $parameters[1];
    }

    /**
     * Parse an attribute is a boolean.
     *
     * @return void
     */
    public function parseBoolean(): void
    {
        $this->enum = [true, false, 0, 1, '0', '1'];
        $this->type = self::TYPE_BOOLEAN;
    }

    /**
     * Parse an attribute has a matching confirmation.
     *
     * @return void
     */
    public function parseConfirmed(): void
    {
        $this->parseSame([$this->name . '_confirmation']);
    }

    /**
     * Parse two attributes match.
     *
     * @param array $parameters
     * @return void
     */
    public function parseSame($parameters) // TODO
    {
        $this->requireParameterCount(1, $parameters, 'same');

        $other = Arr::get($this->data, $parameters[0]);

        return $value === $other;
    }

    /**
     * Parse an attribute is a valid date.
     *
     * @return void
     */
    public function parseDate(): void
    {
        $this->type = self::TYPE_STRING;
    }

    /**
     * Parse an attribute is equal to another date.
     *
     * @param array $parameters
     * @return void
     * @throws \Exception
     */
    public function parseDateEquals($parameters): void
    {
        $this->requireParameterCount(1, $parameters, 'date_equals');

        $date = new Carbon($parameters[0]);

        $this->type = self::TYPE_STRING;
        $this->example = $date->__toString();
    }

    /**
     * Parse an attribute matches a date format.
     *
     * @param array $parameters
     * @return void
     */
    public function parseDateFormat($parameters): void
    {
        $this->requireParameterCount(1, $parameters, 'date_format');

        $now = Carbon::now();
        $format = $parameters[0];

        $this->type = self::TYPE_STRING;
        $this->example = \DateTime::createFromFormat('!' . $format, $now);
    }

    /**
     * Parse an attribute is different from another attribute.
     *
     * @param array $parameters
     * @return void
     */
    public function parseDifferent($parameters): void
    {
        $this->requireParameterCount(1, $parameters, 'different');

        return;
    }

    /**
     * Parse an attribute has a given number of digits.
     *
     * @param array $parameters
     * @return void
     */
    public function parseDigits($parameters): void
    {
        $this->requireParameterCount(1, $parameters, 'digits');

        $this->type = self::TYPE_NUMBER;
        $this->min = $this->max = $parameters[0];
    }

    /**
     * Parse an attribute is between a given number of digits.
     *
     * @param array $parameters
     * @return void
     */
    public function parseDigitsBetween($parameters): void
    {
        $this->requireParameterCount(2, $parameters, 'digits_between');

        $this->type = self::TYPE_NUMBER;
        $this->min = $parameters[0];
        $this->max = $parameters[1];
    }

    /**
     * Validate the dimensions of an image matches the given values.
     *
     * @param array $parameters
     * @return void
     */
    public function parseDimensions($parameters) // TODO
    {
        if ($this->isValidFileInstance($value) && $value->getClientMimeType() === 'image/svg+xml') {
            return true;
        }

        if (!$this->isValidFileInstance($value) || !$sizeDetails = @getimagesize($value->getRealPath())) {
            return false;
        }

        $this->requireParameterCount(1, $parameters, 'dimensions');

        [$width, $height] = $sizeDetails;

        $parameters = $this->parseNamedParameters($parameters);

        if ($this->failsBasicDimensionChecks($parameters, $width, $height) ||
            $this->failsRatioCheck($parameters, $width, $height)) {
            return false;
        }

        return true;
    }

    /**
     * Validate an attribute is unique among other values.
     *
     * @param array $parameters
     * @return void
     */
    public function parseDistinct($parameters) // TODO
    {
        $data = Arr::except($this->getDistinctValues($attribute), $attribute);

        if (in_array('ignore_case', $parameters)) {
            return empty(preg_grep('/^' . preg_quote($value, '/') . '$/iu', $data));
        }

        return !in_array($value, array_values($data));
    }

    /**
     * Parse an attribute is a valid e-mail address.
     *
     * @return void
     */
    public function parseEmail(): void
    {
        $this->type = self::TYPE_STRING;
        $this->example = $this->faker->safeEmail;
    }

    /**
     * Validate the attribute ends with a given substring.
     *
     * @param array $parameters
     * @return void
     */
    public function parseEndsWith($parameters): void
    {
        $this->endsWith = $parameters[array_rand($parameters, 1)];
    }

    /**
     * Validate the existence of an attribute value in a database table.
     *
     * @param array $parameters
     * @return void
     */
    public function parseExists($parameters): void
    {
        $this->requireParameterCount(1, $parameters, 'exists');

        return;
    }

    /**
     * Validate the given value is a valid file.
     *
     * @return void
     */
    public function parseFile(): void
    {
        $this->type = self::TYPE_FILE;
    }

    /**
     * Validate the given attribute is filled if it is present.
     *
     * @return void
     */
    public function parseFilled(): void
    {
        if (!$this->min > 0) {
            $this->min = 1;
        }
    }

    /**
     * Parse an attribute is greater than another attribute.
     *
     * @param array $parameters
     * @return void
     */
    public function parseGt($parameters) // TODO
    {
        $this->requireParameterCount(1, $parameters, 'gt');

        $comparedToValue = $this->getValue($parameters[0]);

        $this->shouldBeNumeric($attribute, 'Gt');

        if (is_null($comparedToValue) && (is_numeric($value) && is_numeric($parameters[0]))) {
            return $this->getSize() > $parameters[0];
        }

        if (!$this->isSameType($value, $comparedToValue)) {
            return false;
        }

        return $this->getSize() > $this->getSize($attribute, $comparedToValue);
    }

    /**
     * Parse an attribute is greater than or equal another attribute.
     *
     * @param array $parameters
     * @return void
     */
    public function parseGte($parameters) // TODO
    {
        $this->requireParameterCount(1, $parameters, 'gte');

        $comparedToValue = $this->getValue($parameters[0]);

        $this->shouldBeNumeric($attribute, 'Gte');

        if (is_null($comparedToValue) && (is_numeric($value) && is_numeric($parameters[0]))) {
            return $this->getSize() >= $parameters[0];
        }

        if (!$this->isSameType($value, $comparedToValue)) {
            return false;
        }

        return $this->getSize() >= $this->getSize($attribute, $comparedToValue);
    }

    /**
     * Validate the MIME type of a file is an image MIME type.
     *
     * @return void
     */
    public function parseImage(): void
    {
        $this->type = self::TYPE_FILE;
        $this->fileExtension = ['jpeg', 'png', 'gif', 'bmp', 'svg'];
    }

    /**
     * Validate an attribute is contained within a list of values.
     *
     * @param array $parameters
     * @return void
     */
    public function parseIn($parameters): void
    {
        $this->enum = $parameters;
    }

    /**
     * Parse the values of an attribute is in another attribute.
     *
     * @param array $parameters
     * @return void
     */
    public function parseInArray($parameters) // TODO
    {
        $this->requireParameterCount(1, $parameters, 'in_array');

        $explicitPath = ValidationData::getLeadingExplicitAttributePath($parameters[0]);

        $attributeData = ValidationData::extractDataFromPath($explicitPath, $this->data);

        $otherValues = Arr::where(Arr::dot($attributeData), function ($value, $key) use ($parameters) {
            return Str::is($parameters[0], $key);
        });

        return in_array($value, $otherValues);
    }

    /**
     * Parse an attribute is an integer.
     *
     * @return void
     */
    public function parseInteger(): void
    {
        $this->type = self::TYPE_INTEGER;
    }

    /**
     * Parse an attribute is a valid IP.
     *
     * @return void
     */
    public function parseIp(): void
    {
        $this->type = self::TYPE_STRING;
        $this->example = $this->faker->ipv4;
    }

    /**
     * Parse an attribute is a valid IPv4.
     *
     * @return void
     */
    public function parseIpv4(): void
    {
        $this->type = self::TYPE_STRING;
        $this->example = $this->faker->ipv4;
    }

    /**
     * Parse an attribute is a valid IPv6.
     *
     * @return void
     */
    public function parseIpv6(): void
    {
        $this->type = self::TYPE_STRING;
        $this->example = $this->faker->ipv6;
    }

    /**
     * Validate the attribute is a valid JSON string.
     *
     * @return void
     */
    public function parseJson(): void
    {
        $this->type = self::TYPE_STRING;
        $this->example = json_encode(['name' => $this->faker->name]);
    }

    /**
     * Parse an attribute is less than another attribute.
     *
     * @param array $parameters
     * @return void
     */
    public function parseLt($parameters) // TODO
    {
        $this->requireParameterCount(1, $parameters, 'lt');

        $comparedToValue = $this->getValue($parameters[0]);

        $this->shouldBeNumeric($attribute, 'Lt');

        if (is_null($comparedToValue) && (is_numeric($value) && is_numeric($parameters[0]))) {
            return $this->getSize() < $parameters[0];
        }

        if (!$this->isSameType($value, $comparedToValue)) {
            return false;
        }

        return $this->getSize() < $this->getSize($attribute, $comparedToValue);
    }

    /**
     * Parse an attribute is less than or equal another attribute.
     *
     * @param array $parameters
     * @return void
     */
    public function parseLte($parameters) // TODO
    {
        $this->requireParameterCount(1, $parameters, 'lte');

        $comparedToValue = $this->getValue($parameters[0]);

        $this->shouldBeNumeric($attribute, 'Lte');

        if (is_null($comparedToValue) && (is_numeric($value) && is_numeric($parameters[0]))) {
            return $this->getSize() <= $parameters[0];
        }

        if (!$this->isSameType($value, $comparedToValue)) {
            return false;
        }

        return $this->getSize() <= $this->getSize($attribute, $comparedToValue);
    }

    /**
     * Validate the size of an attribute is less than a maximum value.
     *
     * @param array $parameters
     * @return void
     */
    public function parseMax($parameters): void
    {
        $this->requireParameterCount(1, $parameters, 'max');

        $this->max = $parameters[0];
    }

    /**
     * Validate the MIME type of a file upload attribute is in a set of MIME types.
     *
     * @param array $parameters
     * @return void
     */
    public function parseMimetypes($parameters) // TODO
    {
        if (!$this->isValidFileInstance($value)) {
            return false;
        }

        if ($this->shouldBlockPhpUpload($value, $parameters)) {
            return false;
        }

        return $value->getPath() !== '' &&
            (in_array($value->getMimeType(), $parameters) ||
                in_array(explode('/', $value->getMimeType())[0] . '/*', $parameters));
    }

    /**
     * Validate the guessed extension of a file upload is in a set of file extensions.
     *
     * @param array $parameters
     * @return void
     */
    public function parseMimes($parameters) // TODO
    {
        if (!$this->isValidFileInstance($value)) {
            return false;
        }

        if ($this->shouldBlockPhpUpload($value, $parameters)) {
            return false;
        }

        return $value->getPath() !== '' && in_array($value->guessExtension(), $parameters);
    }

    /**
     * Validate the size of an attribute is greater than a minimum value.
     *
     * @param array $parameters
     * @return void
     */
    public function parseMin($parameters): void
    {
        $this->requireParameterCount(1, $parameters, 'min');

        $this->min = $parameters[0];
    }

    /**
     * Validate an attribute is not contained within a list of values.
     *
     * @param array $parameters
     * @return void
     */
    public function parseNotIn($parameters): void
    {
        return;
    }

    /**
     * Parse an attribute does not pass a regular expression check.
     *
     * @param array $parameters
     * @return void
     */
    public function parseNotRegex($parameters): void
    {
        return;
    }

    /**
     * "Indicate" validation should pass if value is null.
     *
     * Always returns true, just lets us put "nullable" in rules.
     *
     * @return void
     */
    public function parseNullable(): void
    {
        return;
    }

    /**
     * Parse an attribute is numeric.
     *
     * @return void
     */
    public function parseNumeric(): void
    {
        $this->type = self::TYPE_NUMBER;
    }

    /**
     * Parse an attribute exists even if not filled.
     *
     * @return void
     */
    public function parsePresent(): void
    {
        $this->required = true;
    }

    /**
     * Parse an attribute passes a regular expression check.
     *
     * @param array $parameters
     * @return void
     */
    public function parseRegex($parameters) // TODO
    {
        if (!is_string($value) && !is_numeric($value)) {
            return false;
        }

        $this->requireParameterCount(1, $parameters, 'regex');

        return preg_match($parameters[0], $value) > 0;
    }

    /**
     * Parse a required attribute exists.
     *
     * @return void
     */
    public function parseRequired(): void
    {
        $this->required = true;
    }

    /**
     * Parse an attribute exists when another attribute has a given value.
     *
     * @param mixed $parameters
     * @return void
     */
    public function parseRequiredIf($parameters): void
    {
        $this->required = true;
        return;
    }

    /**
     * Parse an attribute exists when another attribute does not have a given value.
     *
     * @param mixed $parameters
     * @return void
     */
    public function parseRequiredUnless($parameters): void
    {
        $this->required = true;
        return;
    }

    /**
     * Parse an attribute exists when any other attribute exists.
     *
     * @param mixed $parameters
     * @return void
     */
    public function parseRequiredWith($parameters): void
    {
        $this->required = true;
        return;
    }

    /**
     * Parse an attribute exists when all other attributes exists.
     *
     * @param mixed $parameters
     * @return void
     */
    public function parseRequiredWithAll($parameters): void
    {
        $this->required = true;
        return;
    }

    /**
     * Parse an attribute exists when another attribute does not.
     *
     * @param mixed $parameters
     * @return void
     */
    public function parseRequiredWithout($parameters): void
    {
        $this->required = true;
        return;
    }

    /**
     * Parse an attribute exists when all other attributes do not.
     *
     * @param mixed $parameters
     * @return void
     */
    public function parseRequiredWithoutAll($parameters): void
    {
        $this->required = true;
        return;
    }

    /**
     * Validate the size of an attribute.
     *
     * @param array $parameters
     * @return void
     */
    public function parseSize($parameters): void
    {
        $this->requireParameterCount(1, $parameters, 'size');

        $this->min = $this->max = $parameters[0];
    }

    /**
     * Validate the attribute starts with a given substring.
     *
     * @param array $parameters
     * @return void
     */
    public function parseStartsWith($parameters): void
    {
        $this->startsWith = $parameters[array_rand($parameters, 1)];
    }

    /**
     * Parse an attribute is a string.
     *
     * @return void
     */
    public function parseString(): void
    {
        $this->type = self::TYPE_STRING;
    }

    /**
     * Parse an attribute is a valid timezone.
     *
     * @return void
     */
    public function parseTimezone(): void
    {
        $this->type = self::TYPE_STRING;
        $this->example = $this->faker->timezone;
    }

    /**
     * Validate the uniqueness of an attribute value on a given database table.
     *
     * If a database column is not specified, the attribute will be used.
     *
     * @param array $parameters
     * @return void
     */
    public function parseUnique($parameters): void
    {
        $this->requireParameterCount(1, $parameters, 'unique');

        return;
    }

    /**
     * Parse an attribute is a valid URL.
     *
     * @return void
     */
    public function parseUrl(): void
    {
        $this->type = self::TYPE_STRING;
        $this->example = $this->faker->url;
    }

    /**
     * Parse an attribute is a valid UUID.
     *
     * @return void
     */
    public function parseUuid(): void
    {
        $this->type = self::TYPE_STRING;
        $this->example = $this->faker->uuid;
    }

    /**
     * "Validate" optional attributes.
     *
     * Always returns true, just lets us put sometimes in rules.
     *
     * @return void
     */
    public function parseSometimes(): void
    {
        return;
    }
}