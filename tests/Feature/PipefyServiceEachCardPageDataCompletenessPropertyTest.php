<?php

use App\Services\PipefyService;
use Illuminate\Support\Facades\Http;
use Tests\Helpers\CardGenerator;
use Tests\Helpers\PaginationHelper;

test('each card page preserves all card fields with equivalent values', function () {
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

        $allCards = [];

        $total = $service->eachCardPage(
            pipeId: 12345,
            onPage: function (array $pageCards) use (&$allCards): void {
                array_push($allCards, ...$pageCards);
            },
        );

        expect($total)->toBe($cardCount);
        expect($allCards)->toHaveCount($cardCount);

        foreach ($allCards as $cardIndex => $returnedCard) {
            $originalCard = $generatedCards[$cardIndex];

            foreach ($expectedFields as $field) {
                expect($returnedCard)->toHaveKey($field);
            }

            expect($returnedCard)->toEqual($originalCard);
        }
    }
});
