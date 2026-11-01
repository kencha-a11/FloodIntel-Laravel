<?php

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;

/**
 * The published contract, decoded once per process. It is hand-written
 * (D-122); this file is the only thing that keeps it honest.
 *
 * @return array<string, mixed>
 */
function apiContract(): array
{
    static $document;

    if ($document === null) {
        $path = public_path('openapi.json');

        expect(file_exists($path))->toBeTrue('public/openapi.json is missing.');

        $document = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    return $document;
}

/**
 * @return list<array{method: string, path: string, operation: array<string, mixed>}>
 */
function apiContractOperations(): array
{
    $operations = [];

    foreach (apiContract()['paths'] as $path => $item) {
        foreach (['get', 'post', 'put', 'patch', 'delete'] as $method) {
            if (isset($item[$method])) {
                $operations[] = [
                    'method' => strtoupper($method),
                    'path' => $path,
                    'operation' => $item[$method],
                ];
            }
        }
    }

    return $operations;
}

/**
 * @return array<string, array{method: string, path: string, operation: array<string, mixed>}>
 */
function apiContractOperationMap(): array
{
    $map = [];

    foreach (apiContractOperations() as $operation) {
        $map[apiContractKey($operation['method'], $operation['path'])] = $operation;
    }

    return $map;
}

/**
 * A route and an operation share a key when the method and the URL shape
 * match. Parameter names are compared separately, because they are part of
 * what a consumer codes against.
 */
function apiContractKey(string $method, string $path): string
{
    $shape = preg_replace('/\{[^}]+\}/', '{}', '/'.ltrim($path, '/'));

    return strtoupper($method).' '.$shape;
}

/**
 * @return list<array{method: string, route: Route}>
 */
function apiContractRoutes(): array
{
    $routes = [];

    foreach (Router::getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/')) {
            continue;
        }

        foreach ($route->methods() as $method) {
            if ($method !== 'HEAD') {
                $routes[] = ['method' => $method, 'route' => $route];
            }
        }
    }

    return $routes;
}

/**
 * The resolved middleware stack, in the same form `route:list` prints.
 *
 * @param  list<string>  $middleware
 */
function apiContractHasMiddleware(array $middleware, string $needle): bool
{
    return in_array($needle, $middleware, true);
}

/**
 * The argument of a `prefix:argument` middleware entry, or null.
 *
 * @param  list<string>  $middleware
 */
function apiContractMiddlewareArgument(array $middleware, string $prefix): ?string
{
    foreach ($middleware as $value) {
        if (str_starts_with($value, $prefix)) {
            return substr($value, strlen($prefix));
        }
    }

    return null;
}

/**
 * The form request the action validates with, or null when it takes none.
 */
function apiContractFormRequest(Route $route): ?string
{
    [$class, $method] = explode('@', $route->getActionName());

    foreach ((new ReflectionMethod($class, $method))->getParameters() as $parameter) {
        $type = $parameter->getType();

        if ($type instanceof ReflectionNamedType && is_a($type->getName(), FormRequest::class, true)) {
            return $type->getName();
        }
    }

    return null;
}

/**
 * Follow a `$ref` if the node is one; otherwise return the node.
 *
 * @param  array<string, mixed>  $node
 * @return array<string, mixed>
 */
function apiContractResolve(array $contract, array $node): array
{
    $ref = $node['$ref'] ?? null;

    if (! is_string($ref)) {
        return $node;
    }

    $target = $contract;

    foreach (array_slice(explode('/', $ref), 1) as $segment) {
        $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
        $target = is_array($target) ? ($target[$segment] ?? null) : null;
    }

    return is_array($target) ? $target : [];
}

/**
 * @return list<string>
 */
function apiContractRefs(array $node): array
{
    $refs = [];

    foreach ($node as $key => $value) {
        if ($key === '$ref' && is_string($value)) {
            $refs[] = $value;
        } elseif (is_array($value)) {
            $refs = array_merge($refs, apiContractRefs($value));
        }
    }

    return $refs;
}

