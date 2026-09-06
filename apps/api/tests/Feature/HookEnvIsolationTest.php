<?php

use App\Adapters\Command\ProcessCommandRunner;

/*
| Hooks run inside the TARGET app, which must resolve its config from its own .env. phpdotenv never
| overwrites a variable that already exists in the environment, so anything cPorter's own .env put
| into this process would win over the target's file — that is how a target app's `artisan migrate`
| hook once ran against cPorter's database. See docs/SPEC.md §23.
*/

beforeEach(function () {
    if (! function_exists('proc_open')) {
        $this->markTestSkipped('proc_open is unavailable in this environment.');
    }

    $this->runner = new ProcessCommandRunner;
    $this->cwd = sys_get_temp_dir();
    $this->originalEnv = [];

    // Set a variable the way Laravel's putenv adapter does, remembering it for afterEach.
    $this->fakeCporterEnv = function (string $name, string $value) {
        $this->originalEnv[$name] = getenv($name);
        putenv("{$name}={$value}");
        $_ENV[$name] = $value;
    };
});

afterEach(function () {
    foreach ($this->originalEnv as $name => $value) {
        if ($value === false) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        } else {
            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
        }
    }
});

it('does not leak cPorter environment variables into a hook process', function () {
    ($this->fakeCporterEnv)('DB_DATABASE', 'cporter_control_db');
    ($this->fakeCporterEnv)('DB_PASSWORD', 'cporter-db-secret');
    ($this->fakeCporterEnv)('APP_KEY', 'base64:cporter-app-key');
    ($this->fakeCporterEnv)('CPORTER_CRON_TOKEN', 'cporter-cron-secret');

    $output = $this->runner->run('printenv', $this->cwd)->output;

    expect($output)->not->toContain('cporter_control_db')
        ->and($output)->not->toContain('cporter-db-secret')
        ->and($output)->not->toContain('base64:cporter-app-key')
        ->and($output)->not->toContain('cporter-cron-secret')
        ->and($output)->not->toContain('DB_DATABASE=');
});

it('passes the allow-listed shell environment through so hooks still resolve binaries', function () {
    $result = $this->runner->run("command -v 'sh'", $this->cwd, [], 10);

    expect($result->ok())->toBeTrue()
        ->and(trim($result->output))->not->toBe('')
        ->and(trim($this->runner->run('printenv PATH', $this->cwd)->output))->not->toBe('');
});

it('honours prefix patterns in the passthrough list', function () {
    config(['cporter.hooks.env_passthrough' => ['PATH', 'MYTOOL_*']]);

    ($this->fakeCporterEnv)('MYTOOL_HOME', '/opt/mytool-kept');
    ($this->fakeCporterEnv)('OTHERTOOL_HOME', '/opt/othertool-stripped');

    $output = $this->runner->run('printenv', $this->cwd)->output;

    expect($output)->toContain('/opt/mytool-kept')
        ->and($output)->not->toContain('/opt/othertool-stripped');
});

it('still applies caller-supplied env overrides on top of the isolation', function () {
    $result = $this->runner->run('printenv CPORTER_HOOK_TEST', $this->cwd, ['CPORTER_HOOK_TEST' => 'explicit']);

    expect(trim($result->output))->toBe('explicit');
});
