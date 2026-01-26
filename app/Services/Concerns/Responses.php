<?php

namespace Bchalier\LaravelOpenapiDoc\App\Services\Concerns;

use App\Exceptions\Handler;
use App\Http\Resources\JsonApiResource;
use Bchalier\LaravelOpenapiDoc\App\Exceptions\JsonResourceNoType;
use Bchalier\LaravelOpenapiDoc\App\Exceptions\ResponseTypeNotSupported;
use Illuminate\Http\Response;
use GoldSpecDigital\ObjectOrientedOAS\Objects\{MediaType as OASMediaType, Response as OASResponse};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Bchalier\LaravelOpenapiDoc\App\Contracts\DocumentedResource;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Log;
use phpDocumentor\Reflection\DocBlock\Tags\Throws;
use phpDocumentor\Reflection\Types\Object_;
use ReflectionClass;
use ReflectionException;
use Throwable;

trait Responses
{
    /**
     * @param Route $route
     * @param bool $hasBody
     * @return array
     * @throws ResponseTypeNotSupported
     * @throws JsonResourceNoType
     * @throws ReflectionException
     */
    protected function getResponses(Route $route, $hasBody = true): array
    {
        $responses = [];

        try {
            foreach ($this->parser->getResponses($route) as $response) {
                try {
                    $responses[] = $this->getResponse($response, $hasBody);
                } catch (Throwable $inner) {
                    Log::warning('[autodoc] response build failed: ' . $inner->getMessage());
                }
            }
            // Also merge phpdoc-declared errors
            $responses = array_merge($this->responsesFromPhpdoc($route), $responses);
        } catch (Throwable $e) {
            Log::warning('[autodoc] response inference failed: ' . $e->getMessage());
        }

        // Fallbacks if nothing was inferred
        if (empty($responses)) {
            if ($fallback = $this->fallbackResponseFromController($route, $hasBody)) {
                $responses[] = $fallback;
            } else {
                $responses[] = OASResponse::create()
                    ->statusCode($hasBody ? 200 : 204)
                    ->description('Undocumented response');
            }
        }

        return $responses;
    }

    /**
     * @param      $response
     * @param bool $hasBody
     * @return OASResponse|null
     * @throws ResponseTypeNotSupported
     * @throws ReflectionException
     */
    protected function getResponse($response, $hasBody = true): ?OASResponse
    {
        if ($response instanceof JsonResource) {
            if ($response instanceof DocumentedResource) {
                $schema = $this->schemaForDocumentedResource($response);
                $summary = $this->getSummary(new ReflectionClass($response), 'toArray');
                $status = $hasBody ? 200 : 204;
                $oas = OASResponse::create()->statusCode($status)->description($summary);
                return $hasBody ? $oas->content(OASMediaType::json()->schema($schema)) : $oas;
            }
            return $this->responseFromResource($response, $hasBody);
        } elseif ($response instanceof JsonResponse) {
            return $this->responseFromResponse($response, $hasBody);
        } elseif ($response instanceof Response) {
            $summary = $this->getSummary(new ReflectionClass($response), '__construct');
            $oas = OASResponse::create()->statusCode($response->getStatusCode())->description($summary);
            $content = json_decode($response->getContent(), true);
            return $hasBody && is_array($content)
                ? $oas->content(OASMediaType::json()->schema($this->extractFromArray($content)))
                : $oas;
        } else {
            throw new ResponseTypeNotSupported($response);
        }
    }

