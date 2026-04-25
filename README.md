# Modern inventory control for Laravel applications. Features include stock tracking, movement history, low stock alerts, and seamless integration with your existing models

[![Latest Version on Packagist](https://img.shields.io/packagist/v/aldeebhasan/inventorix.svg?style=flat-square)](https://packagist.org/packages/aldeebhasan/inventorix)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/aldeebhasan/inventorix/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/aldeebhasan/inventorix/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/aldeebhasan/inventorix/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/aldeebhasan/inventorix/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Codacy Badge](https://app.codacy.com/project/badge/Grade/dfa531de17724ac787b23634fc652051)](https://app.codacy.com/gh/aldeebhasan/Inventorix/dashboard?utm_source=gh&utm_medium=referral&utm_content=&utm_campaign=Badge_grade)
[![Total Downloads](https://img.shields.io/packagist/dt/aldeebhasan/inventorix.svg?style=flat-square)](https://packagist.org/packages/aldeebhasan/inventorix)

This is where your description should go. Limit it to a paragraph or two. Consider adding a small example.

## Installation

You can install the package via composer:

```bash
composer require aldeebhasan/inventorix
```

You can publish and run the migrations with:

```bash
php artisan vendor:publish --tag="inventorix-migrations"
php artisan migrate
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="inventorix-config"
```

This is the contents of the published config file:

```php
return [
];
```

Optionally, you can publish the views using

```bash
php artisan vendor:publish --tag="inventorix-views"
```

## Usage

```php
$inventorix = new Aldeebhasan\Inventorix();
echo $inventorix->echoPhrase('Hello, Aldeebhasan!');
```

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Hasan Deeb](https://github.com/aldeebhasan)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
