<?php

namespace Bchalier\LaravelOpenapiDoc\App\Services;

use App\Http\Controllers\Controller;
use Bchalier\LaravelOpenapiDoc\App\Exceptions\ResponseTypeNotSupported;
use Doctrine\Common\Annotations\PhpParser;
use GoldSpecDigital\ObjectOrientedOAS\Objects\{
    Info as OASInfo,
    Operation as OASOperation,
    Parameter as OASParameter,
    PathItem as OASPathItem,
    Schema as OASSchema,
    Tag as OASTag
};
use GoldSpecDigital\ObjectOrientedOAS\OpenApi;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use phpDocumentor\Reflection\DocBlock;
use phpDocumentor\Reflection\DocBlockFactory;

class DocGenerator
{
    use Concerns\RequestBody,
        Concerns\Responses;

    /** @var DocParser */
    protected $parser;

    /** @var \phpDocumentor\Reflection\DocBlockFactory */
    protected $docBlockFactory;

    protected $operationsByUri;
    protected $models;
    protected $tags;

    /**
     * DocGenerator constructor.
     *
     * @param Router $router
     */
    public function __construct(Router $router)
    {
        $this->parser = new DocParser($router);
        $this->docBlockFactory = DocBlockFactory::createInstance();
    }

    /**
     * @return OpenApi
     * @throws ResponseTypeNotSupported
     * @throws \Bchalier\LaravelOpenapiDoc\App\Exceptions\JsonResourceNoType
     * @throws \ReflectionException
     * @throws \GoldSpecDigital\ObjectOrientedOAS\Exceptions\InvalidArgumentException
     */
    public function generate(): OpenApi
    {
        return OpenApi::create()
            ->openapi(OpenApi::OPENAPI_3_0_2)
            ->info($this->getInfo())
            ->paths(...$this->getPaths())
            ->tags(...array_values($this->tags));
    }

    /**
     * @return OASInfo
     */
    protected function getInfo(): OASInfo
    {
        return OASInfo::create()
            ->title('API Specification') // TODO
            ->version('v1') // TODO
            ->description('For using the Example App API'); // TODO
    }

    /**
     * @return array
     * @throws ResponseTypeNotSupported
     * @throws \Bchalier\LaravelOpenapiDoc\App\Exceptions\JsonResourceNoType
     * @throws \ReflectionException
     * @throws \GoldSpecDigital\ObjectOrientedOAS\Exceptions\InvalidArgumentException
     */
    protected function getPaths(): array
    {
        $paths = [];

        foreach ($this->parser->getRoutes() as $route) {
            if ($route->getActionMethod() === 'Closure') {
                continue;
            }

            $paths[] = $this->getPath($route);
        }

        return $paths;
    }

    /**
     * @param Route $route
     * @return OASPathItem
     * @throws ResponseTypeNotSupported
     * @throws \Bchalier\LaravelOpenapiDoc\App\Exceptions\JsonResourceNoType
     * @throws \ReflectionException
     * @throws \GoldSpecDigital\ObjectOrientedOAS\Exceptions\InvalidArgumentException
     */
    protected function getPath(Route $route): OASPathItem
    {
        return OASPathItem::create()
            ->route('/' . $route->uri)
            ->operations(...$this->getOperations($route));
//            ->parameters(...$this->getParameters($route)); // TODO
    }

    /**
     * @param Route $route
     * @return array
     * @throws ResponseTypeNotSupported
     * @throws \Bchalier\LaravelOpenapiDoc\App\Exceptions\JsonResourceNoType
     * @throws \ReflectionException
     * @throws \GoldSpecDigital\ObjectOrientedOAS\Exceptions\InvalidArgumentException
     */
    protected function getOperations(Route $route): array
    {
        $operations = $this->getCachedOperations($route);
        $summary = $this->getSummary($this->getControllerReflection($route), $route->getActionMethod());
        $description = $this->getDescription($this->getControllerReflection($route), $route->getActionMethod());

        foreach ($route->methods() as $method) {
            $operations[] = OASOperation::$method()
                ->requestBody($this->getRequestBody($this->parser->getRequest($route)))
                ->responses(...$this->getResponses($route, $method !== 'HEAD'))
                ->tags($this->getTag($this->getNameFromController($route->getController())))
                ->summary($summary)
                ->description($description)
                ->operationId($route->getName() . ".$method");
        }

        return $this->operationsByUri[$route->uri] = $operations;
    }

    /**
     * @param Route $route
     * @return array
     */
    protected function getCachedOperations(Route $route): array
    {
        return $this->operationsByUri[$route->uri] ?? [];
    }

    /**
     * @param \ReflectionClass $reflection
     * @param                  $method
     * @return string
     * @throws \ReflectionException
     */
    protected function getSummary(\ReflectionClass $reflection, $method): string
    {
        $docBlock = $this->getDocBlock($reflection, $method);

        return $docBlock ? $docBlock->getSummary() : '';
    }

    /**
     * @param \ReflectionClass $reflection
     * @param                  $method
     * @return DocBlock|null
     * @throws \ReflectionException
     */
    protected function getDocBlock(\ReflectionClass $reflection, $method): ?DocBlock
    {
        $methodReflection = $reflection->getMethod($method);
        $docComment = $methodReflection->getDocComment();

        return is_string($docComment) ? $this->docBlockFactory->create($docComment) : null;
    }

    /**
     * @param Route $route
     * @return \ReflectionClass
     * @throws \ReflectionException
     */
    protected function getControllerReflection(Route $route): \ReflectionClass
    {
        return new \ReflectionClass($route->getController());
    }

