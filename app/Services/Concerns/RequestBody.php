<?php

namespace Bchalier\LaravelOpenapiDoc\App\Services\Concerns;

use Bchalier\LaravelOpenapiDoc\App\Models\ValidationExtractor;
use Bchalier\LaravelOpenapiDoc\App\Services\DocParser;
use GoldSpecDigital\ObjectOrientedOAS\Objects\{
    MediaType as OASMediaType,
    RequestBody as OASRequestBody,
    Schema as OASSchema
};
use Illuminate\Foundation\Http\FormRequest;

trait RequestBody
{
    /** @var DocParser */
    protected $parser;

    /**
     * @param FormRequest|null $request
     * @return OASRequestBody|null
     * @throws \GoldSpecDigital\ObjectOrientedOAS\Exceptions\InvalidArgumentException
     */
    protected function getRequestBody(?FormRequest $request): ?OASRequestBody
    {
        if (is_null($request)) return null;

        $properties = $required = [];

        foreach ($this->getRules($request) as $property => $ruleSet) {
            $property = new ValidationExtractor($property);
            $property->setRules($ruleSet);
            $property->guessTheBlanks();

            $properties[] = $this->schemaFromProperty($property);

            if ($property->isRequired()) {
                $required[] = $property->getName();
            }
        }

        $schema = OASSchema::object()
            ->properties(...$properties)
            ->required(...$required);

        return OASRequestBody::create()
            ->content(OASMediaType::json()->schema($schema))
            ->required()
            ->description('test');
    }

    protected function getRules(FormRequest $request): array
    {
        return method_exists($request, 'documentationRules') ? $request->documentationRules() : $request->rules();
    }

    /**
     * @param ValidationExtractor $property
     * @return OASSchema
     */
    protected function schemaFromProperty(ValidationExtractor $property): OASSchema
    {
        $schema = OASSchema::create($property->getName())
            ->type($property->getType())
            ->enum($property->getEnum())
            ->example($property->getExample())
//            ->description(implode("<br/>", $property->getMessages())); // TODO chose one
            ->description(implode(", ", $property->getRules()));

        if (is_array($property->getEnum())) {
            $schema->enum($property->getEnum());
        }

        return $schema;
    }
}