    /**
     * @param JsonResource $resource
     * @param bool $hasBody
     * @return OASResponse
     * @throws ResponseTypeNotSupported
     * @throws ReflectionException
     */
    protected function responseFromResource(JsonResource $resource, $hasBody = true): OASResponse
    {
        $req = HttpRequest::create('/', 'GET');
        $req->headers->set('Accept', 'application/vnd.api+json');
        $summary = $this->getSummary(new ReflectionClass($resource), 'toArray');

        if ($resource instanceof JsonApiResource) {
            $status = $hasBody ? 200 : 204;
            $schema = $this->schemaFromResource($resource, true);
        } else {
            try {
                $resp = $resource->toResponse($req);
                $status = $resp->getStatusCode();
                $schema = $this->schemaFromResource($resource);
            } catch (Throwable $e) {
                // Fallback if rendering fails; still provide schema from attributes only
                $status = $hasBody ? 200 : 204;
                $schema = $this->schemaFromResource($resource, true);
            }
        }

        $oas = OASResponse::create()
            ->statusCode($status)
            ->description($summary);

        return $hasBody && $schema ? $oas->content(
            OASMediaType::json()->schema($schema)
        ) : $oas;
    }

    /**
     * @param JsonResponse $jsonResponse
     * @param bool $hasBody
     * @return OASResponse
     * @throws ResponseTypeNotSupported
     * @throws ReflectionException
     */
    protected function responseFromResponse(JsonResponse $jsonResponse, $hasBody = true): OASResponse
    {
        $summary = $this->getSummary(new ReflectionClass($jsonResponse), '__construct');

        $response = OASResponse::create()
            ->statusCode($jsonResponse->getStatusCode())
            ->description($summary);

        return $hasBody ? $response->content(
            OASMediaType::json()->schema($this->extractFromArray($jsonResponse->getData(true)))
        ) : $response;
    }

    /**
     * @param Route $route
     * @return array
     * @throws ResponseTypeNotSupported
     * @throws ReflectionException
     */
    protected function responsesFromPhpdoc(Route $route): array
    {
        $errors = $this->getPhpdocErrorsFromRoute($route);
        $responses = [];

        foreach ($errors as $error) {
            $responses[] = $this->responseFromError($error);
        }

        return $responses;
    }

    /**
     * @param Route $route
     * @return array
     * @throws ReflectionException
     */
    protected function getPhpdocErrorsFromRoute(Route $route): array
    {
        $tags = $this->getDocBlock($this->getControllerReflection($route), $route->getActionMethod())?->getTags() ?? [];
        $classImports = $this->getClassImports($this->getControllerReflection($route));
        $errors = [];

        foreach ($tags as $tag) {
            if ($tag->getName() !== 'throws') continue;

            /** @var $tag Throws */
            $type = $tag->getType();

            if ($type instanceof Object_) {
                $class = $type->getFqsen()->getName();

                if (!class_exists($class)) {
                    $class = $classImports[strtolower($class)] ?? $class;
                }

                if (class_exists($class)) {
                    $errors[] = $class;
                }
            }
        }

        return $errors;
    }

    /**
     * @param string $error
     * @return OASResponse
     * @throws ResponseTypeNotSupported
     * @throws ReflectionException
     */
    protected function responseFromError(string $error): OASResponse
    {
        $handler = new Handler(app());
        $request = new Request();
        $request->headers->set('Accept', 'application/vnd.api+json');

        $response = $handler->render($request, new $error);

        $content = json_decode($response->getContent(), true);
        $summary = $this->getSummary(new ReflectionClass($error), '__construct');

        return OASResponse::create()
            ->statusCode($response->getStatusCode())
            ->description($summary)
            ->content(
                OASMediaType::json()->schema($this->extractFromArray($content))
            );
    }

    /**
     * Try to fall back to controller return type to build a response
     */
    protected function fallbackResponseFromController(Route $route, bool $hasBody): ?OASResponse
    {
        try {
            $classes = $this->parser->getReturnTypeCandidates($route);
            foreach ($classes as $class) {
                if (!class_exists($class) || !is_subclass_of($class, JsonResource::class)) {
                    continue;
                }
                /** @var JsonResource $instance */
                $instance = new $class(null);
                return $this->getResponse($instance, $hasBody);
            }
        } catch (Throwable $e) {
            Log::info('[autodoc] fallbackResponseFromController failed: ' . $e->getMessage());
        }
        return null;
    }
}
