<?php

namespace Bchalier\LaravelOpenapiDoc\App\Contracts;

use Bchalier\LaravelOpenapiDoc\App\Models\ValidationExtractor;

interface Parsable
{
    /**
     * Set the necessary parameters for automatic documentation.
     *
     * @param ValidationExtractor $extractor
     * @return mixed
     */
    public function parse(ValidationExtractor &$extractor);

    public function __toString();
}
