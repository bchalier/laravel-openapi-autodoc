<?php

namespace Bchalier\LaravelOpenapiDoc\App\Contracts;

interface DocumentableRequest
{
    public function queryRules(): array;
    public function bodyRules(): array;
}
