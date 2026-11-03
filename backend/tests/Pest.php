<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Existing tests are class-based PHPUnit tests that extend Tests\TestCase
| themselves, so they are unaffected by this file. The bindings below apply
| to Pest-style tests (it() / test() closures), so new tests can be written
| in either style: Feature tests boot the Laravel app with a fresh database,
| Unit tests stay plain PHP.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');
