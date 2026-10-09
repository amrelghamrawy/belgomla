<?php

declare(strict_types=1);

use App\Support\Http\PaginatedRequest;

function paginated(array $query): PaginatedRequest
{
    $request = new class extends PaginatedRequest {};
    $request->query->replace($query);

    return $request;
}

test('per page defaults and sort parsing', function (array $query, int $perPage, ?array $sort): void {
    $request = paginated($query);

    expect($request->perPage())->toBe($perPage)
        ->and($request->sort())->toBe($sort);
})->with([
    'defaults' => [[], 24, null],
    'explicit' => [['per_page' => '10', 'sort' => 'name'], 10, ['name', 'asc']],
    'descending' => [['sort' => '-created_at'], 24, ['created_at', 'desc']],
]);

test('rules cap per_page and constrain sort', function (): void {
    expect(paginated([])->rules())->toMatchArray([
        'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
    ]);
});
