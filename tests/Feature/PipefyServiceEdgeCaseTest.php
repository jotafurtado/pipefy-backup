<?php

use App\Exceptions\PipefyApiException;
use App\Services\PipefyService;
use Illuminate\Http\Client\ConnectionException;
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

test('each card page returns zero total for pipe with zero cards', function () {
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

    $pages = [];

    $total = makeService()->eachCardPage(
        pipeId: 99999,
        onPage: function (array $pageCards) use (&$pages): void {
            $pages[] = $pageCards;
        },
    );

    expect($total)->toBe(0);
    expect($pages)->toHaveCount(1);
    expect($pages[0])->toBeEmpty();
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

test('each card page throws pipefy api exception on connection error', function () {
    fakeOauthToken();

    Http::fake(function ($request) {
        if (str_contains($request->url(), 'oauth/token')) {
            return Http::response(['access_token' => 'fake-token', 'expires_in' => 3600]);
        }

        throw new ConnectionException('Connection timed out');
    });

    makeService()->eachCardPage(
        pipeId: 12345,
        onPage: fn () => null,
    );
})->throws(PipefyApiException::class, 'Falha ao conectar com a API do Pipefy');

test('get card attachments throws pipefy api exception on connection error', function () {
    fakeOauthToken();

    Http::fake(function ($request) {
        if (str_contains($request->url(), 'oauth/token')) {
            return Http::response(['access_token' => 'fake-token', 'expires_in' => 3600]);
        }

        throw new ConnectionException('Connection refused');
    });

    makeService()->getCardAttachments(cardId: 12345);
})->throws(PipefyApiException::class, 'Falha ao conectar com a API do Pipefy');

test('query refreshes token and retries on 401 response', function () {
    fakeOauthToken();

    $tokenRequests = 0;
    $queryRequests = 0;

    Http::fake(function ($request) use (&$tokenRequests, &$queryRequests) {
        if (str_contains($request->url(), 'oauth/token')) {
            $tokenRequests++;

            return Http::response(['access_token' => 'token-'.$tokenRequests, 'expires_in' => 3600]);
        }

        $queryRequests++;
        if ($queryRequests === 1) {
            return Http::response(['message' => 'The login information is not valid'], 401);
        }

        return Http::response([
            'data' => [
                'card' => [
                    'attachments' => [],
                ],
            ],
        ], 200);
    });

    $result = makeService()->getCardAttachments(cardId: 12345);

    expect($result)->toBeArray()->toBeEmpty();
    expect($tokenRequests)->toBe(2);
    expect($queryRequests)->toBe(2);
});