it('publishes an OpenAPI 3.1 document', function () {
    $contract = apiContract();

    expect($contract['openapi'])->toBe('3.1.0')
        ->and($contract['info']['title'])->toBeString()->not->toBeEmpty()
        ->and($contract['info']['version'])->toBeString()->not->toBeEmpty()
        ->and($contract['components']['securitySchemes']['sanctum']['scheme'])->toBe('bearer')
        ->and(array_column($contract['tags'], 'name'))->not->toBeEmpty();

    foreach (array_keys($contract['paths']) as $path) {
        expect($path)->toStartWith('/api/');
    }
});

it('lists every API route exactly once, with the same parameter names', function () {
    $operations = apiContractOperationMap();
    $keys = array_map(
        fn (array $operation): string => apiContractKey($operation['method'], $operation['path']),
        apiContractOperations(),
    );

    $this->assertSame(
        count($keys),
        count(array_unique($keys)),
        'The contract lists the same method and path more than once.',
    );

    foreach (apiContractRoutes() as $entry) {
        $route = $entry['route'];
        $key = apiContractKey($entry['method'], $route->uri());

        $this->assertArrayHasKey(
            $key,
            $operations,
            "routes/api.php declares {$entry['method']} {$route->uri()} but the contract does not.",
        );

        preg_match_all('/\{(\w+)\}/', '/'.ltrim($route->uri(), '/'), $routeParameters);
        preg_match_all('/\{(\w+)\}/', $operations[$key]['path'], $contractParameters);

        $this->assertSame(
            $routeParameters[1],
            $contractParameters[1],
            "The parameter names for {$key} differ between the route and the contract.",
        );
    }

    $routeKeys = array_map(
        fn (array $entry): string => apiContractKey($entry['method'], $entry['route']->uri()),
        apiContractRoutes(),
    );

    $this->assertEqualsCanonicalizing($routeKeys, array_keys($operations));
});

it('names the controller and the form request the route actually runs', function () {
    $operations = apiContractOperationMap();

    foreach (apiContractRoutes() as $entry) {
        $route = $entry['route'];
        $key = apiContractKey($entry['method'], $route->uri());
        $operation = $operations[$key]['operation'];

        expect($operation['x-controller'] ?? null)->toBe(
            $route->getActionName(),
            "{$key} must name the controller it dispatches to.",
        );

        expect($operation['x-request'] ?? null)->toBe(
            apiContractFormRequest($route),
            "{$key} must name the form request in the controller signature, or omit it.",
        );
    }
});

it('states the authentication the route middleware enforces', function () {
    $operations = apiContractOperationMap();

    foreach (apiContractRoutes() as $entry) {
        $route = $entry['route'];
        $key = apiContractKey($entry['method'], $route->uri());
        $operation = $operations[$key]['operation'];
        $middleware = app('router')->gatherRouteMiddleware($route);
        $authenticated = apiContractHasMiddleware($middleware, 'Illuminate\Auth\Middleware\Authenticate:sanctum');

        expect($operation['security'])->toBe(
            $authenticated ? [['sanctum' => []]] : [],
            "{$key} must declare Sanctum exactly when the route requires it.",
        );

        expect(array_key_exists('401', $operation['responses']))->toBe(
            $authenticated,
            "{$key} must declare 401 exactly when the route requires authentication.",
        );
    }
});

