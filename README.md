# Laravel Dev Mesh

[![Tests](https://github.com/aenzenith/laravel-dev-mesh/actions/workflows/tests.yml/badge.svg)](https://github.com/aenzenith/laravel-dev-mesh/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/aenzenith/laravel-dev-mesh.svg)](https://packagist.org/packages/aenzenith/laravel-dev-mesh)
[![License](https://img.shields.io/packagist/l/aenzenith/laravel-dev-mesh.svg)](https://packagist.org/packages/aenzenith/laravel-dev-mesh)

Run Horizon, the scheduler and any other long-running artisan service of one
or more Laravel projects from a single command, with a live terminal
dashboard.

```
 Shop mesh  ·  17:42:10  ·  uptime 01:12:33                                                  q / Ctrl+C to quit

┌──────────────┬───────────────────────────────┬────────────────────────┬────────┬──────────┬──────────┬──────────────────────┬──────────────────────┐
│ Project      │ horizon                       │ schedule               │ ✓ Done │ ✕ Failed │ Jobs/min │ Last job             │ Last error           │
├──────────────┼───────────────────────────────┼────────────────────────┼────────┼──────────┼──────────┼──────────────────────┼──────────────────────┤
│ shop         │ ● 01:12:33 84211 142 MB       │ ● 01:12:33 84212 38 MB │ 142    │ 2        │ 1.4      │ 17:42:08 SyncUsers… ✓│ 17:30:12 SendSmsJob  │
│ payments-api │ ✕ exit 255 · 4 s ↻3 · 17:41   │ ● 00:01:01 84390 36 MB │ 42     │ 7        │ 0.6      │ 17:42:05 DeliverWe… ✕│ 17:42:05 stopped (e… │
└──────────────┴───────────────────────────────┴────────────────────────┴────────┴──────────┴──────────┴──────────────────────┴──────────────────────┘

 Recent activity
 17:42:08  shop          horizon   App\Jobs\SyncUsersJob .............................................. 120.34ms DONE
 17:42:05  payments-api  horizon   App\Jobs\DeliverWebhookJob ............................................. 1.02s FAIL
 17:42:00  shop          schedule  reports:rollup ............................................................ 1s DONE
```

- **One row per project, one column per service:** state, uptime, PID, memory
  (a Horizon master is summed with its supervisors and workers) and restart
  history.
- **Project totals:** finished and failed jobs, jobs per minute over the last
  five minutes, the latest job and the latest error, parsed from the output of
  queue workers and `schedule:work`.
- **Self-healing:** a crashed service is restarted after 1, 2, 4 … seconds.
- **Isolated environments:** every service starts with `env -i`, so the
  variables loaded from the dashboard application's `.env` never leak into a
  sibling project.
- **Plain output without a TTY:** piped or in CI, lines are printed as
  `[project|service] line`.

## Requirements

- PHP 8.2+ with `pcntl` and `posix`
- Laravel 12 or 13
- macOS or Linux (`stty` and `ps` are used)

## Installation

```bash
composer require --dev aenzenith/laravel-dev-mesh
php artisan vendor:publish --tag=dev-mesh-config
```

## Configuration

`config/dev-mesh.php`:

```php
return [
    'command' => env('DEV_MESH_COMMAND', 'dev'),

    'title' => null, // defaults to the application name

    // label => absolute path; empty runs the current application only
    'projects' => [
        'shop' => base_path(),
        'payments-api' => base_path('../payments-api'),
    ],

    // column => command, started in every project
    'services' => [
        'horizon' => ['php', 'artisan', 'horizon'],
        'schedule' => ['php', 'artisan', 'schedule:work'],
    ],

    // variables passed through `env -i`
    'environment' => [
        'keep' => ['HOME', 'PATH', 'USER', 'SHELL', 'TMPDIR', 'TERM', 'LANG'],
        'keep_prefixes' => ['HERD_', 'NVM_'],
    ],

    'max_restart_delay' => 30,
];
```

Keep the `HERD_` prefix when you use Laravel Herd: its PHP reads the ini that
holds the certificate authority for `.test` TLS from those variables.

## Usage

```bash
php artisan dev
```

Press `q` or `Ctrl+C` to stop every service.

## Translations

The dashboard follows the application locale and ships with 19 languages:
English, Turkish, German, French, Spanish, Portuguese (Brazil), Italian,
Dutch, Polish, Russian, Ukrainian, Chinese (Simplified and Traditional),
Japanese, Korean, Arabic, Hindi, Indonesian and Vietnamese. Publish the
language files to change or add one:

```bash
php artisan vendor:publish --tag=dev-mesh-lang
```

## Testing

```bash
composer test      # Pest
composer format    # Pint
composer analyse   # PHPStan
```

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Commits follow Conventional Commits;
releases and the changelog are generated from them.

## License

MIT. See [LICENSE](LICENSE).
