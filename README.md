# Larvel SRP
SRP for Laravel

[![Latest Stable Version](https://poser.pugx.org/appelit/laravel-srp/v/stable)](https://packagist.org/packages/appelit/laravel-srp)
[![Latest Unstable Version](https://poser.pugx.org/appelit/laravel-srp/v/unstable)](https://packagist.org/packages/appelit/laravel-srp)
[![Total Downloads](https://poser.pugx.org/appelit/laravel-srp/downloads)](https://packagist.org/packages/appelit/laravel-srp)
[![Monthly Downloads](https://poser.pugx.org/appelit/laravel-srp/d/monthly)](https://packagist.org/packages/appelit/laravel-srp)
[![Daily Downloads](https://poser.pugx.org/appelit/laravel-srp/d/daily)](https://packagist.org/packages/appelit/laravel-srp)
[![License](https://poser.pugx.org/appelit/laravel-srp/license)](https://packagist.org/packages/appelit/laravel-srp)
[![composer.lock](https://poser.pugx.org/appelit/laravel-srp/composerlock)](https://packagist.org/packages/appelit/laravel-srp)

## About
Laravel SRP provides an easy layer around the server side of the SRP (Secure Remote Password) protocol for use in your
authentication flow.

## Install
This package required PHP 7.2 and Laravel 5.6 or higher. To install the package use the command below.

```bash
composer require appelit/laravel-srp
```

# Using
The package provides a Facade aliased as `\SRP`, this facade can be used to easily access the `APPelit\SRP\SrpService`.
Inside SrpService are 2 methods, `challenge` and `authorize`, which represent the challenge and authorization part of
the flow. The challenge and authorization both return a class containing all the information required by the client and
implements the `Illuminate\Contracts\Support\Jsonable` interface, so it can be returned directly from controller
methods. The `APPelit\SRP\AuthenticateResponse` also contains the generated session key (which will NOT be encoded into
the response (and never should be)), if required this key can be stored in some sort of (secure) cache or storage to
be used later (uses can include message signing and (symmetric) encryption).

The package also provides a `APPelit\SRP\Http\AuthenticatesUsers` trait (modelled in a similar fashion as the trait of
the same name inside the Laravel framework), this trait exposes a `challenge` and `response` method which are to be
used as route endpoints. In order not to force any routing structure (or cause problems by doing so), the routes
themselves are not defined and should be added to `routes/web.php` and/or `routes/api.php`.

The package provides some sane defaults for the required SRP parameters, it is however recommended to use your own
parameters instead. For this reason the package provides a command (`srp:generate`) to generate the required values and
insert them into your .env file. It is recommended to use `openssl dhparam` (Google it if unsure) to generate the N and
g parameters, since generating these takes a (very) long time when done using PHP and is potentially less secure. You
can provide the resulting `dhparam.pem` file using the `-F [pathToFile]` switch and it will be decoded and used instead
of generating it. Please note that you must configure the client to use the same values.

Since the package is build around the "thinbus-srp-php" package, it is recommended to use the "thinbus-srp" npm
package for frontend implementation.

**WARNING** This package is currently alpha and should not (yet) be used in production.

## Testing
**NOTE** Tests are not implemented yet, if you use this package and know how to write tests, feel free to contribute them.

Run the tests with:
```bash
vendor/bin/phpunit
```

### Changelog
Please see [CHANGELOG](CHANGELOG.md) for more information what has changed recently.

## Security
If you discover any security-related issues, please email mark@appelit.com instead of using the issue tracker.

## Credits
- [Mark van Beek](https://github.com/chancezeus)
- [All Contributors](CONTRIBUTORS.md)

## Support us
APPelit is an IT company based in The Netherlands. You'll find an overview of all our open source projects
[on our website](https://appelit.com/opensource).

## License
This project is open-source and licensed under the [MIT license](http://opensource.org/licenses/MIT)
