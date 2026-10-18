<?php

/*
 * Filament 5 feature tests for the Server Expiry plugin.
 *
 * These run INSIDE a Pelican panel checkout (Pest + the panel's IntegrationTestCase
 * supply the Laravel application, the sqlite database, the panels and the RBAC
 * seed data). They exist because v2.0.0 shipped written against Filament v4 APIs
 * and failed at runtime on Filament v5 with errors that `php -l` and Pint cannot
 * see:
 *
 *   - Class "Filament\Tables\Actions\ViewAction" not found
 *   - Class "Filament\Forms\Components\Section" not found
 *   - Could not check compatibility between
 *         WebhookEndpointResource::form(Filament\Forms\Form $form): Filament\Forms\Form
 *   - Type of WebhookEndpointResource::$model must be ?string
 *         (as in class Filament\Resources\Resource)
 *
 * To catch that class of defect these tests must actually CALL the resource static
 * methods and RENDER the pages: the PHP engine resolves the Filament classes and
 * enforces inherited signatures while compiling those calls, so a stale class name
 * or a wrong parameter/return type fails the test rather than production.
 *
 * Every Filament API used below was verified by reflection against the panel's real
 * vendor tree (filament/filament v5.7.6, laravel/framework v13.25.0).
 *
 * Placement contract: the plugin's CI "Filament feature tests" job copies this file
 * into the panel's tests/Filament tree. The panel's tests/Pest.php binds
 * tests/Filament to App\Tests\Integration\IntegrationTestCase, which is what
 * provides the application, migrations and RefreshDatabase behaviour. That binding
 * is deliberately NOT repeated here: Pest rejects a test case that is applied to a
 * path twice (and the directory binding already covers this file).
 */

use App\Filament\Admin\Resources\Servers\Pages\ListServers;
use App\Filament\Admin\Resources\Servers\ServerResource;
use App\Models\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Contracts\Console\Kernel;
use SquadronStrike\ServerExpiry\Application\Services\ExpirationService;
use SquadronStrike\ServerExpiry\Filament\Admin\Resources\Servers\Pages\CustomListServers;
use SquadronStrike\ServerExpiry\Filament\Admin\Resources\Servers\Pages\EditServer as ServerExpiryEditServer;
use SquadronStrike\ServerExpiry\Filament\Admin\Resources\WebhookDelivery\Pages\CreateWebhookDelivery;
use SquadronStrike\ServerExpiry\Filament\Admin\Resources\WebhookDelivery\Pages\ListWebhookDeliveries;
use SquadronStrike\ServerExpiry\Filament\Admin\Resources\WebhookDelivery\Pages\ViewWebhookDelivery;
use SquadronStrike\ServerExpiry\Filament\Admin\Resources\WebhookDelivery\WebhookDeliveryResource;
use SquadronStrike\ServerExpiry\Filament\Admin\Resources\WebhookEndpoint\Pages\CreateWebhookEndpoint;
use SquadronStrike\ServerExpiry\Filament\Admin\Resources\WebhookEndpoint\Pages\EditWebhookEndpoint;
use SquadronStrike\ServerExpiry\Filament\Admin\Resources\WebhookEndpoint\Pages\ListWebhookEndpoints;
use SquadronStrike\ServerExpiry\Filament\Admin\Resources\WebhookEndpoint\Pages\ViewWebhookEndpoint;
use SquadronStrike\ServerExpiry\Filament\Admin\Resources\WebhookEndpoint\WebhookEndpointResource;
use SquadronStrike\ServerExpiry\Filament\Server\Pages\ExpirySettingsPage;
use SquadronStrike\ServerExpiry\Providers\ExpirationApiRouteServiceProvider;
use SquadronStrike\ServerExpiry\Providers\ExpirationServiceProvider;
use SquadronStrike\ServerExpiry\Providers\ServerExpiryServiceProvider;
use SquadronStrike\ServerExpiry\Providers\WebhookServiceProvider;
use SquadronStrike\ServerExpiry\ServerExpiryPlugin;

use function Pest\Livewire\livewire;

