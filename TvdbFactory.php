<?php

namespace Omnifilm\Tvdb;

use Omnifilm\Config;
use Omnifilm\Http\JsonClient;
use Omnifilm\ProviderFactory;
use Omnifilm\ProviderInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * "tvdb": options api_key (required: the project's v4 key), pin (a
 * subscriber's PIN, for a user-supported key), language (null: TheTVDB's
 * own; "fr" or "fra"), api ("https://api4.thetvdb.com/v4").
 */
final class TvdbFactory extends ProviderFactory
{
    public function __construct(private readonly ?HttpClientInterface $http = null)
    {
    }

    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnifilm.factory_name' => 'tvdb',
            'omnifilm.factory_title' => 'TheTVDB',
            'omnifilm.required_options' => ['api_key'],
            'pin' => null,
            'language' => null,
            'api' => 'https://api4.thetvdb.com/v4',
        ]);
    }

    protected function build(Config $c): ProviderInterface
    {
        return new TvdbProvider(new JsonClient($this->http ?? HttpClient::create(['timeout' => 20]), 'TheTVDB', ['Accept' => 'application/json']), (string) $c->string('api_key'), $c->string('pin'), rtrim((string) $c->string('api'), '/'), $c->string('language'));
    }
}
