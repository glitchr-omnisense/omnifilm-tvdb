<?php

namespace Omnifilm\Tvdb\Tests;

use Omnifilm\Exception\ProviderException;
use Omnifilm\Model\Credit;
use Omnifilm\Model\Query;
use Omnifilm\Model\Role;
use Omnifilm\Model\Work;
use Omnifilm\Model\WorkRef;
use Omnifilm\Model\WorkType;
use Omnifilm\Testing\RecordedHttpClient;
use Omnifilm\Tvdb\TvdbFactory;
use Omnifilm\Tvdb\TvdbProvider;
use PHPUnit\Framework\TestCase;

/**
 * TheTVDB's answers shaped after its v4 API reference (thetvdb.github.io/v4-api),
 * values written by hand: no key was used, nothing here was captured from
 * TheTVDB.
 */
final class TvdbProviderTest extends TestCase
{
    public function testTheTokenIsGotOnceThenSentAsABearer(): void
    {
        $http = self::http();
        $provider = self::provider($http, ['pin' => '0000']);
        $provider->search(new Query('twin peaks'));
        $provider->search(new Query('twin peaks'));

        self::assertSame(['POST', 'https://api4.thetvdb.com/v4/login'], [$http->requests[0]['method'], $http->requests[0]['url']]);
        self::assertSame(['apikey' => 'not-a-real-tvdb-key', 'pin' => '0000'], $http->requests[0]['options']['json'] ?? null);
        self::assertCount(3, $http->requests);
        self::assertSame('Bearer not-a-real-tvdb-token', $http->header('Authorization'));
    }

    public function testASearchInTheLanguageAsked(): void
    {
        $works = self::provider(self::http())->search(new Query('twin peaks', WorkType::Series, language: 'fr'));

        self::assertSame([['Mystères à Twin Peaks', 'Twin Peaks', 1990, 'Un agent du FBI enquête sur un meurtre.']], array_map(static fn (Work $w) => [$w->title, $w->originalTitle, $w->year, $w->overview], $works));
        self::assertSame(['imdb' => 'tt0098936', 'tmdb' => '1920', 'tvdb' => '70533'], $works[0]->identifiers->toArray());
        self::assertSame('https://thetvdb.com/series/twin-peaks', $works[0]->url);
    }

    public function testASeriesByItsImdbNumberWithItsPeople(): void
    {
        $http = self::http();
        $work = self::provider($http, ['language' => 'fra'])->work(WorkRef::imdb('tt0098936', WorkType::Series));

        self::assertNotNull($work);
        self::assertSame(['Mystères à Twin Peaks', 'Twin Peaks', '1990-04-08', 47, 2], [$work->title, $work->originalTitle, $work->released?->format('Y-m-d'), $work->runtime, $work->season]);
        self::assertSame(['imdb' => 'tt0098936', 'tmdb' => '1920', 'tvdb' => '70533', 'wikidata' => 'Q2085'], $work->identifiers->toArray());
        self::assertSame([['Kyle MacLachlan', 'Dale Cooper']], array_map(static fn (Credit $c) => [$c->name, $c->character], $work->credits(Role::Cast)));
        self::assertSame([['Mark Frost', 'Creator']], array_map(static fn (Credit $c) => [$c->name, $c->job], $work->credits(Role::Writer)));
        self::assertSame(['Crime', 'Drama'], $work->genres);
        self::assertStringContainsString('/search/remoteid/tt0098936', $http->requests[1]['url']);
    }

    public function testASeasonsEpisodesInOrder(): void
    {
        $http = self::http();
        $episodes = self::provider($http)->episodes(WorkRef::tvdb(70533), 1, 'fr');

        self::assertSame([[1, 'Pilot', '1990-04-08', 94], [2, 'Traces to Nowhere', '1990-04-12', 47]], array_map(static fn (Work $w) => [$w->number, $w->title, $w->released?->format('Y-m-d'), $w->runtime], $episodes));
        self::assertSame('70533', $episodes[0]->series?->get('tvdb'));
        self::assertSame('https://api4.thetvdb.com/v4/series/70533/episodes/default/fra', $http->requests[1]['url']);
    }

    public function testUnknownIsNullAndARefusedKeyAnError(): void
    {
        self::assertNull(self::provider(self::http())->work(WorkRef::tvdb(1)));

        $http = RecordedHttpClient::routes(['~/login~' => [401, '{"status": "failure", "message": "Unauthorized"}']]);
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('TheTVDB answered 401 for POST /v4/login');
        self::provider($http)->work(WorkRef::tvdb(70533));
    }

    private static function http(): RecordedHttpClient
    {
        $f = '@'.__DIR__.'/Fixtures/';
        $api = '~ https://api4\.thetvdb\.com/v4/';

        return RecordedHttpClient::routes([
            '~^POST https://api4\.thetvdb\.com/v4/login$~' => [200, $f.'login.json'],
            $api.'search\?query=twin~' => [200, $f.'search.json'],
            $api.'search/remoteid/tt0098936~' => [200, $f.'remoteid.json'],
            $api.'series/70533/extended~' => [200, $f.'series-70533.json'],
            $api.'series/70533/episodes/default~' => [200, $f.'episodes-70533.json'],
        ]);
    }

    /** @param array<string, mixed> $options */
    private static function provider(RecordedHttpClient $http, array $options = []): TvdbProvider
    {
        $provider = (new TvdbFactory($http))->create($options + ['api_key' => 'not-a-real-tvdb-key']);
        self::assertInstanceOf(TvdbProvider::class, $provider);

        return $provider;
    }
}
