## Despark CMS

Despark CMS is an administrative interface builder for Laravel 5.1

## Installation

 1. Require this package in your composer.json and run `composer update`:

  ```
  "require": {
    "php": ">=5.5.9",
    "laravel/framework": "5.2.*",
    ...
    "despark/cms": "*"
  },
  ```

  Or `composer require despark/cms`

 2. After composer update, insert service provider `Despark\Providers\AdminServiceProvider::class,`
 before `Application Service Providers...` to the `config/app.php`

  **Example**
  ```php
      ...
      /*
       * Despark CMS Service Provider
       */
        Despark\Providers\AdminServiceProvider::class,

      /*
       * Application Service Providers...
       */
      App\Providers\AppServiceProvider::class,
      ...
  ```

 3. Run this command in the terminal (it'll set all necessary resources to use the CMS):

    ```
        php artisan admin:install
    ```

 4. All done! Now go to the `<your_site_url>/admin` and use default credentials `admin@despark.com` / `Despark1234`

## Additional commands

- Use the command `php artisan admin:resource` to create all necessary files for manipulating resources. You should specify the resource name.

    **Example**
    ```
        php artisan admin:resource "Blog post"
    ```

- The command `php artisan admin:update` will update composer dependencies, it'll clear the autoload and it'll run any new migrations.

- You can run `php artisan admin:prod` on your production server, after deploy. It will install all dependencies according to your composer.lock file, run new migrations and optimize the autoload.

## Copyright and License

Despark CMS was written by Despark for the Laravel framework and is released under the MIT License. See the LICENSE file for details.
