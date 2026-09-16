<?php

use App\Exceptions\PipefyApiException;
use App\Services\PipefyService;
use Illuminate\Support\Facades\Http;

function makeService(): PipefyService
{
    return new PipefyService(
        clientId: 'test-id',
        clientSecret: 'test-secret',
        tokenUrl: 'https://app.pipefy.com/oauth/token',
        endpoint: 'https://api.pipefy.com/graphql',
    );
}

function fakeOauthToken(): void
{
    cache()->forget('pipefy_access_token');
}

test('get cards returns empty array for pipe with zero cards', function () {
    fakeOauthToken();

    Http::fake(function ($request) {
        if (str_contains($request->url(), 'oauth/token')) {
            return Http::response(['access_token' => 'fake-token', 'expires_in' => 3600]);
        }

        return Http::response([
            'data' => [
                'allCards' => [
                    'pageInfo' => [
                        'hasNextPage' => false,
                        'endCursor' => null,
                    ],
                    'edges' => [],
                ],
            ],
        ]);
    });

    $result = makeService()->getCards(pipeId: 99999);

    expect($result)->toBeArray()->toBeEmpty();
});

test('get card attachments returns empty array for card with zero attachments', function () {
    fakeOauthToken();

    Http::fake(function ($request) {
        if (str_contains($request->url(), 'oauth/token')) {
            return Http::response(['access_token' => 'fake-token', 'expires_in' => 3600]);
        }

        return Http::response([
            'data' => [
                'card' => [
                    'attachments' => [],
                ],
            ],
        ]);
    });

    $result = makeService()->getCardAttachments(cardId: 12345);

    expect($result)->toBeArray()->toBeEmpty();
});

test('get cards throws pipefy api exception on connection error', function () {
    fakeOauthToken();

    Http::fake(function ($request) {
        if (str_contains($request->url(), 'oauth/token')) {
            return Http::response(['access_token' => 'fake-token', 'expires_in' => 3600]);
        }

        throw new \Illuminate\Http\Client\ConnectionException('Connection timed out');
    });

    makeService()->getCards(pipeId: 12345);
})->throws(PipefyApiException::class, 'Falha ao conectar com a API do Pipefy');

test('get card attachments throws pipefy api exception on connection error', function () {
    fakeOauthToken();

    Http::fake(function ($request) {
        if (str_contains($request->url(), 'oauth/token')) {
            return Http::response(['access_token' => 'fake-token', 'expires_in' => 3600]);
        }

        throw new \Illuminate\Http\Client\ConnectionException('Connection refused');
    });

    makeService()->getCardAttachments(cardId: 12345);
})->throws(PipefyApiException::class, 'Falha ao conectar com a API do Pipefy');
