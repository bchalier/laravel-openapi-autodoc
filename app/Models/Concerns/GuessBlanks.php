<?php

namespace Bchalier\LaravelOpenapiDoc\App\Models\Concerns;

trait GuessBlanks
{
    protected function guessExample()
    {
        if ($this->example !== null) {
            return;
        }

        if (is_array($this->enum) && count($this->enum) > 0) {
            $this->example = $this->enum[0];
            return;
        }

        $type = $this->type;
        if ($type === self::TYPE_BOOLEAN) {
            $this->example = true;
            return;
        }
        if ($type === self::TYPE_INTEGER) {
            $this->example = $this->guessNumericExample(true);
            return;
        }
        if ($type === self::TYPE_NUMBER) {
            $this->example = $this->guessNumericExample(false);
            return;
        }
        if ($type === self::TYPE_ARRAY) {
            $this->example = [];
            return;
        }
        if ($type === self::TYPE_OBJECT) {
            $this->example = (object) [];
            return;
        }

        $this->example = $this->guessStringExample();
    }

    protected function generateRandomString($regex)
    {

    }

    protected function guessNumericExample(bool $integer): int|float
    {
        $candidate = null;

        if (isset($this->min) && is_numeric($this->min)) {
            $candidate = $this->min;
        } elseif (isset($this->max) && is_numeric($this->max)) {
            $candidate = $this->max;
        } else {
            $candidate = $integer ? 1 : 1.5;
        }

        return $integer ? (int) $candidate : (float) $candidate;
    }

    protected function guessStringExample(): string
    {
        $name = strtolower((string) $this->name);

        if ($name !== '') {
            if (str_contains($name, 'email')) {
                return 'user@example.com';
            }
            if (str_contains($name, 'uuid') || $name === 'id' || str_ends_with($name, '_id')) {
                return '00000000-0000-0000-0000-000000000000';
            }
            if (str_contains($name, 'url')) {
                return 'https://example.com';
            }
            if (str_contains($name, 'ip')) {
                return '127.0.0.1';
            }
            if (str_contains($name, 'date')) {
                return '2025-01-01';
            }
            if (str_contains($name, 'time')) {
                return '2025-01-01T00:00:00Z';
            }
            if (str_contains($name, 'name')) {
                return 'Example';
            }
            if (str_contains($name, 'title')) {
                return 'Example title';
            }
            if (str_contains($name, 'description')) {
                return 'Example description';
            }
        }

        if (!empty($this->validCharacters)) {
            $chars = array_slice($this->validCharacters, 0, 8);
            return implode('', $chars);
        }

        return 'string';
    }
}
