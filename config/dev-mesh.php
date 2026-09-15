<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Command name
    |--------------------------------------------------------------------------
    |
    | The artisan command that opens the dashboard: `php artisan dev`.
    |
    */

    'command' => env('DEV_MESH_COMMAND', 'dev'),

    /*
    |--------------------------------------------------------------------------
    | Title
    |--------------------------------------------------------------------------
    |
    | Shown in the dashboard header. `null` uses the application name.
    |
    */

    'title' => null,

    /*
    |--------------------------------------------------------------------------
    | Projects
    |--------------------------------------------------------------------------
    |
    | Laravel projects to run, as `label => absolute path` (a plain list of
    | paths uses each directory name as the label). Every project gets every
    | service below, started from its own directory with its own `.env`.
    | Leave empty to run the current application only.
    |
    */

    'projects' => [
        // 'api' => base_path('../api'),
        // 'admin' => base_path('../admin'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Services
    |--------------------------------------------------------------------------
    |
    | Long-running commands started in each project, as `column => command`.
    | Output of `queue:work`-style workers (Horizon included) and of
    | `schedule:work` is parsed into the job counters and the activity feed.
    |
    */

    'services' => [
        'horizon' => ['php', 'artisan', 'horizon'],
        'schedule' => ['php', 'artisan', 'schedule:work'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Environment
    |--------------------------------------------------------------------------
    |
    | Children start with a clean environment (`env -i`): the variables Dotenv
    | loaded for THIS application would otherwise override each project's own
    | `.env` — a sibling's worker would connect to this application's database.
    | Only the variables below are passed through. Keep `HERD_` for Laravel
    | Herd's PHP (its ini holds the CA used for `.test` TLS) and `NVM_` for node.
    |
    */

    'environment' => [
        'keep' => ['HOME', 'PATH', 'USER', 'SHELL', 'TMPDIR', 'TERM', 'LANG'],
        'keep_prefixes' => ['HERD_', 'NVM_'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Restart back-off
    |--------------------------------------------------------------------------
    |
    | A crashed service is restarted after 1, 2, 4 ... seconds, never waiting
    | longer than this.
    |
    */

    'max_restart_delay' => 30,

];
