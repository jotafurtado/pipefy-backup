<?php

use App\Services\PipefyService;
use Illuminate\Support\Facades\Http;
use Tests\Helpers\PaginationHelper;

test('all graphql requests contain first with value 50', function () {
    for ($i = 0; $i < 100; $i++) {
        PaginationHelper::resetHttpFake();

        $totalCards = fake()->numberBetween(0, 200);

        $cards = [];
        for ($j = 0; $j < $totalCards; $j++) {
            $cards[] = PaginationHelper::makeCard($j);
        }

        $paginatedResponses = PaginationHelper::buildPaginatedResponses($cards);
        $pageIndex = 0;
        $capturedRequests = [];

        Http::fake(function ($request) use ($paginatedResponses, &$pageIndex, &$capturedRequests) {
            if (str_contains($request->url(), 'oauth/token')) {
                return Http::response(['access_token' => 'fake-token', 'expires_in' => 3600]);
            }

            $capturedRequests[] = $request;

            $response = $paginatedResponses[$pageIndex] ?? $paginatedResponses[0];
            $pageIndex++;

            return Http::response($response);
        });

        cache()->forget('pipefy_access_token');

        $service = new PipefyService(
            clientId: 'test-id',
            clientSecret: 'test-secret',
            tokenUrl: 'https://app.pipefy.com/oauth/token',
            endpoint: 'https://api.pipefy.com/graphql',
        );

        $service->getCards(pipeId: fake()->numberBetween(1, 99999));

        $expectedPages = max(1, (int) ceil($totalCards / 50));

        expect($capturedRequests)->toHaveCount($expectedPages);

        foreach ($capturedRequests as $reqIndex => $request) {
            $body = json_decode($request->body(), true);
            $variables = $body['variables'] ?? [];

            expect($variables)->toHaveKey('first');
            expect($variables['first'])->toBe(50);
        }
    }
});
