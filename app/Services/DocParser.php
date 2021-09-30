<?php

namespace Bchalier\LaravelOpenapiDoc\App\Services;

use Bchalier\LaravelOpenapiDoc\App\Exceptions\JsonResourceNoFactory;
use Bchalier\LaravelOpenapiDoc\App\Exceptions\JsonResourceNoType;
use Bchalier\LaravelOpenapiDoc\App\Exceptions\ResponseTypeNotSupported;
use Bchalier\LaravelOpenapiDoc\App\Tags\DocForceTypeTag;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use phpDocumentor\Reflection\DocBlockFactory;

class DocParser
{
    protected $router;

    /** @var \phpDocumentor\Reflection\DocBlockFactory */
    protected $docBlockFactory;

    protected $customTags = [
        'autodoc-type' => '\\' . DocForceTypeTag::class,
    ];

    /**
     * DocParser constructor.
     *
     * @param Router $router
     */
    public function __construct(Router $router)
    {
        $this->router = $router;
        $this->docBlockFactory = DocBlockFactory::createInstance($this->customTags);
    }

    /**
     * @return RouteCollection
     */
    public function getRoutes(): RouteCollection
    {
        return $this->router->getRoutes();
    }

    /**
     * @param Route $route
     * @return array
     * @throws JsonResourceNoType
     * @throws ResponseTypeNotSupported
     * @throws \ReflectionException
     */
    public function getResponses(Route $route): array
    {
        $responses = [];

        if ($response = $this->getDefaultResponse($route)) {
            $responses[] = $response;
        }

        return $responses;
    }

    /**
     * Find the default response (usually a resource).
     *
     * @param Route $route
     * @return object
     * @throws JsonResourceNoType
     * @throws ResponseTypeNotSupported
     * @throws \ReflectionException
     */
    protected function getDefaultResponse(Route $route): ?object
    {
        $returnType = $this->getReturnType($route);

        if (empty($returnType)) {
            return null;
        }

        $responseClassName = $returnType->getName();

        if (!class_exists($responseClassName)) {
            return null;
        }

        $responseClassReflection = new \ReflectionClass($responseClassName);
        $responseClassInstance = $responseClassReflection->newInstanceWithoutConstructor();

        if ($responseClassInstance instanceof ResourceCollection) {
            $responseClass = new $responseClassName($this->getResourceCollectionArguments($responseClassInstance));
        } elseif ($responseClassInstance instanceof JsonResource) {
            $responseClass = new $responseClassName($this->getResourceArguments($responseClassInstance));
        } elseif ($responseClassInstance instanceof JsonResponse) {
            $responseClass = new $responseClassName($this->getResponseArguments($responseClassInstance));
        } elseif ($responseClassInstance instanceof RedirectResponse) {
            $responseClass = new JsonResponse(null, 302);
        } else {
            throw new ResponseTypeNotSupported($responseClassName);
        }

        return $responseClass;
    }

    /**
     * @param Route $route
     * @return \ReflectionNamedType|null
     * @throws \ReflectionException
     */
    protected function getReturnType(Route $route): ?\ReflectionNamedType
    {
        $controller = new \ReflectionClass($route->getController());
        $method = $controller->getMethod($route->getActionMethod());

        return $method->getReturnType();
    }

    /**
     * @param ResourceCollection $resourceCollection
     * @return Collection
     * @throws JsonResourceNoType
     * @throws \ReflectionException
     */
    protected function getResourceCollectionArguments(ResourceCollection $resourceCollection): Collection
    {
        $model = $this->getResourceCollectionModel($resourceCollection);

        $this->ensureFactoryTraitPresence($model);

        $collection = $model::factory()->count(2)->make();

        foreach ($collection as $item) {
            $this->configureModel($item);
        }

        return $collection;
    }

