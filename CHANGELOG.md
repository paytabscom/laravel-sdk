# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - 2026-07-30

### Added
- Configuration validation using SDK's `InvalidConfigurationException`
- Time guard feature for IPN replay attack prevention
- Comprehensive PHPDoc blocks across all classes and methods
- Static analysis support (Larastan) with `phpstan analyse` script
- Code formatting support (Laravel Pint) with `pint` script
- Development dependencies: larastan/larastan, laravel/pint, phpunit/phpunit
- Complete documentation: ReadMe.md, INSTALLATION.md, USAGE.md, IPN_HANDLING.md

### Changed
- Refactored `Paytabs` class from static methods to instance methods for proper Laravel Facade support
- Removed static instance management in favor of Laravel's service container
- Updated `PaytabsResultProcessor` to use container for Paytabs instance resolution
- Updated composer.json to require `paytabs/php-sdk ^3.2`
- Updated Laravel support to `^11.0|^12.0|^13.19`

### Security
- Added IPN time guard to prevent replay attacks by rejecting stale IPNs
- Configuration validation now throws SDK exceptions for missing required config values

### Documentation
- Added comprehensive ReadMe.md with quick start guide
- Added detailed INSTALLATION.md with troubleshooting section
- Added USAGE.md with extensive code examples
- Added IPN_HANDLING.md with security best practices

## [1.0.0] - Initial Release

### Added
- Initial Laravel SDK for PayTabs payment gateway
- IPN (Instant Payment Notification) handling with idempotency
- Profile resolver support for multi-tenant applications
- Cache-based idempotency guard
- Signature validation for callbacks
- Facade support for easy access
- Configuration file with sensible defaults
