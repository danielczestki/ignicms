<?php

namespace Despark\Cms\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Routing\Router;
use Illuminate\Foundation\AliasLoader;
use File;
use Despark\Cms\Admin\Admin;

class AdminServiceProvider extends ServiceProvider
{
    /**
     * The Artisan commands provided by starter kit.
     *
     * @var array
     */
    protected $commands = [
        'Despark\Cms\Console\Commands\AdminInstallCommand',
        'Despark\Cms\Console\Commands\AdminUpdateCommand',
        'Despark\Cms\Console\Commands\AdminUpdateProdCommand',
        'Despark\Cms\Console\Commands\AdminResourceCommand',
    ];

    /**
     * Bootstrap the application services.
     */
    public function boot(Router $router)
    {
        // Routes
        $router->group(['namespace' => 'Despark\Cms\Http\Controllers'], function ($router) {
            require __DIR__.'/../Http/routes.php';
        });

        $this->publishes([
            __DIR__.'/../Http/resourcesRoutes.php' => app_path('Http/resourcesRoutes.php'),
        ]);

        $router->group(['prefix' => 'admin', 'middleware' => 'auth'], function () {
            if (File::exists(app_path('Http/resourcesRoutes.php'))) {
                require app_path('Http/resourcesRoutes.php');
            }
        });

        // Register Assets
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'despark');
        $this->loadTranslationsFrom(__DIR__.'/../../resources/lang', 'despark');

        // Register the application commands
        $this->commands($this->commands);

        // Publish the Resources
        # Migrations
        $this->publishes([
            __DIR__.'/../../database/migrations/' => database_path('/migrations'),
        ], 'migrations');

        # Seeders
        $this->publishes([
            __DIR__.'/../../database/seeds/' => database_path('/seeds'),
        ], 'seeds');

        # Configs
        $this->publishes([
            __DIR__.'/../../config/' => config_path(),
        ], 'config');
        $this->publishes([
            __DIR__.'/../../resources/' => base_path('/resources'),
        ]);
        $this->publishes([
            __DIR__.'/../../gulp/' => base_path('/gulp'),
        ]);
        $this->publishes([
            __DIR__.'/../../public/' => public_path(),
        ]);

        $this->publishes([
            __DIR__.'/../../.env.example' => base_path('.env.example'),
            __DIR__.'/../../package.json' => base_path('package.json'),
            __DIR__.'/../../bower.json' => base_path('bower.json'),
            __DIR__.'/../../.bowerrc' => base_path('.bowerrc'),
            __DIR__.'/../../gulpfile.js' => base_path('gulpfile.js'),
        ]);

        $configPaths = config('admin.bootstrap.paths');
        if ($configPaths) {
            foreach ($configPaths as $key => $path) {
                if (!is_dir($path)) {
                    File::makeDirectory($path, 755, true, true);
                }
            }
        }

        exec('composer dumpautoload');
    }

    /**
     * Register the application services.
     */
    public function register()
    {
        /*
         * Register the service provider for the dependency.
         */
        $this->app->register('Zizaco\Entrust\EntrustServiceProvider');
        $this->app->register('Conner\Tagging\Providers\TaggingServiceProvider');
        $this->app->register('Collective\Html\HtmlServiceProvider');
        $this->app->register('Intervention\Image\ImageServiceProvider');
        $this->app->register('Despark\HtmlTemplateCurator\HtmlTemplateCuratorServiceProvider');
        $this->app->register('Cviebrock\EloquentSluggable\SluggableServiceProvider');
        $this->app->register('Skovmand\Mailchimp\MailchimpServiceProvider');
        $this->app->register('Roumen\Sitemap\SitemapServiceProvider');
        $this->app->register('Rutorika\Sortable\SortableServiceProvider');
        $this->app->register('Jenssegers\Agent\AgentServiceProvider');

        /*
         * Create aliases for the dependency.
         */
        $loader = AliasLoader::getInstance();
        $loader->alias('Entrust', 'Zizaco\Entrust\EntrustFacade');
        $loader->alias('Form', 'Collective\Html\FormFacade');
        $loader->alias('Html', 'Collective\Html\HtmlFacade');
        $loader->alias('Image', 'Intervention\Image\Facades\Image');
        $loader->alias('Agent', 'Jenssegers\Agent\Facades\Agent');
    }
}
