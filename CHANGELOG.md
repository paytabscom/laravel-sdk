# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [3.0.0] - 2026-08-05

See [UPGRADE.md](UPGRADE.md) for migration steps.

### Breaking Changes
- `PaytabsResultProcessor::handleIpn()` and `handleCallback()` now take an `IpnOutcome` by reference as their first argument. Calls such as `handleIpn(true)` no longer work.
- `handleCallback()` no longer lets `InvalidSignatureException` escape. All rejections are now reported as `CallbackProcessingException` (or a subclass), with the original exception available via `getPrevious()`.
- `IpnIdempotencyGuardInterface` now requires `release(Ipn $payload): void`. Custom guards must implement it.
- `InvalidPayloadException` and `IdempotencyException` now extend `CallbackProcessingException` instead of `RuntimeException`.
- `IdempotencyException::duplicateDelivery()` was removed. Use `IdempotencyException::forIpn()`.
- The idempotency cache key format changed. In-flight locks from a previous version are not recognized after upgrade.

### Added
- Updated service binding to **scoped** lifecycle for safer request/job isolation.
- `IpnOutcome` enum to standardize callback/IPN response outcomes.
- `CallbackProcessingException` as the single base exception for callbacks that were received but not processed.
- Added dedicated `InvalidPayloadException` for callback payload type and mapping failures.
- `IpnIdempotencyGuardInterface::release()` so a failed handler frees the lock and PayTabs can retry.
- `ipn_time_guard_future_skew_seconds` configuration options.
- Test suite based on Orchestra Testbench, plus a CI workflow covering PHP 8.1-8.4 and Laravel 10-12.

### Fixed
- Invalid signature responses logged the server key prefix, letting anyone with the endpoint URL force key material into application logs.
- Callbacks are now read from the Laravel request rather than `php://input` and `getallheaders()`, which fixes IPN handling under Laravel Octane.
- Payload access is guarded throughout, so an IPN missing `payment_result`, `ipn_trace` or `profile_id` no longer raises a PHP `Error`.

### Changed
- `handleIpn()` now applies the idempotency guard by default, matching its documentation.
- Improved callback processing by reusing initialized callback result objects.
- Updated `ack_on_handler_exception` default to `false`.
- Set default IPN handler and profile resolver configuration values to `null`.
- Replaced helper-based time checks with `Carbon::now()` for static-analysis compatibility.
- Updated callback processor APIs to support outcome-based callback handling.
- Static analysis now loads the Larastan extension, and `illuminate/contracts`, `illuminate/http`, `illuminate/routing`, `illuminate/cache` and `illuminate/log` are declared as runtime dependencies.
- Widened support to Laravel 10 and PHP 8.1.
- Refactored `IpnOutcome::toResponse()` to reduce branching return complexity.


## [2.0.2] - 2026-07-30

### Changed
- Refactored `Paytabs` class from static methods to instance methods for proper Laravel Facade support
- Removed static instance management in favor of Laravel's service container
- Updated `PaytabsResultProcessor` to use container for Paytabs instance resolution
- Reordered callback guards to run time guard before idempotency guard.
- Updated docs and legal wording for proprietary licensing consistency.

## [2.0.1] - 2026-07-29

### Changed
- Fix bug in Laravel 11 support

## [2.0.0] - 2026-07-29 - Initial Release

### Added
- Initial Laravel SDK for PayTabs payment gateway (Replacing the Laravel SDK v1)
- IPN (Instant Payment Notification) handling with idempotency
- Profile resolver support for multi-tenant applications
- Cache-based idempotency guard
- Signature validation for callbacks
- Facade support for easy access
- Configuration file with sensible defaults
- Configuration validation using SDK's `InvalidConfigurationException`
- Time guard feature for IPN replay attack prevention
- PHPDoc blocks across all classes and methods
- Static analysis support (Larastan) with `phpstan analyse` script
- Code formatting support (Laravel Pint) with `composer format` script
- Development dependencies: larastan/larastan, laravel/pint, phpunit/phpunit
- Complete documentation: ReadMe.md, INSTALLATION.md, USAGE.md, IPN_HANDLING.md
- Laravel support: `^11.0|^12.0|^13.0`

### Security
- Added IPN time guard to prevent replay attacks by rejecting stale IPNs
- Configuration validation now throws SDK exceptions for missing required config values

### Documentation
- Added comprehensive ReadMe.md with quick start guide
- Added detailed INSTALLATION.md with troubleshooting section
- Added USAGE.md with extensive code examples
- Added IPN_HANDLING.md with security best practices
