<?php

namespace Tests\Helpers;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;

class PaginationHelper
{
    public static function makeCard(int $index): array
    {
        return [
            'id' => (string) ($index + 1),
            'title' => "Card {$index}",
            'assignees' => [],
            'comments' => [],
            'comments_count' => 0,
            'current_phase' => ['name' => 'Phase'],
            'done' => false,
            'due_date' => null,
            'fields' => [],
            'labels' => [],
            'phases_history' => [],
            'url' => "https://app.pipefy.com/pipes/1/cards/{$index}",
        ];
    }

    public static function buildPaginatedResponses(array $cards): array
    {
        $pages = array_chunk($cards, 50);

        if (empty($pages)) {
            $pages = [[]];
        }

        $responses = [];

        foreach ($pages as $pageIndex => $pageCards) {
            $isLastPage = $pageIndex === count($pages) - 1;
            $cursor = $isLastPage ? null : 'cursor_'.($pageIndex + 1);

            $edges = array_map(fn (array $card) => ['node' => $card], $pageCards);

            $responses[] = [
                'data' => [
                    'allCards' => [
                        'pageInfo' => [
                            'hasNextPage' => ! $isLastPage,
                            'endCursor' => $cursor,
                        ],
                        'edges' => $edges,
                    ],
                ],
            ];
        }

        return $responses;
    }

    public static function resetHttpFake(): void
    {
        $factory = new HttpFactory(app('events'));
        Http::swap($factory);
    }
}