    /**
     * @param \ReflectionClass $reflection
     * @param                  $method
     * @return DocBlock\Description|string
     * @throws \ReflectionException
     */
    protected function getDescription(\ReflectionClass $reflection, $method): string
    {
        $docBlock = $this->getDocBlock($reflection, $method);

        return $docBlock ? $docBlock->getDescription() : '';
    }

    /**
     * @param $name
     * @return OASTag
     */
    protected function getTag($name): OASTag
    {
        $nameLower = strtolower($name);

        return $this->tags[$nameLower] ?? $this->tags[$nameLower] = OASTag::create()
                ->name(ucfirst($name))
                ->description("All $nameLower related endpoints");
    }

    /**
     * @param Controller $controller
     * @return string
     * @throws \ReflectionException
     */
    protected function getNameFromController(Controller $controller): string
    {
        $shortName = (new \ReflectionClass($controller))->getShortName();

        return Str::replaceLast('Controller', '', $shortName);
    }

    /**
     * @param Route $route
     * @return array
     */
    protected function getParameters(Route $route): array
    {
        $parameters = [];

        $parameters[] = OASParameter::create('id')
            ->in(OASParameter::IN_QUERY)
            ->name('test')
            ->example('test !')
            ->description('description');

        return $parameters;
    }

    /**
     * @param \ReflectionClass $class
     * @return array
     */
    protected function getClassImports(\ReflectionClass $class): array
    {
        return (new PhpParser())->parseClass($class);
    }

    /**
     * @param JsonResource $resource
     * @return OASSchema|null
     * @throws ResponseTypeNotSupported
     */
    protected function schemaFromResource(JsonResource $resource): ?OASSchema
    {
        $response = $resource->toResponse(null)->getContent();

        return $this->extractFromArray(json_decode($response, true));
    }

    /**
     * @param array|null $array
     * @return OASSchema|null
     * @throws ResponseTypeNotSupported
     */
    protected function extractFromArray(?array $array): ?OASSchema
    {
        if (is_null($array) || empty($array)) {
            return null;
        }

        $mainSchema = new OASSchema();
        $schemas = [];

        foreach ($array as $key => $property) {
            $type = gettype($property);

            switch ($type) {
                case 'object':
                    $schema = $this->extractSchemaFromObject($key, $property);
                    break;

                case 'string':
                    $schema = $this->extractSchemaFromProperty($key, $property);
                    break;

                case 'array':
                    $schema = $this->extractSchemaFromArray($key, $property);
                    break;

                default:
                    throw new ResponseTypeNotSupported($property);
                    break;
            }

            $schemas[] = $schema;
        }

        return $mainSchema->properties(...$schemas);
    }

    /**
     * @param $key
     * @param $object
     * @return OASSchema
     * @throws ResponseTypeNotSupported
     */
    protected function extractSchemaFromObject($key, $object): OASSchema
    {
        if ($object instanceof Collection) {
            $properties = $this->extractSchemaFromCollection($object);
        } elseif ($object instanceof JsonResource) {
            $properties = $this->getSchemaFromResource($object);
        } else {
            throw new ResponseTypeNotSupported($object);
        }

        return OASSchema::object($key)
            ->type(OASSchema::TYPE_ARRAY)
            ->items(Arr::first($properties));
    }

    /**
     * @param Collection $collection
     * @return array
     */
    protected function extractSchemaFromCollection(Collection $collection): array
    {
        $resources = [];

        foreach ($collection as $item) {
            $resources[] = $this->getSchemaFromResource($item);
        }

        return $resources;
    }

    /**
     * @param JsonResource $resource
     * @return OASSchema
     */
    protected function getSchemaFromResource(JsonResource $resource): OASSchema
    {
        return $this->models[$this->getResourceCacheKey($resource)] ?? $this->extractSchemaFromResource($resource);
    }

    /**
     * @param JsonResource $resource
     * @return string
     */
    protected function getResourceCacheKey(JsonResource $resource): string
    {
        return get_class($resource);
    }

    /**
     * @param JsonResource $resource
     * @return OASSchema
     */
    protected function extractSchemaFromResource(JsonResource $resource): OASSchema
    {
        $properties = $this->extractPropertiesFromArray($resource->toArray(null));

        return $this->models[$this->getResourceCacheKey($resource)] = OASSchema::object()->properties(...$properties);
    }

    /**
     * @param array $propertiesList
     * @return array
     */
    protected function extractPropertiesFromArray(array $propertiesList): array
    {
        $schemaList = [];

        foreach ($propertiesList as $key => $property) {
            $type = gettype($property);

            /** @var OASSchema $schema */
            $schema = OASSchema::$type($key);

            if ($type === OASSchema::TYPE_ARRAY) {
                $schema = $schema
                    ->type(OASSchema::TYPE_OBJECT)
                    ->properties(...$this->extractPropertiesFromArray($property));
            } else {
                $schema = $schema->example($property);
            }

            $schemaList[] = $schema;
        }

        return $schemaList;
    }

    /**
     * @param $key
     * @param $property
     * @return OASSchema
     */
    protected function extractSchemaFromProperty($key, $property): OASSchema
    {
        $type = gettype($property);

        return OASSchema::$type($key)
            ->example($property);
    }

    /**
     * @param $key
     * @param $property
     * @return OASSchema
     */
    protected function extractSchemaFromArray($key, $property): OASSchema
    {
        return OASSchema::object($key)
            ->properties(...$this->extractPropertiesFromArray($property));
    }
}