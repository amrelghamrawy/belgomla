<?php

declare(strict_types=1);

beforeEach(function (): void {
    $this->output = sys_get_temp_dir().'/openapi-'.uniqid().'.json';
});

afterEach(function (): void {
    @unlink($this->output);
});

test('api:openapi compiles the yaml spec into json', function (): void {
    $this->artisan('api:openapi', ['--output' => $this->output])
        ->expectsOutputToContain('paths')
        ->assertSuccessful();

    $json = json_decode((string) file_get_contents($this->output), true, flags: JSON_THROW_ON_ERROR);

    expect($json)->toBe(openApiSpec())
        ->and($json['openapi'])->toBe('3.1.0');
});

test('the committed openapi.json is up to date with the yaml', function (): void {
    $this->artisan('api:openapi', ['--output' => $this->output])->assertSuccessful();

    expect(file_get_contents(base_path('openapi.json')))->toBe(file_get_contents($this->output));
});

test('api:openapi refuses a spec it cannot use', function (string $source, string $message): void {
    $this->artisan('api:openapi', ['--source' => $source, '--output' => $this->output])
        ->expectsOutputToContain($message)
        ->assertFailed();

    expect(file_exists($this->output))->toBeFalse();
})->with([
    'missing file' => [__DIR__.'/../Fixtures/openapi/ghost.yaml', 'Spec not found'],
    'malformed yaml' => [__DIR__.'/../Fixtures/openapi/malformed.yaml', 'Invalid YAML'],
    'not openapi 3' => [__DIR__.'/../Fixtures/openapi/swagger2.yaml', 'Not an OpenAPI 3 document'],
]);
