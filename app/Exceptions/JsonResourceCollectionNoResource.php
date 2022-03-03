<?php

namespace Bchalier\LaravelOpenapiDoc\App\Exceptions;

use Illuminate\Http\Resources\Json\ResourceCollection;

class JsonResourceCollectionNoResource extends \Exception
{
    /**
     * @param            $resource [optional]
     * @param \Throwable $previous [optional] The previous throwable used for the exception chaining.
     *
     * @return void
     */
    public function __construct(ResourceCollection $collection, \Throwable $previous = null)
    {
        $message = 'The resource collection ' . get_class($collection) . ' need a created JsonResource in order to document it';

        parent::__construct($message, null, $previous);
    }
}
