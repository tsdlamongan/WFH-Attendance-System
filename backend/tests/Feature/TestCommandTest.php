<?php

use App\Console\Commands\TestCommand;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Input\ArrayInput;

/*
|--------------------------------------------------------------------------
| `php artisan test` coverage guard
|--------------------------------------------------------------------------
|
| The project standard is 90% line coverage on every coverage run. These tests make sure the guard
| keeps working (e.g. after a Collision upgrade) without launching a nested test run.
|
*/

/**
 * The test command with its console input bound to the given arguments/options, as artisan would do.
 */
function testCommandWithInput(array $input): TestCommand
{
    $command = new TestCommand;
    $command->setLaravel(app());

    (new ReflectionProperty($command, 'input'))
        ->setValue($command, new ArrayInput($input, $command->getDefinition()));

    return $command;
}

function applyMinimumCoverage(TestCommand $command): void
{
    (new ReflectionMethod($command, 'applyMinimumCoverage'))->invoke($command);
}

it('replaces the framework test command, so `php artisan test` goes through the guard', function () {
    expect(Artisan::all()['test'])->toBeInstanceOf(TestCommand::class);
});

it('requires at least 90 percent', function () {
    expect(TestCommand::MINIMUM_COVERAGE)->toBe(90);
});

it('enforces the minimum when --coverage is used without --min', function () {
    $command = testCommandWithInput(['--coverage' => true]);

    applyMinimumCoverage($command);

    expect($command->option('min'))->toBe('90');
});

it('keeps an explicit --min, including an explicit opt-out with --min=0', function (string $min) {
    $command = testCommandWithInput(['--coverage' => true, '--min' => $min]);

    applyMinimumCoverage($command);

    expect($command->option('min'))->toBe($min);
})->with(['0', '75', '100']);

it('does not touch runs that do not collect coverage', function () {
    $command = testCommandWithInput([]);

    applyMinimumCoverage($command);

    expect($command->option('min'))->toBeNull();
});