    /**
     * Find the model of the specified resource collection.
     *
     * @param ResourceCollection $resourceCollection
     * @return string
     * @throws JsonResourceNoType
     * @throws \ReflectionException
     */
    protected function getResourceCollectionModel(ResourceCollection $resourceCollection): string
    {
        $resourceCollectionReflection = new \ReflectionClass($resourceCollection);

        $collectMethod = $resourceCollectionReflection->getMethod('collects');
        $collectMethod->setAccessible(true);
        $resourceClass = $collectMethod->invoke($resourceCollection);

        $resource = (new \ReflectionClass($resourceClass))->newInstanceWithoutConstructor();

        return $this->getResourceModel($resource);
    }

    /**
     * Find the model of the specified resource.
     *
     * @param JsonResource $resource
     * @return string|null
     * @throws JsonResourceNoType
     * @throws \ReflectionException
     */
    protected function getResourceModel(JsonResource $resource): string
    {
        $resourceReflection = new \ReflectionClass($resource);
        $constructMethod = $resourceReflection->getMethod('__construct');

        if ($type = $this->methodTypeFromPhpdoc($constructMethod) ?? method_type($constructMethod)) {
            return ltrim($type, '\\');
        } else {
            throw new JsonResourceNoType($resource);
        }
    }

    protected function methodTypeFromPhpdoc(\ReflectionMethod $method)
    {
        $docComment = $method->getDocComment();

        if (empty($docComment)) {
            return null;
        }

        $docBlock = $this->docBlockFactory->create($docComment);
        $tag = $docBlock->getTagsByName('autodoc-type');

        return empty($tag) ? null : $tag[0]->getType();
    }

    /**
     * @param JsonResource $resource
     * @return Model
     * @throws JsonResourceNoType
     * @throws \ReflectionException
     */
    protected function getResourceArguments(JsonResource $resource): Model
    {
        $this->ensureFactoryTraitPresence($resource);

        return tap($this->getResourceModel($resource)::factory()->make(), function ($model) {
            $this->configureModel($model);
        });
    }

    /**
     * @param JsonResponse $response
     * @return Model|null
     * @throws \ReflectionException
     */
    protected function getResponseArguments(JsonResponse $response): ?Model
    {
        $model = $this->getResponseModel($response);

        if (empty($model)) {
            return null;
        }

        $this->ensureFactoryTraitPresence($model);

        if ($model) {
            return tap($model::factory()->make(), function ($model) {
                $this->configureModel($model);
            });
        }

        return null;
    }

    protected function ensureFactoryTraitPresence(JsonResource|string $resource): void
    {
        $class = is_string($resource) ? $resource : get_class($resource);

        if (!in_array('Bchalier\SystemModules\Core\App\Concerns\HasFactory', class_uses_recursive($resource))) {
            throw new JsonResourceNoFactory($class);
        }
    }

    /**
     * @param JsonResponse $response
     * @return string|null
     * @throws \ReflectionException
     */
    protected function getResponseModel(JsonResponse $response): ?string
    {
        $resourceReflection = new \ReflectionClass($response);
        $constructMethod = $resourceReflection->getMethod('__construct');

        foreach ($constructMethod->getParameters() as $parameter) {
            if ($parameter->hasType()) {
                return $parameter->getType()->getName();
            }
        }

        return null;
    }

    /**
     * @param Route $route
     * @return FormRequest|null
     */
    public function getRequest(Route $route): ?FormRequest
    {
        $routeParameters = $route->signatureParameters();

        /** @var \ReflectionParameter $routeParameter */
        foreach ($routeParameters as $routeParameter) {
            if ($routeParameter->hasType() && !$routeParameter->getType()->isBuiltin()) {
                $parameter = new ($routeParameter->getType()->getName());

                if ($parameter instanceof FormRequest) {
                    $parameter->headers->set('Accept', 'application/vnd.api+json');
                    return $parameter;
                }
            }
        }

        return null;
    }

    protected function configureModel($model)
    {
        $this->addUuidIfNeeded($model);
        $this->initStateIfNeeded($model);
    }

    protected function addUuidIfNeeded($model)
    {
        if (in_array('Dyrynda\Database\Support\GeneratesUuid', class_uses_recursive(get_class($model)))) {
            $model->uuid = $model->resolveUuid();
        }
    }

    protected function initStateIfNeeded($model)
    {
        if (method_exists($model, 'initState')) {
            $model->initState();
        }
    }
}