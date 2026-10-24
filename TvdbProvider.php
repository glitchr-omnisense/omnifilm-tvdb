<?php

namespace Omnifilm\Tvdb;

use Omnifilm\Capabilities;
use Omnifilm\EpisodesInterface;
use Omnifilm\Exception\NotSupportedException;
use Omnifilm\Exception\ProviderException;
use Omnifilm\Http\Data;
use Omnifilm\Http\JsonClient;
use Omnifilm\LookupInterface;
use Omnifilm\Model\Credit;
use Omnifilm\Model\Identifiers;
use Omnifilm\Model\Image;
use Omnifilm\Model\ImageKind;
use Omnifilm\Model\Query;
use Omnifilm\Model\Role;
use Omnifilm\Model\Scheme;
use Omnifilm\Model\Work;
use Omnifilm\Model\WorkRef;
use Omnifilm\Model\WorkType;
use Omnifilm\ProviderInterface;
use Omnifilm\SearchInterface;

/**
 * TheTVDB's API v4: a token got once from the project's key (and a
 * subscriber's PIN, when the key is a user-supported one), kept for the
 * provider's life; search, a series' or a film's extended record, a work by
 * an IMDb or TMDB number (/search/remoteid), a season's episodes in aired
 * order, titles and overviews in the language asked when TheTVDB has them
 * (its codes are three letters: "fra", "eng").
 */
final class TvdbProvider implements ProviderInterface, SearchInterface, LookupInterface, EpisodesInterface
{
    /** ISO 639-1 to TheTVDB's ISO 639-2 codes, for the languages asked most. */
    private const LANGUAGES = ['en' => 'eng', 'fr' => 'fra', 'de' => 'deu', 'es' => 'spa', 'it' => 'ita', 'pt' => 'por', 'nl' => 'nld', 'ja' => 'jpn', 'ko' => 'kor', 'zh' => 'zho', 'ru' => 'rus', 'pl' => 'pol', 'sv' => 'swe', 'da' => 'dan', 'no' => 'nor', 'fi' => 'fin'];
    /** TheTVDB's names of other registers (remoteIds' sourceName). */
    private const REMOTE = ['imdb' => 'imdb', 'themoviedb.com' => 'tmdb', 'tmdb' => 'tmdb', 'wikidata' => 'wikidata'];
    private const PEOPLE = ['Director' => Role::Director, 'Writer' => Role::Writer, 'Actor' => Role::Cast, 'Producer' => Role::Producer, 'Executive Producer' => Role::Producer, 'Creator' => Role::Writer, 'Composer' => Role::Composer];

    private ?string $token = null;

    public function __construct(private readonly JsonClient $json, private readonly string $key, private readonly ?string $pin = null, private readonly string $api = 'https://api4.thetvdb.com/v4', private readonly ?string $language = null)
    {
    }

    public function getName(): string
    {
        return 'tvdb';
    }

