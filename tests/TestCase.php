<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Nama popup <x-form-modal> yang dirender terbuka (dibuka ulang setelah galat server) pada $html. */
    protected function openModals(string $html): array
    {
        preg_match_all("/formModal\\(true\\)\"\\s+@open-form-modal\\.window=\"\\\$event\\.detail === (?:&#039;|')([^&']+)/", $html, $m);

        return $m[1];
    }
}
