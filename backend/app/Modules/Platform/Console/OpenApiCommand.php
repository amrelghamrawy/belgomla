<?php

declare(strict_types=1);

namespace App\Modules\Platform\Console;

use App\Support\Console\TypedInput;
use Illuminate\Console\Command;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Compiles the hand-written spec (backend/openapi/openapi.yaml) into backend/openapi.json, which the
 * frontend turns into TypeScript types (tasks/8-frontend-foundation.md). The YAML is the source of truth.
 */
final class OpenApiCommand extends Command
{
    use TypedInput;

    protected $signature = 'api:openapi
        {--source= : YAML spec (default: openapi/openapi.yaml)}
        {--output= : JSON file to write (default: openapi.json)}';

    protected $description = 'Compile the OpenAPI spec from YAML to JSON for the frontend';

    public function handle(): int
    {
        $source = $this->stringOption('source', base_path('openapi/openapi.yaml')) ?? '';
        $output = $this->stringOption('output', base_path('openapi.json')) ?? '';

        if (! is_file($source)) {
            $this->error("Spec not found: {$source}");

            return self::FAILURE;
        }

        try {
            $spec = Yaml::parseFile($source);
        } catch (ParseException $e) {
            $this->error('Invalid YAML: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! is_array($spec) || ! is_string($spec['openapi'] ?? null) || ! str_starts_with($spec['openapi'], '3.') || ! is_array($spec['paths'] ?? null)) {
            $this->error('Not an OpenAPI 3 document: "openapi" and "paths" are required.');

            return self::FAILURE;
        }

        file_put_contents($output, json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");

        $this->info(sprintf('Wrote %s (%d paths).', $output, count($spec['paths'])));

        return self::SUCCESS;
    }
}