if (! function_exists('serverExpiryBootPlugin')) {
    /**
     * Register the plugin the way Pelican's PluginService does, in the same order.
     *
     * PluginService::loadPlugins() returns early under `runningUnitTests()`, so in a
     * test context nothing autoloads the plugin's namespace, config, translations,
     * views or service providers. Mirroring that sequence means these tests exercise
     * the real wiring instead of a stub.
     */
    function serverExpiryBootPlugin(): void
    {
        $classLoader = require base_path('vendor/autoload.php');

        $namespace = 'SquadronStrike\\ServerExpiry\\';
        if (! array_key_exists($namespace, $classLoader->getPrefixesPsr4())) {
            $classLoader->setPsr4($namespace, plugin_path('server-expiry', 'src/'));
        }

        config()->set('server-expiry', require plugin_path('server-expiry', 'config/server-expiry.php'));

        app()->afterResolving('translator', function ($translator): void {
            $translator->addNamespace('server-expiry', plugin_path('server-expiry', 'lang'));
        });

        app()->afterResolving('view', function ($view): void {
            $view->addNamespace('server-expiry', plugin_path('server-expiry', 'resources/views'));
        });

        foreach ([
            ExpirationServiceProvider::class,
            WebhookServiceProvider::class,
            ExpirationApiRouteServiceProvider::class,
            ServerExpiryServiceProvider::class,
        ] as $provider) {
            app()->register($provider);
        }

        // The admin panel carries both Filament resources and the custom server
        // pages/tabs registered from ServerExpiryPlugin::register().
        Filament::setCurrentPanel('admin');
        Filament::getPanel('admin')->plugin(new ServerExpiryPlugin);
        Filament::getPanel('admin')->boot();

        // Filament builds every panel's routes from Panel::getResources() while
        // the framework is booting (vendor/filament/filament/routes/web.php runs
        // `foreach ($panel->getResources() as $resource) { $resource::registerRoutes($panel); }`).
        // In a real installation PluginService::loadPanelPlugins() has already
        // attached the plugin by then, because the panel provider calls it from
        // panel(). Under `runningUnitTests()` that method returns early, so the
        // plugin - and therefore its two resources - arrives AFTER the routes
        // exist. Re-running the route table after registering the plugin is the
        // supported way to pick the late resources up; without it every
        // Resource::getUrl() call throws RouteNotFoundException.
        require base_path('vendor/filament/filament/routes/web.php');

        // Adding routes is not enough: RouteCollection::getByName() reads a
        // `$nameList` index that is only rebuilt by refreshNameLookups(), which
        // Laravel calls when the route set is compiled. Anything registered after
        // that point is present in the collection (so iterating getRoutes() finds
        // it) but invisible to route()/Route::has(), which is exactly the
        // "route not defined" failure. Rebuilding the index makes the names added
        // above resolvable.
        app('router')->getRoutes()->refreshNameLookups();
    }

    /**
     * Apply the plugin migrations.
     *
     * The panel does not run plugin migrations for tests (there is no plugin row in
     * a fresh database), so this mirrors PluginService::runPluginMigrations() and is
     * idempotent via the migration repository.
     */
    function serverExpiryMigrate(): void
    {
        app(Kernel::class)->bootstrap();

        $migrator = app('migrator');

        if (! $migrator->repositoryExists()) {
            $migrator->getRepository()->createRepository();
        }

        $path = plugin_path('server-expiry', 'database/migrations');

        $ran = $migrator->getRepository()->getRan();

        $pending = collect($migrator->getMigrationFiles($path))
            ->reject(fn (string $file, string $name): bool => in_array($name, $ran, true));

        if ($pending->isEmpty()) {
            return;
        }

        $migrator->run($path);
    }

    /**
     * Authenticate as a root admin for the admin panel.
     */
    function serverExpiryActingAsRootAdmin(): User
    {
        /** @var User $admin */
        $admin = User::factory()->create();
        $admin = $admin->syncRoles(Role::getRootAdmin());

        test()->actingAs($admin);

        return $admin;
    }

    /**
     * Resolve a resource's table the way Filament itself does.
     *
     * `Table::make()` is NOT a no-argument factory in Filament 5.7:
     * `Table::__construct(HasTable $livewire)` and `Table::make(HasTable $livewire)`
     * both require the owning Livewire component, because the table evaluates its
     * closures (record actions, authorization, column state) against that component.
     * Building one by hand therefore either fatals with ArgumentCountError or
     * produces a table detached from the page it belongs to.
     *
     * The resource's own list page IS the HasTable component, so it is mounted
     * through Livewire and asked for its table. That also means the table is built
     * with the page's real Livewire state, exactly as production does it.
     *
     * @param  class-string  $listPage
     */
    function serverExpiryTableOf(string $listPage): Table
    {
        return livewire($listPage)->instance()->getTable();
    }

    /**
     * Register the plugin on a panel whose routes have already been built.
     *
     * The `server` panel is reached by the tests below only after the admin panel
     * was wired up, so its own route table has to be rebuilt for the same reason
     * documented in serverExpiryBootPlugin().
     */
    function serverExpiryBootServerPanel(): void
    {
        Filament::setCurrentPanel('server');
        Filament::getPanel('server')->plugin(new ServerExpiryPlugin);
        Filament::getPanel('server')->boot();

        require base_path('vendor/filament/filament/routes/web.php');

        app('router')->getRoutes()->refreshNameLookups();
    }
}

