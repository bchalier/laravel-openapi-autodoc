<?php

namespace Bchalier\LaravelOpenapiDoc\App\Exceptions;

class JsonResourceNoFactory extends \Exception
{
    /**
     * @param $model
     * @param  \Throwable|null  $previous  [optional] The previous throwable used for the exception chaining.
     *
     */
    public function __construct($model, \Throwable $previous = null)
    {
        $message = "The model $model needs to use the \Bchalier\SystemModules\Core\App\Concerns\HasFactory trait to be documented.";

        parent::__construct($message, null, $previous);
    }
}
