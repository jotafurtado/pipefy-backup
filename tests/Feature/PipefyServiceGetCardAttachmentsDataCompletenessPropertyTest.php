<?php

use App\Services\PipefyService;
use Illuminate\Support\Facades\Http;
use Tests\Helpers\PaginationHelper;

function generateRandomAttachments(): array
{
    $faker = fake();
    $count = $faker->numberBetween(1, 10);
    $attachments = [];

    for ($i = 0; $i < $count; $i++) {
        $filename = $faker->lexify('????????').'.'.$faker->fileExtension();
        $attachments[] = [
            'url' => $faker->url(),
            'createdAt' => $faker->dateTimeThisYear()->format('c'),
            'path' => '/uploads/'.$filename,
        ];
    }

    return $attachments;
}

test('get card attachments preserves all attachment fields with equivalent values', function () {
    $expectedFields = ['url', 'createdAt', 'path', 'filename'];

    for ($i = 0; $i < 100; $i++) {
        PaginationHelper::resetHttpFake();

        $generatedAttachments = generateRandomAttachments();

        Http::fake(function ($request) use ($generatedAttachments) {
            if (str_contains($request->url(), 'oauth/token')) {
                return Http::response(['access_token' => 'fake-token', 'expires_in' => 3600]);
            }

            return Http::response([
                'data' => [
                    'card' => [
                        'attachments' => $generatedAttachments,
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

        $result = $service->getCardAttachments(cardId: 12345);

        expect($result)->toHaveCount(count($generatedAttachments));

        foreach ($result as $index => $returnedAttachment) {
            $original = $generatedAttachments[$index];

            foreach ($expectedFields as $field) {
                expect($returnedAttachment)->toHaveKey($field);
            }

            expect($returnedAttachment['url'])->toEqual($original['url']);
            expect($returnedAttachment['createdAt'])->toEqual($original['createdAt']);
            expect($returnedAttachment['path'])->toEqual($original['path']);
            expect($returnedAttachment['filename'])->toEqual(basename($original['path']));
        }
    }
});