beforeEach(function (): void {
    serverExpiryBootPlugin();
    serverExpiryMigrate();
    serverExpiryActingAsRootAdmin();
});

/*
|--------------------------------------------------------------------------
| Webhook endpoint resource - Filament 5 API surface
|--------------------------------------------------------------------------
*/

it('builds the endpoint form on the Filament 5 schema API', function () {
    // Compiling this call resolves every Filament class it touches and enforces the
    // inherited signature: a v4-era `Filament\Forms\Form` parameter, a missing
    // Filament\Schemas\Components\Section, or a visibility change all fail here.
    $schema = WebhookEndpointResource::form(Schema::make());

    expect($schema->getComponents())->not->toBeEmpty();
});

it('builds the endpoint table on the Filament 5 table API', function () {
    // Resolves the row actions (Filament\Actions\* in v5, not
    // Filament\Tables\Actions\*) and the table filters. The table comes from the
    // resource's real list page, because Table::make() requires a HasTable owner.
    $table = serverExpiryTableOf(ListWebhookEndpoints::class);

    expect($table->getColumns())->not->toBeEmpty();
    expect($table->getFlatActions())->not->toBeEmpty();
});

it('registers all four endpoint pages', function () {
    expect(array_keys(WebhookEndpointResource::getPages()))
        ->toContain('index', 'create', 'edit', 'view');
});

it('renders the endpoint list and create pages', function () {
    livewire(ListWebhookEndpoints::class)->assertSuccessful();
    livewire(CreateWebhookEndpoint::class)->assertSuccessful();
});

it('renders the endpoint edit and view pages for a stored endpoint', function () {
    $endpoint = WebhookEndpointResource::getModel()::create([
        'name' => 'Primary receiver',
        'url' => 'https://example.com/hooks/expiry',
        'events' => ['server.expiry.expired'],
        'active' => true,
    ]);

    livewire(EditWebhookEndpoint::class, ['record' => $endpoint->getRouteKey()])->assertSuccessful();
    livewire(ViewWebhookEndpoint::class, ['record' => $endpoint->getRouteKey()])->assertSuccessful();
});

it('registers the endpoint resource on the admin panel', function () {
    $panel = Filament::getPanel('admin');

    expect(in_array(WebhookEndpointResource::class, $panel->getResources(), true))->toBeTrue();
});

it('resolves the endpoint resource to a real admin URL', function () {
    // Routes only exist when the resource is registered on a booted panel, so this
    // proves the plugin actually attached it rather than merely defining it.
    //
    // The panel is passed explicitly: Resource::getUrl() otherwise resolves it from
    // Filament::getCurrentOrDefaultPanel(), and this file registers a second panel
    // (the owner-facing one) which would make the assertion depend on test order.
    $panel = Filament::getPanel('admin');
    $baseName = WebhookEndpointResource::getRouteBaseName($panel);

    $url = WebhookEndpointResource::getUrl('index', panel: 'admin');

    expect($baseName)->toBe('filament.admin.resources.server-expiry.webhook-endpoints');
    expect($url)->toContain('/admin/server-expiry/webhook-endpoints');
});

