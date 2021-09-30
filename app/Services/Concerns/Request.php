<?php

namespace Bchalier\LaravelOpenapiDoc\App\Services\Concerns;

use Bchalier\LaravelOpenapiDoc\App\Contracts\DocumentableRequest;
use Bchalier\LaravelOpenapiDoc\App\Models\ValidationExtractor;
use Bchalier\LaravelOpenapiDoc\App\Services\DocParser;
use GoldSpecDigital\ObjectOrientedOAS\Objects\{
    MediaType as OASMediaType,
    Parameter as OASParameter,
    RequestBody as OASRequestBody,
    Schema as OASSchema,
};
use Illuminate\Foundation\Http\FormRequest;

trait Request
{
    protected DocParser $parser;

    /**
     * @param FormRequest|null $request
     * @return OASRequestBody|null
     * @throws \GoldSpecDigital\ObjectOrientedOAS\Exceptions\InvalidArgumentException
     */
    protected function getRequestBody(?FormRequest $request): ?OASRequestBody
    {
        if (is_null($request)) {
            return null;
        }

        [$properties, $required] = $this->extractRules($this->bodyRules($request));

        if (empty($properties)) {
            return null;
        }

        $schema = OASSchema::object()
            ->properties(...$properties)
            ->required(...$required);

        return OASRequestBody::create()
            ->content(OASMediaType::json()->schema($schema))
            ->required();
    }

    /**
     * @param  FormRequest|null  $request
     *
     * @return array
     * @throws \GoldSpecDigital\ObjectOrientedOAS\Exceptions\InvalidArgumentException
     */
    protected function getRequestQueryParameters(?FormRequest $request): array
    {
        if (is_null($request)) {
            return [];
        }

        $parameters = [];

        foreach ($this->queryRules($request) as $property => $ruleSet) {
            if (!is_array($ruleSet)) {
                $ruleSet = [$ruleSet];
            }

            $extractor = $this->extractor($property, $ruleSet);

            $parameters[] = OASParameter
                ::create()
                ->in(OASParameter::IN_QUERY)
                ->name($this->cleanNameQuery($extractor->getName()))
                ->description($this->stringifyRules($extractor->getRules()))
                ->example($extractor->getExample())
                ->required($extractor->isRequired());
        }

        return $parameters;
    }

    protected function extractRules(array $rules): array
    {
        $properties = [];
        $required = [];

        foreach ($rules as $property => $ruleSet) {
            if (!is_array($ruleSet)) {
                $ruleSet = [$ruleSet];
            }

            $validationExtractor = $this->extractor($property, $ruleSet);

            $properties[] = $this->schemaFromValidationExtractor($validationExtractor);

            if ($validationExtractor->isRequired()) {
                $required[] = $validationExtractor->getName();
            }
        }

        return [$properties, $required];
    }

    protected function extractor(string $property, array $ruleSet): ValidationExtractor
    {
        $validationExtractor = new ValidationExtractor($property);

        $validationExtractor->setRules($ruleSet);
        $validationExtractor->guessTheBlanks();

        return $validationExtractor;
    }

    protected function bodyRules(FormRequest $request): array
    {
        if (method_exists($request, 'documentationRules')) {
            return $request->documentationRules();
        }

        if ($request instanceof DocumentableRequest) {
            return $request->bodyRules();
        }

        return $request->rules();
    }

    protected function queryRules(FormRequest $request): array
    {
        return $request instanceof DocumentableRequest
            ? $request->queryRules()
            : [];
    }

    /**
     * @param ValidationExtractor $extractor
     *
     * @return OASSchema
     */
    protected function schemaFromValidationExtractor(ValidationExtractor $extractor): OASSchema
    {
        $schema = OASSchema::create($extractor->getName())
            ->type($extractor->getType())
            ->enum($extractor->getEnum())
            ->example($extractor->getExample())
//            ->description(implode("<br/>", $property->getMessages())); // TODO chose one
            ->description($this->stringifyRules($extractor->getRules()));

        if (is_array($extractor->getEnum())) {
            $schema->enum($extractor->getEnum());
        }

        return $schema;
    }

    protected function stringifyRules(array $rules): string
    {
        return $this->recursive_implode($rules); // TODO clean that

        return implode(', ', $rules);
    }

    protected function recursive_implode(array $array, $glue = ',', $include_keys = false, $trim_all = true)
    {
        $glued_string = '';

        // Recursively iterates array and adds key/value to glued string
        array_walk_recursive($array, function ($value, $key) use ($glue, $include_keys, &$glued_string) {
            $include_keys and $glued_string .= $key . $glue;
            $glued_string .= $value . $glue;
        });

        // Removes last $glue from string
        strlen($glue) > 0 and $glued_string = substr($glued_string, 0, -strlen($glue));

        // Trim ALL whitespace
        $trim_all and $glued_string = preg_replace("/(\s)/ixsm", '', $glued_string);

        return (string) $glued_string;
    }

    protected function cleanNameQuery(string $name): string
    {
        $parts = collect(explode('.', $name));
        $firstPart = $parts->shift();

        $parts->transform(function ($part) {
            if ($part === '*') {
                return '[]';
            }

            return "[$part]";
        });

        return $firstPart . $parts->implode('');
    }
}
