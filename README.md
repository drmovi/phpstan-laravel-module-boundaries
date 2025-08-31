# PHPStan Laravel Module Boundaries

A PHPStan extension that enforces module boundaries in Laravel applications to maintain bounded contexts and prevent unwanted cross-module dependencies.

## Features

- Detects and prevents cross-module imports between Laravel modules
- Supports shared modules that can be imported by any module
- Configurable via Laravel project's `composer.json`
- Works with PHPStan 2.0+

## Installation

Install the extension via Composer:

```bash
composer require --dev drmovi/phpstan-laravel-module-boundaries
```

## Configuration

### 1. Laravel Project Configuration

In your Laravel project's `composer.json`, add the following configuration:

```json
{
    "extra": {
        "laravel-module": {
            "path": "app/Modules"
        },
        "phpstan-laravel-module-boundries": {
            "shared": ["shared", "auth"]
        }
    }
}
```

- `laravel-module.path`: The path to your modules directory relative to the project root
- `phpstan-laravel-module-boundries.shared`: Array of module names that are considered "shared" and can be imported by any module

### 2. PHPStan Configuration

The extension is automatically loaded when installed. No additional PHPStan configuration is required.

## Rules

### Module Boundary Rule

This rule enforces the following boundaries:

1. **Same Module**: Files within a module can import from the same module ✅
2. **Shared Modules**: Any module can import from modules listed in the `shared` configuration ✅
3. **Shared to Shared**: Shared modules can import from other shared modules ✅
4. **Cross-Module**: Non-shared modules cannot import from other non-shared modules ❌

## Example

Given the following module structure:

```
app/Modules/
├── User/
│   └── UserService.php
├── Order/
│   └── OrderService.php
├── shared/
│   └── SharedHelper.php
└── auth/
    └── AuthService.php
```

With configuration:
```json
{
    "extra": {
        "laravel-module": {
            "path": "app/Modules"
        },
        "phpstan-laravel-module-boundries": {
            "shared": ["shared", "auth"]
        }
    }
}
```

### Allowed Imports ✅

```php
// In User/UserService.php
use App\Modules\User\UserRepository;     // Same module
use App\Modules\shared\SharedHelper;     // From shared module
use App\Modules\auth\AuthService;        // From shared module
```

```php
// In shared/SharedHelper.php
use App\Modules\auth\AuthService;        // Shared to shared
```

### Forbidden Imports ❌

```php
// In User/UserService.php
use App\Modules\Order\OrderService;      // Cross-module import
```

```php
// In Order/OrderService.php
use App\Modules\User\UserService;        // Cross-module import
```

## Error Messages

When a boundary violation is detected, PHPStan will report an error like:

```
Module "User" cannot import "App\Modules\Order\OrderService" from module "Order". 
Cross-module imports are only allowed from shared modules (shared, auth).
```

## Requirements

- PHP 8.4+
- PHPStan 2.0+
- Laravel project with modular structure

## License

MIT License. See LICENSE file for details.