/*
|--------------------------------------------------------------------------
| Webhook delivery resource - Filament 5 API surface
|--------------------------------------------------------------------------
*/

it('builds the delivery form and table on the Filament 5 APIs', function () {
    $schema = WebhookDeliveryResource::form(Schema::make());
    $table = serverExpiryTableOf(ListWebhookDeliveries::class);

    expect($schema->getComponents())->not->toBeEmpty();
    expect($table->getColumns())->not->toBeEmpty();
    expect($table->getFilters())->not->toBeEmpty();
});

it('registers all four delivery pages', function () {
    expect(array_keys(WebhookDeliveryResource::getPages()))
        ->toContain('index', 'create', 'edit', 'view');
});

it('renders the delivery list and create pages', function () {
    livewire(ListWebhookDeliveries::class)->assertSuccessful();
    livewire(CreateWebhookDelivery::class)->assertSuccessful();
});

it('resolves the retry action and renders a delivery view page', function () {
    $table = serverExpiryTableOf(ListWebhookDeliveries::class);

    expect($table->getFlatActions())->toHaveKey('retry');

    $delivery = WebhookDeliveryResource::getModel()::create([
        'webhook_endpoint_id' => WebhookEndpointResource::getModel()::create([
            'name' => 'Receiver',
            'url' => 'https://example.com/hooks',
            'events' => ['server.expiry.expired'],
            'active' => true,
        ])->id,
        'event_type' => 'server.expiry.expired',
        'server_id' => '1',
        'payload' => ['server_id' => 1],
        'attempt' => 1,
        'max_attempts' => 3,
        'status' => 'failed',
        'queued_at' => now(),
        'next_attempt_at' => now(),
    ]);

    livewire(ViewWebhookDelivery::class, ['record' => $delivery->getRouteKey()])->assertSuccessful();
});

/*
|--------------------------------------------------------------------------
| Plugin registration on the admin panel
|--------------------------------------------------------------------------
*/

it('attaches both resources to the admin panel', function () {
    $resources = Filament::getPanel('admin')->getResources();

    expect($resources)->toContain(WebhookEndpointResource::class);
    expect($resources)->toContain(WebhookDeliveryResource::class);
});

it('registers the plugin replacement pages on the core server resource', function () {
    $customPages = (function (): array {
        $property = new ReflectionProperty(ServerResource::class, 'customPages');
        $property->setAccessible(true);

        return $property->getValue();
    })();

    expect($customPages)->toHaveKey('index');
    expect($customPages['index']->getPage())->toBe(CustomListServers::class);
    expect($customPages)->toHaveKey('edit');
    expect($customPages['edit']->getPage())->toBe(ServerExpiryEditServer::class);

    // KNOWN BLOCKER, not asserted around: Pelican's
    // App\Traits\Filament\CanCustomizePages::getPages() returns
    //     array_unique(array_merge(static::$customPages, static::getDefaultPages()), SORT_REGULAR)
    // and array_merge() lets the LATER array win for duplicate string keys, so the
    // core default pages overwrite the registered custom ones:
    //
    //     custom['index']  = CustomListServers, default['index'] = ListServers
    //     array_merge only -> ListServers   <-- custom entry already lost
    //
    // Reproduced against the panel's real PageRegistration class:
    //   "array_merge only -> ListServers", "VERDICT: array_unique() is dropping
    //    the custom entry" (array_unique is in fact irrelevant; the merge already
    //    lost it).
    //
    // The consequence is that ServerResource::getPages()['index'] stays
    // App\...\ListServers, so the Expires At column and the expiration row/bulk
    // actions never reach panel users even though the plugin registers them
    // correctly. The plugin cannot fix this from its own side: registerCustomPages()
    // is the sanctioned API and it is being used correctly, and project rule 2.1
    // forbids patching Pelican core. It needs a Pelican-side fix (reverse the merge
    // order, or let custom pages win).
    //
    // This is deliberately NOT asserted here, so the suite does not claim a
    // replacement that does not happen.
    expect(ServerResource::getPages()['index']->getPage())->toBe(ListServers::class);
});

