# Bugsnag for Laraigniter

Bugsnag error tracking for the Laraigniter framework (CodeIgniter 3 + Elegant providers).

## Installation

### 1. Require the package

```bash
composer require lara-igniter/bugsnag
```

### 2. Register the provider

In `config/hooks.php`, add the service provider to the `providers` list:

```php
Laraigniter\Bugsnag\BugsnagServiceProvider::class,
```

Optionally register the facade alias:

```php
'Bugsnag' => Laraigniter\Bugsnag\Facades\Bugsnag::class,
```

### 3. Publish the config (optional)

Configuration is loaded from the package automatically. To override it locally:

```bash
php artisan vendor:publish --tag=bugsnag-config
```

### 4. Environment variables

Add these to your application `.env`:

```env
BUGSNAG_API_KEY=
BUGSNAG_APP_TYPE=web
BUGSNAG_RELEASE_STAGE=production

# Optional — defaults to Elegant\Foundation\Application::VERSION
# BUGSNAG_APP_VERSION=1.0.0

# Optional — comma-separated stages that should notify (default: production,staging)
# BUGSNAG_NOTIFY_RELEASE_STAGES=production,staging,development

# Optional
# BUGSNAG_BATCH_SENDING=true
# BUGSNAG_SEND_CODE=true
# BUGSNAG_CAPTURE_SESSIONS=false
# BUGSNAG_HOSTNAME=
# BUGSNAG_ENDPOINT=
# BUGSNAG_SESSION_ENDPOINT=
# BUGSNAG_BUILD_ENDPOINT=
# BUGSNAG_FILTERS=password,token,api_key
# BUGSNAG_REDACTED_KEYS=
# BUGSNAG_DISCARD_CLASSES=
# BUGSNAG_MAX_BREADCRUMBS=50
# BUGSNAG_CALLBACKS=true
# BUGSNAG_USER=true
# BUGSNAG_PROJECT_ROOT=
# BUGSNAG_STRIP_PATH=
```

## Usage

Unhandled exceptions are reported automatically (Whoops / CI error pages still render).

```php
try {
    // ...
} catch (Throwable $e) {
    bugsnag_report($e, ['order_id' => 123]);
    // or: report($e);
}

bugsnag_log('PaymentError', 'Card declined', ['amount' => 10], 'warning');
bugsnag_breadcrumb('Checkout started', ['cart_id' => 1]);
bugsnag_user($user->id, $user->email, $user->username);

use Laraigniter\Bugsnag\Facades\Bugsnag;

Bugsnag::notifyException($e);
Bugsnag::leaveBreadcrumb('Saved draft');
```

Ion Auth users are attached automatically when logged in.
