# omnifilm/tvdb

TheTVDB for [omnisense/omnifilm](https://github.com/glitchr-omnisense/omnifilm), through its API v4
(`https://api4.thetvdb.com/v4`): search, a series' or a film's extended record with its people, by
its TheTVDB number or by an IMDb or TMDB number, an episode, a season's episodes in aired order,
titles and overviews in the language asked when TheTVDB has them. The provider calls:

| Operation | API v4 |
|---|---|
| token | `POST /login` with `apikey` (and `pin`), once per provider |
| search | `GET /search?query=...&type=movie\|series&year=...&limit=...` |
| by another register | `GET /search/remoteid/{id}` |
| a series, a film | `GET /series/{id}/extended?meta=translations&short=false`, `GET /movies/{id}/extended?...` |
| an episode | `GET /episodes/{id}/extended?meta=translations` |
| a season | `GET /series/{id}/episodes/default[/{language}]?season=n&page=p` |

Every call after the login sends `Authorization: Bearer <token>` and `Accept: application/json`.
TheTVDB has no sandbox. No v4 client taking the application's HTTP client was found to reuse.

## Installation

```sh
composer require omnifilm/tvdb
```

Requires PHP 8.2, `omnisense/omnifilm` (1.x) and `symfony/http-client` (6.4, 7 or 8).

## Credentials

- A **project API key** (v4), from a TheTVDB account: the API is licensed - for commercial
  projects or individual developers, with discounts for open source and non-profit projects
  ([api-information](https://thetvdb.com/api-information)).
- A **PIN**, for a user-supported key: the subscriber's.

## Options

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `api_key` | string | - | yes | the project's v4 key |
| `pin` | string | `null` | no | a subscriber's PIN |
| `language` | string | `null` | no | `fr` or `fra`; `null`: TheTVDB's own texts |
| `api` | string | `https://api4.thetvdb.com/v4` | no | the API's base URL |

TheTVDB's language codes have three letters (ISO 639-2). A three-letter code is kept; a two-letter
one (or `fr-FR`) is mapped for sixteen languages: `en` eng, `fr` fra, `de` deu, `es` spa, `it`
ita, `pt` por, `nl` nld, `ja` jpn, `ko` kor, `zh` zho, `ru` rus, `pl` pol, `sv` swe, `da` dan,
`no` nor, `fi` fin; another two-letter code is ignored (TheTVDB's own texts).

## Capabilities

| Contract | |
|---|---|
| `SearchInterface` | films, series |
| `LookupInterface` | film and series: tvdb, imdb, tmdb; episode: tvdb, imdb |
| `EpisodesInterface` | yes |
| `AvailabilityInterface` | no |
| images, credits | yes; a key is needed |

`attribution`: `Metadata provided by TheTVDB (thetvdb.com). Please consider adding missing
information or subscribing.` - TheTVDB asks for a direct link to thetvdb.com beside its metadata.

## Behaviour

**The token.** Asked on the first call (`POST /login`), then kept for the provider's life; TheTVDB
keeps it valid a month. A login refused is a `ProviderException` (`TheTVDB answered 401 for POST
/v4/login. ...`); an answer without a token too (`... answered no token ...`).

**Search.** Each answer: TheTVDB number and the remote identifiers it lists (IMDb, TMDB, Wikidata,
by their `sourceName`), the title translated when the language asked is among the answer's
`translations` (the name otherwise; `originalTitle` the name), year, first air date, overview
(translated likewise), country, primary language, poster (`image_url`), page
`https://thetvdb.com/series/<slug>` (or `/movies/`).

**By another register.** `/search/remoteid/{id}` and the first match of the type asked (`movie`,
`series`, `episode`): its TheTVDB number. A TMDB number is searched as it is, so the type decides
which of a film and a series it names.

**A record.**

| Field | Read from |
|---|---|
| `title` | the translation in the language asked (`meta=translations`), else `name`; `originalTitle` is `name` |
| `released`, `year` | a film: its earliest `releases[].date`; a series: `firstAired`; `year` TheTVDB's when given |
| `runtime` | a film: `runtime`; a series: `averageRuntime` |
| `overview` | the translation, else `overview` |
| `genres` | `genres[].name` |
| `countries`, `languages` | `originalCountry`, `originalLanguage`: TheTVDB's codes (`usa`, `eng`) |
| `identifiers` | the TheTVDB number and `remoteIds` (IMDb, TMDB, Wikidata) |
| `images` | `image`, as `Poster` |
| `credits` | `characters` by `peopleType`: Director, Writer, Creator (as writers), Actor (cast, with the character's name and TheTVDB's sort as order), Producer, Executive Producer (as producers), Composer; the type as the job, the person's TheTVDB number |
| `url` | `https://thetvdb.com/series/<slug>` or `/movies/<slug>` |
| `season` | a series' number of official seasons, the specials (0) left out |

An episode: its translated name (else its name, else `Episode <n>`), aired date, runtime, overview,
still (`image`), its TheTVDB number and remote identifiers, `series` the series' TheTVDB number,
season and number, page `https://thetvdb.com/episodes/{id}`.

**A season.** The episodes in the default (aired) order, the language in the path when one is set,
the season in the query; pages of up to 500, followed while `links.next` is set, 20 pages at most;
only the episodes of the season asked are kept, sorted by number.

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnifilm\Model\WorkRef;
use Omnifilm\Model\WorkType;
use Omnifilm\Tvdb\TvdbFactory;
use Symfony\Component\HttpClient\HttpClient;

$tvdb = (new TvdbFactory(HttpClient::create()))->create(['api_key' => getenv('TVDB_KEY'), 'language' => 'fr']);

$peaks = $tvdb->work(WorkRef::imdb('tt0098936', WorkType::Series));
echo $peaks->title, ' / ', $peaks->originalTitle, ', ', $peaks->season, " seasons\n";
foreach ($tvdb->episodes(WorkRef::tvdb(70533), 1) as $episode) {
    echo $episode->number, '. ', $episode->title, "\n";
}
```

The record read from the package's test answers (written after TheTVDB's v4 reference; no key was
used), in the core's harness on 2026-10-26 - each field of the `Work`, then the calls made:

```
Mystères à Twin Peaks / Twin Peaks, 1990, 1990-04-08, 47 min, season 2
  ids {"imdb":"tt0098936","tmdb":"1920","tvdb":"70533","wikidata":"Q2085"}
  genres ["Crime","Drama"] countries ["usa"] languages ["eng"]
  cast: Kyle MacLachlan as Dale Cooper [Actor] #0
  writer: Mark Frost [Creator]
  director: David Lynch [Director]
  poster https://artworks.thetvdb.com/banners/posters/example-70533.jpg
  https://thetvdb.com/series/twin-peaks
  > POST https://api4.thetvdb.com/v4/login
  > GET https://api4.thetvdb.com/v4/search/remoteid/tt0098936 []
  > GET https://api4.thetvdb.com/v4/series/70533/extended {"meta":"translations","short":"false"}
```

In Symfony:

```yaml
services:
    Omnifilm\Tvdb\TvdbFactory:
        arguments: ['@http_client']
    Omnifilm\Registry:
        arguments:
            $factories: ['@Omnifilm\Tvdb\TvdbFactory']
            $config:
                tvdb: { factory: tvdb, options: { api_key: '%env(TVDB_KEY)%', language: fr } }
```

## Errors

- a login refused, no token: `ProviderException` naming `POST /v4/login`;
- a status of 400 or more but 404: `ProviderException`, TheTVDB's message after it; a 404:
  `null` (or `[]`); a network error: `UnreachableException` (the key travels in the login's body,
  not in a URL);
- a missing `api_key`: `InvalidConfigException` when the provider is built;
- an episode by its TMDB number, a Trakt or Wikidata number: `NotSupportedException` before any
  call.

## Verified, and not

Tested offline (`Tests/TvdbProviderTest.php`, in the core's harness, PHP 8.4): the token got once
then sent as a bearer, a search in French, a series by its IMDb number with its people and
identifiers, a season's episodes in order, unknown as `null`, a refused key. The answers are shaped
after TheTVDB's v4 reference and written by hand; the `sourceName` of remote identifiers (`IMDB`,
`TheMovieDB.com`, `Wikidata`) is the reference's, not seen live. `Tests/BareTest.php` loads every
class with the package's requirements alone.

Not verified: no TheTVDB key was used; nothing was called on TheTVDB.

## Responsibility

An independent, unofficial client, affiliated with TheTVDB in no way. Whoever runs it holds a
licence of TheTVDB's API and shows its attribution with a direct link to thetvdb.com
([api-information](https://thetvdb.com/api-information)): see the README.
