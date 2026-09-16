<?php

use App\Services\PipefyService;
use Illuminate\Support\Facades\Http;
use Tests\Helpers\PaginationHelper;

test('pagination aggregation returns exactly n cards for any count', function () {
    for ($i = 0; $i < 100; $i++) {
        PaginationHelper::resetHttpFake();

        $totalCards = fake()->numberBetween(0, 200);

        $cards = [];
        for ($j = 0; $j < $totalCards; $j++) {
            $cards[] = PaginationHelper::makeCard($j);
        }

        $paginatedResponses = PaginationHelper::buildPaginatedResponses($cards);
        $pageIndex = 0;

        Http::fake(function ($request) use ($paginatedResponses, &$pageIndex) {
            if (str_contains($request->url(), 'oauth/token')) {
                return Http::response(['access_token' => 'fake-token', 'expires_in' => 3600]);
            }

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

        $result = $service->getCards(pipeId: 12345);

        expect($result)->toHaveCount($totalCards);
    }
});
