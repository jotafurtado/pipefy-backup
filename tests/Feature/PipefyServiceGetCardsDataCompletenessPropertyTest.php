<?php

use App\Services\PipefyService;
use Illuminate\Support\Facades\Http;
use Tests\Helpers\CardGenerator;
use Tests\Helpers\PaginationHelper;

test('get cards preserves all card fields with equivalent values', function () {
    $expectedFields = [
        'id', 'title', 'assignees', 'comments', 'comments_count',
        'current_phase', 'done', 'due_date', 'fields', 'labels',
        'phases_history', 'url',
    ];

    for ($i = 0; $i < 100; $i++) {
        PaginationHelper::resetHttpFake();

        $cardCount = fake()->numberBetween(1, 5);
        $generatedCards = [];
        for ($j = 0; $j < $cardCount; $j++) {
            $generatedCards[] = CardGenerator::generateRandomCard();
        }

        $edges = array_map(fn (array $card) => ['node' => $card], $generatedCards);

        Http::fake(function ($request) use ($edges) {
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
                        'edges' => $edges,
                    ],
                ],
            ]);
        });

        cache()->forget('pipefy_access_token');

        $service = new PipefyService(
            clientId: 'test-id',
            clientSecret: 'test-secret',
            tokenUrl: 'https://app.pipefy.com/oauth/token',
            endpoint: 'https://api.pipefy.com/graphql',
        );

        $result = $service->getCards(pipeId: 12345);

        expect($result)->toHaveCount($cardCount);

        foreach ($result as $cardIndex => $returnedCard) {
            $originalCard = $generatedCards[$cardIndex];

            foreach ($expectedFields as $field) {
                expect($returnedCard)->toHaveKey($field);
            }

            expect($returnedCard)->toEqual($originalCard);
        }
    }
});
