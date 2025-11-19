# Changelog

All notable changes to this project will be documented in this file.

## [1.0.0] - 2024-11-19

### Added
- Initial release of MiPaymentChoice Cashier
- Complete API client with JWT bearer token authentication
- Billable trait for User models
- Payment method management (cards and checks)
- Subscription management with trial periods
- One-time charges and refunds
- QuickPayments integration for fast, tokenized payments
- Comprehensive tokenization API support
  - Card tokenization (create, read, update, delete)
  - Check tokenization (create, read, update, delete)
  - Token management with pagination and filtering
  - Transaction-to-token conversion
- Database migrations for subscriptions and payment methods
- Service provider with automatic registration
- Full exception handling
- Extensive documentation

### Features
- Laravel 10.x and 11.x support
- PHP 8.1+ compatibility
- PSR-4 autoloading
- Comprehensive error handling
- Token caching for API authentication
- Support for both card and ACH/check payments