it('states the role, ability, verification, throttle, size and not-found refusals', function () {
    $operations = apiContractOperationMap();

    foreach (apiContractRoutes() as $entry) {
        $route = $entry['route'];
        $key = apiContractKey($entry['method'], $route->uri());
        $operation = $operations[$key]['operation'];
        $responses = $operation['responses'];
        $middleware = app('router')->gatherRouteMiddleware($route);

        $roles = apiContractMiddlewareArgument($middleware, 'App\Http\Middleware\EnsureUserHasRole:');

        expect($operation['x-required-roles'] ?? null)->toBe(
            $roles === null ? null : explode(',', $roles),
            "{$key} must list the roles its middleware enforces.",
        );

        $ability = apiContractMiddlewareArgument($middleware, 'Illuminate\Auth\Middleware\Authorize:');

        expect($operation['x-required-ability'] ?? null)->toBe(
            $ability,
            "{$key} must name the ability its middleware enforces.",
        );

        if ($roles !== null || $ability !== null) {
            $this->assertArrayHasKey('403', $responses, "{$key} gates on a role or ability but omits 403.");
        }

        if (apiContractHasMiddleware($middleware, 'Illuminate\Auth\Middleware\EnsureEmailIsVerified')
            || apiContractHasMiddleware($middleware, 'Illuminate\Routing\Middleware\ValidateSignature')) {
            $this->assertArrayHasKey('403', $responses, "{$key} refuses unverified or unsigned callers but omits 403.");
        }

        if (apiContractMiddlewareArgument($middleware, 'Illuminate\Routing\Middleware\ThrottleRequests:') !== null) {
            $this->assertArrayHasKey('429', $responses, "{$key} is rate limited but omits 429.");
        }

        if (isset($operation['requestBody'])) {
            $this->assertArrayHasKey('413', $responses, "{$key} takes a body but omits the 413 ceiling (API-07).");
        }

        if (isset($operation['x-request'])) {
            $this->assertArrayHasKey('422', $responses, "{$key} validates a body but omits 422.");
        }

        preg_match_all('/\{(\w+)\}/', '/'.ltrim($route->uri(), '/'), $parameters);

        if ($parameters[1] !== []) {
            $this->assertArrayHasKey('404', $responses, "{$key} resolves a path model but omits 404.");
        }
    }
});

it('keeps every operation self-describing and every reference resolvable', function () {
    $contract = apiContract();
    $tags = array_column($contract['tags'], 'name');
    $operationIds = [];

    foreach (apiContractOperations() as $entry) {
        $operation = $entry['operation'];
        $label = "{$entry['method']} {$entry['path']}";
        $operationId = $operation['operationId'] ?? null;

        expect($operationId)->toBeString()->not->toBeEmpty("{$label} needs an operationId.");
        $this->assertArrayNotHasKey($operationId, $operationIds, "The operationId {$operationId} is used twice.");
        $operationIds[$operationId] = true;

        expect($operation['summary'] ?? null)->toBeString()->not->toBeEmpty("{$label} needs a summary.");
        expect($operation['tags'] ?? [])->toHaveCount(1, "{$label} needs exactly one tag.");
        expect($tags)->toContain($operation['tags'][0]);

        foreach ($operation['responses'] as $status => $response) {
            expect((string) $status)->toMatch('/^[1-5]\d\d$/');

            expect(apiContractResolve($contract, $response)['description'] ?? null)
                ->toBeString()->not->toBeEmpty("{$label} {$status} needs a description.");
        }
    }

    $refs = apiContractRefs($contract);

    expect($refs)->not->toBeEmpty();

    foreach ($refs as $ref) {
        expect($ref)->toStartWith('#/components/');
        expect(apiContractResolve($contract, ['$ref' => $ref]))
            ->not->toBeEmpty("The reference {$ref} does not resolve.");
    }
});

it('states the error envelope, the replay answer and the size ladder', function () {
    $contract = apiContract();
    $schemas = $contract['components']['schemas'];
    $responses = $contract['components']['responses'];

    expect($schemas['Error']['required'])->toContain('message')
        ->and($schemas['ValidationError']['required'])->toContain('errors')
        ->and($responses['ValidationFailed']['description'])->toContain('photo_size')
        ->and($responses['PayloadTooLarge']['description'])->toContain('6 MiB');

    expect($schemas['PaginationLinks']['required'])->toBe(['first', 'last', 'prev', 'next'])
        ->and($schemas['PaginationMeta']['required'])
        ->toBe(['current_page', 'from', 'last_page', 'links', 'path', 'per_page', 'to', 'total']);

    foreach (['/api/flood-reports', '/api/flood-impact-reports'] as $path) {
        $filing = $contract['paths'][$path]['post']['responses'];

        $this->assertArrayHasKey('201', $filing, "{$path} must declare the stored answer.");
        $this->assertArrayHasKey('200', $filing, "{$path} must declare the replay answer (D-110).");

        expect($filing['200']['description'])->toContain('client_uuid');
    }
});
