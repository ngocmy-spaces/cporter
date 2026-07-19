<?php

use App\Domain\Deploy\EnvFileRenderer;

it('renders the managed marker as the first line', function () {
    $out = EnvFileRenderer::render([['key' => 'APP_ENV', 'value' => 'production']]);

    expect(explode("\n", $out)[0])->toBe(EnvFileRenderer::MARKER)
        ->and($out)->toContain('APP_ENV="production"');
});

it('quotes and escapes special characters phpdotenv-safe', function () {
    $out = EnvFileRenderer::render([
        ['key' => 'QUOTED', 'value' => 'a "b" c'],
        ['key' => 'BACKSLASH', 'value' => 'a\\b'],
        ['key' => 'MULTILINE', 'value' => "line1\nline2"],
        ['key' => 'SPACED', 'value' => 'Acme Inc'],
        ['key' => 'HASH', 'value' => 'not#acomment'],
    ]);

    expect($out)->toContain('QUOTED="a \\"b\\" c"')
        ->toContain('BACKSLASH="a\\\\b"')
        ->toContain('MULTILINE="line1\\nline2"')
        ->toContain('SPACED="Acme Inc"')
        ->toContain('HASH="not#acomment"');
});

it('is deterministic and drops blank-key rows', function () {
    $vars = [['key' => 'A', 'value' => '1'], ['key' => '', 'value' => 'ignored']];

    expect(EnvFileRenderer::render($vars))->toBe(EnvFileRenderer::render($vars))
        ->and(EnvFileRenderer::render($vars))->not->toContain('ignored');
});