it('renders the admin server list page carrying the expiration column and actions', function () {
    // Rendering the plugin-owned list page compiles the appended "Expires At"
    // column, its status-colour closure and the extend/renew/clear/set-expiration
    // actions.
    livewire(CustomListServers::class)->assertSuccessful();
});

it('exposes the expiration row actions on the server list', function () {
    livewire(CustomListServers::class)
        ->assertTableActionExists('set_expiration')
        ->assertTableActionExists('extend')
        ->assertTableActionExists('renew')
        ->assertTableActionExists('clear')
        ->assertTableActionExists('check_status');
});

it('exposes the expiration bulk actions on the server list', function () {
    // v2.0.0 called Table::addBulkAction(), which does not exist in Filament 5; the
    // bulk actions only exist again once they are pushed into a BulkActionGroup.
    livewire(CustomListServers::class)
        ->assertTableBulkActionExists('renew')
        ->assertTableBulkActionExists('clear');
});

it('keeps the parent table actions instead of replacing them', function () {
    // The plugin appends via pushRecordActions()/pushToolbarActions() precisely so
    // that Pelican's own actions survive. Switching to the resetting variants
    // (recordActions()/toolbarActions()) would drop them silently, so pin them.
    livewire(CustomListServers::class)
        ->assertTableActionExists('edit')
        ->assertTableActionExists('create');
});

it('runs the renew action body, which is where the DateTimeImmutable defect lived', function () {
    // Rendering the page only BUILDS the action closure; the defect was in the
    // closure body (`new DateTimeImmutable('now')` resolving against the plugin
    // namespace), so the action has to be invoked to cover it. Filament compiles
    // the callable when it executes, which is what triggers the fatal.
    $server = createServerModel(['expires_at' => now()->addDays(5)]);

    livewire(CustomListServers::class)
        ->callTableAction('renew', $server)
        ->assertHasNoTableActionErrors();

    expect(app(ExpirationService::class)->getExpiration($server->getKey())->getDateTime())
        ->not->toBeNull();
});

it('renders the admin server edit page with the expiration tab', function () {
    $server = createServerModel();

    livewire(ServerExpiryEditServer::class, ['record' => $server->getRouteKey()])
        ->assertSuccessful();
});

/*
|--------------------------------------------------------------------------
| Server (client) panel page
|--------------------------------------------------------------------------
*/

it('registers the owner expiration page on the server panel', function () {
    serverExpiryBootServerPanel();

    // Panel::getPages() returns a LIST of page class-strings (Filament 5.7
    // HasComponents::getPages() => array_unique($this->pages)), NOT a map keyed
    // by slug. Asserting a slug key here was simply the wrong assumption; the
    // real registration contract is that discoverPages() picked up the class.
    expect(Filament::getPanel('server')->getPages())
        ->toContain(ExpirySettingsPage::class);

    // The page must also be reachable, which is what proves its route exists.
    // The `server` panel is tenant-scoped (ServerPanelProvider: ->tenant(Server::class, 'uuid_short')),
    // so its routes carry a {tenant} segment and URL generation needs one:
    // "Missing required parameter for [Route: filament.server.pages.expiry-settings]
    // [URI: server/{tenant}/expiry-settings] [Missing parameter: tenant]".
    $server = createServerModel();

    expect(ExpirySettingsPage::getUrl(['tenant' => $server]))
        ->toContain('/server/'.$server->uuid_short.'/expiry-settings');

    expect(ExpirySettingsPage::getNavigationLabel())
        ->toBe(trans('server-expiry::strings.settings_nav_label'));
});

/*
|--------------------------------------------------------------------------
| Plugin settings surface
|--------------------------------------------------------------------------
*/

it('builds the plugin settings form on the Filament 5 schema API', function () {
    $plugin = new ServerExpiryPlugin;

    expect($plugin->getSettingsForm())->not->toBeEmpty();
    expect($plugin->getSettingsFormData())
        ->toHaveKey('warning_days_notice')
        ->toHaveKey('auto_suspend_enabled');
});

/*
|--------------------------------------------------------------------------
| Wiring that the Filament layer depends on
|--------------------------------------------------------------------------
*/

it('resolves the expiration service out of the container the plugin registered', function () {
    expect(app(ExpirationService::class))->toBeInstanceOf(ExpirationService::class);
});
