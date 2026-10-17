<?php

declare(strict_types=1);

namespace App\Support\Console;

use InvalidArgumentException;

/**
 * Typed accessors for console arguments/options, so commands never pass `mixed` into services.
 */
trait TypedInput
{
    protected function stringArgument(string $name): string
    {
        $value = $this->argument($name);

        if (! is_string($value)) {
            throw new InvalidArgumentException("Argument \"{$name}\" must be a string.");
        }

        return $value;
    }

    protected function stringOption(string $name, ?string $default = null): ?string
    {
        $value = $this->option($name);

        if (in_array($value, [null, '', false], true)) {
            return $default;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException("Option \"{$name}\" must be a string.");
        }

        return $value;
    }

    protected function askString(string $question, bool $secret = false): string
    {
        $answer = $secret ? $this->secret($question) : $this->ask($question);

        if (! is_string($answer) || $answer === '') {
            throw new InvalidArgumentException("{$question} is required.");
        }

        return $answer;
    }
}
