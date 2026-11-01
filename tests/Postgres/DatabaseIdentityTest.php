<?php

namespace Tests\Postgres;

use App\Http\Middleware\SetDatabaseIdentity;
use App\Models\Barangay;
use App\Models\User;
use App\Support\DatabaseIdentity;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class DatabaseIdentityTest extends PostgresTestCase
{
    use LazilyRefreshDatabase;

    public function test_it_sets_the_identity_for_the_callback_and_clears_it_after(): void
    {
        $user = User::factory()->create();

        $seen = DatabaseIdentity::runAs(
            $user->id,
            fn (): string => (string) DB::scalar("select current_setting('app.user_id')"),
        );

        $this->assertSame((string) $user->id, $seen);
        $this->assertSame(
            '',
            (string) DB::scalar("select coalesce(current_setting('app.user_id', true), '')"),
            'The identity must not outlive the callback.'
        );
    }

    public function test_it_restores_an_identity_it_found_in_place(): void
    {
        $user = User::factory()->create();

        DB::statement("select set_config('app.user_id', '424242', true)");

        try {
            DatabaseIdentity::runAs($user->id, fn () => null);

            $this->assertSame(
                '424242',
                (string) DB::scalar("select current_setting('app.user_id', true)")
            );
        } finally {
            DB::statement("select set_config('app.user_id', '', true)");
        }
    }

    public function test_a_failing_callback_rolls_its_work_back(): void
    {
        $user = User::factory()->create();
        $barangay = Barangay::factory()->create(['name' => 'Original']);

        try {
            DatabaseIdentity::runAs($user->id, function () use ($barangay): void {
                DB::table('barangays')->where('id', $barangay->id)->update(['name' => 'Changed']);

                throw new RuntimeException('Provider failure.');
            });

            $this->fail('The callback exception did not propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Provider failure.', $exception->getMessage());
        }

        $this->assertSame('Original', DB::table('barangays')->where('id', $barangay->id)->value('name'));
        $this->assertSame(
            '',
            (string) DB::scalar("select coalesce(current_setting('app.user_id', true), '')")
        );
    }

    public function test_the_identity_middleware_runs_after_auth_and_before_route_binding(): void
    {
        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($route): bool => $route->uri() === 'api/flood-reports/{floodReport}');

        $this->assertNotNull($route, 'The bound report route must exist.');

        $middleware = array_map(
            fn (string $name): string => explode(':', $name)[0],
            app('router')->gatherRouteMiddleware($route),
        );

        $identity = array_search(SetDatabaseIdentity::class, $middleware, true);
        $auth = array_search(Authenticate::class, $middleware, true);
        $bindings = array_search(SubstituteBindings::class, $middleware, true);

        $this->assertNotFalse($identity, 'The identity middleware must be on the route.');
        $this->assertNotFalse($auth, 'The route must authenticate.');
        $this->assertNotFalse($bindings, 'The route must substitute bindings.');
        $this->assertGreaterThan($auth, $identity);
        $this->assertLessThan($bindings, $identity);
    }
}