    public function getTitle(): string
    {
        return 'TheTVDB';
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            search: true,
            lookup: true,
            episodes: true,
            types: [WorkType::Movie, WorkType::Series, WorkType::Episode],
            lookupBy: ['movie' => [Scheme::Tvdb, Scheme::Imdb, Scheme::Tmdb], 'series' => [Scheme::Tvdb, Scheme::Imdb, Scheme::Tmdb], 'episode' => [Scheme::Tvdb, Scheme::Imdb]],
            images: true,
            credits: true,
            attribution: 'Metadata provided by TheTVDB (thetvdb.com). Please consider adding missing information or subscribing.',
            terms: ['https://thetvdb.com/api-information', 'https://thetvdb.com/tos'],
        );
    }

    public function search(Query $query): array
    {
        $answer = $this->get('/search', ['query' => $query->text, 'type' => null === $query->type ? null : (WorkType::Movie === $query->type ? 'movie' : 'series'), 'year' => $query->year, 'limit' => $query->limit]);
        $language = self::language($query->language ?? $this->language);
        $works = [];
        foreach ($answer?->list('data') ?? [] as $result) {
            $type = match ($result->string('type')) {
                'movie' => WorkType::Movie,
                'series' => WorkType::Series,
                default => null,
            };
            if (null === $type) {
                continue;
            }
            $released = $result->date('first_air_time');
            $ids = ['tvdb' => $result->string('tvdb_id')];
            foreach ($result->list('remote_ids') as $remote) {
                $ids[self::REMOTE[strtolower((string) $remote->string('sourceName'))] ?? '_'] ??= $remote->string('id');
            }
            unset($ids['_']);
            $image = $result->string('image_url');
            $works[] = new Work(
                type: $type,
                title: (string) (null !== $language ? ($result->string('translations.'.$language) ?? $result->string('name')) : $result->string('name')),
                identifiers: Identifiers::of($ids),
                source: 'tvdb',
                originalTitle: $result->string('name'),
                year: $result->int('year') ?? ($released ? (int) $released->format('Y') : null),
                released: $released,
                overview: null !== $language ? ($result->string('overviews.'.$language) ?? $result->string('overview')) : $result->string('overview'),
                countries: array_filter([$result->string('country')]),
                languages: array_filter([$result->string('primary_language')]),
                images: null === $image ? [] : [new Image(ImageKind::Poster, $image)],
                url: null === $result->string('slug') ? null : 'https://thetvdb.com/'.(WorkType::Movie === $type ? 'movies' : 'series').'/'.$result->string('slug'),
            );
        }

        return \array_slice($works, 0, $query->limit);
    }

    public function work(WorkRef $ref, ?string $language = null): ?Work
    {
        if (!$this->capabilities()->resolves($ref)) {
            throw NotSupportedException::ref($this->getTitle(), $ref);
        }
        $id = Scheme::Tvdb === $ref->scheme ? $ref->id : $this->remote($ref);
        if (null === $id) {
            return null;
        }
        $language = self::language($language ?? $this->language);
        if (WorkType::Episode === $ref->type) {
            $data = $this->get('/episodes/'.rawurlencode($id).'/extended', ['meta' => 'translations'])?->data('data');

            return null === $data || null === $data->string('id') ? null : $this->episode($data, $language);
        }
        $path = WorkType::Movie === $ref->type ? '/movies/' : '/series/';
        $data = $this->get($path.rawurlencode($id).'/extended', ['meta' => 'translations', 'short' => 'false'])?->data('data');

        return null === $data || null === $data->string('id') ? null : $this->work0($data, $ref->type, $language);
    }

    public function episodes(WorkRef $series, int $season, ?string $language = null): array
    {
        if (WorkType::Series !== $series->type) {
            return [];
        }
        $id = Scheme::Tvdb === $series->scheme ? $series->id : $this->remote($series);
        if (null === $id) {
            return [];
        }
        $language = self::language($language ?? $this->language);
        $episodes = [];
        // Aired order, a page of 500 at a time (TheTVDB's page size), the season asked.
        for ($page = 0; $page < 20; ++$page) {
            $answer = $this->get('/series/'.rawurlencode($id).'/episodes/default'.(null !== $language ? '/'.$language : ''), ['season' => $season, 'page' => $page]);
            foreach ($answer?->list('data.episodes') ?? [] as $episode) {
                if ($season === $episode->int('seasonNumber')) {
                    $episodes[] = $this->episode($episode, null, (int) $id);
                }
            }
            if (null === $answer?->string('links.next')) {
                break;
            }
        }
        usort($episodes, static fn (Work $a, Work $b) => $a->number <=> $b->number);

        return $episodes;
    }

    /** TheTVDB's number of a work known by another register. */
    private function remote(WorkRef $ref): ?string
    {
        $key = match ($ref->type) {
            WorkType::Movie => 'movie',
            WorkType::Series => 'series',
            WorkType::Episode => 'episode',
        };
        foreach ($this->get('/search/remoteid/'.rawurlencode($ref->id))?->list('data') ?? [] as $match) {
            if (null !== $id = $match->string($key.'.id')) {
                return $id;
            }
        }

        return null;
    }

    private function work0(Data $data, WorkType $type, ?string $language): Work
    {
        $movie = WorkType::Movie === $type;
        $released = $movie ? self::firstRelease($data) : $data->date('firstAired');
        $name = $data->string('name');
        $credits = [];
        foreach ($data->list('characters') as $character) {
            $role = self::PEOPLE[(string) $character->string('peopleType')] ?? null;
            if (null !== $role && null !== $person = $character->string('personName')) {
                $credits[] = new Credit($person, $role, Role::Cast === $role ? $character->string('name') : null, Role::Cast === $role ? $character->int('sort') : null, Identifiers::of(['tvdb' => $character->string('peopleId')]), $character->string('peopleType'));
            }
        }
        $image = $data->string('image');
        $slug = $data->string('slug');

        return new Work(
            type: $type,
            title: (string) ($this->translated($data, 'nameTranslations', 'name', $language) ?? $name),
            identifiers: Identifiers::of(['tvdb' => $data->string('id')] + self::remoteIds($data)),
            source: 'tvdb',
            originalTitle: $name,
            year: $data->int('year') ?? (null !== $released ? (int) $released->format('Y') : null),
            released: $released,
            runtime: $data->int($movie ? 'runtime' : 'averageRuntime'),
            overview: $this->translated($data, 'overviewTranslations', 'overview', $language) ?? $data->string('overview'),
            genres: $data->strings('genres', 'name'),
            countries: null === ($country = $data->string('originalCountry')) ? [] : [$country],
            languages: null === ($language = $data->string('originalLanguage')) ? [] : [$language],
            images: null === $image ? [] : [new Image(ImageKind::Poster, $image)],
            credits: $credits,
            url: null === $slug ? null : 'https://thetvdb.com/'.($movie ? 'movies' : 'series').'/'.$slug,
            season: $movie ? null : (\count(array_filter($data->list('seasons'), static fn (Data $s) => 'official' === $s->string('type.type') && ($s->int('number') ?? 0) > 0)) ?: null),
        );
    }

    private function episode(Data $data, ?string $language, ?int $series = null): Work
    {
        $released = $data->date('aired');
        $image = $data->string('image');

        return new Work(
            type: WorkType::Episode,
            title: (string) ($this->translated($data, 'nameTranslations', 'name', $language) ?? $data->string('name') ?? 'Episode '.$data->int('number')),
            identifiers: Identifiers::of(['tvdb' => $data->string('id')] + self::remoteIds($data)),
            source: 'tvdb',
            year: null !== $released ? (int) $released->format('Y') : null,
            released: $released,
            runtime: $data->int('runtime'),
            overview: $this->translated($data, 'overviewTranslations', 'overview', $language) ?? $data->string('overview'),
            images: null === $image ? [] : [new Image(ImageKind::Still, $image)],
            url: null === $data->string('id') ? null : 'https://thetvdb.com/episodes/'.$data->string('id'),
            series: Identifiers::of(['tvdb' => $series ?? $data->int('seriesId')]),
            season: $data->int('seasonNumber'),
            number: $data->int('number'),
        );
    }

    /** A text in the language asked, from the record's translations (meta=translations). */
    private function translated(Data $data, string $list, string $field, ?string $language): ?string
    {
        if (null === $language) {
            return null;
        }
        foreach ($data->list('translations.'.$list) as $translation) {
            if ($language === $translation->string('language')) {
                return $translation->string($field);
            }
        }

        return null;
    }

    /** @return array<string, string|null> */
    private static function remoteIds(Data $data): array
    {
        $ids = [];
        foreach ($data->list('remoteIds') as $remote) {
            $scheme = self::REMOTE[strtolower((string) $remote->string('sourceName'))] ?? null;
            if (null !== $scheme) {
                $ids[$scheme] ??= $remote->string('id');
            }
        }

        return $ids;
    }

    private static function firstRelease(Data $data): ?\DateTimeImmutable
    {
        $dates = array_filter(array_map(static fn (Data $r) => $r->date('date'), $data->list('releases')));
        usort($dates, static fn (\DateTimeImmutable $a, \DateTimeImmutable $b) => $a <=> $b);

        return $dates[0] ?? null;
    }

    private static function language(?string $language): ?string
    {
        if (null === $language) {
            return null;
        }
        $code = strtolower(substr(str_replace('_', '-', $language), 0, 3));
        $code = rtrim($code, '-');

        return 3 === \strlen($code) ? $code : (self::LANGUAGES[$code] ?? null);
    }

    /** @param array<string, string|int|null> $query */
    private function get(string $path, array $query = []): ?Data
    {
        return $this->json->get($this->api.$path, $query, ['Authorization' => 'Bearer '.$this->token()]);
    }

    /** The bearer token, got once (TheTVDB keeps it valid a month). */
    private function token(): string
    {
        if (null === $this->token) {
            $answer = $this->json->request('POST', $this->api.'/login', ['json' => array_filter(['apikey' => $this->key, 'pin' => $this->pin])]);
            $this->token = $answer?->string('data.token') ?? throw ProviderException::answered('TheTVDB', 'POST /login', 'no token', (string) $answer?->string('message'));
        }

        return $this->token;
    }
}
