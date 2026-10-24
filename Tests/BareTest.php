<?php

namespace Omnifilm\Tvdb\Tests;

use Omnifilm\Testing\BareTestCase;
use Omnifilm\Tvdb\TvdbProvider;

/** omnifilm/tvdb's classes load with omniscient/omnifilm and Symfony's HTTP client contracts alone. */
final class BareTest extends BareTestCase
{
    protected static function entryClass(): string
    {
        return TvdbProvider::class;
    }

    protected static function requiredPackages(): array
    {
        return ['symfony/http-client-contracts'];
    }
}
