<?php

use App\Backup\VerifyCardBackup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('verification detects json and attachments correctly', function () {
    Storage::fake('local');

    $verifier = app(VerifyCardBackup::class);

    for ($i = 0; $i < 100; $i++) {
        $pipeId = fake()->numberBetween(100000, 9999999);
        $cardId = fake()->numberBetween(10000, 999999);
        $cardTitle = fake()->sentence();

        $scenario = fake()->randomElement(['valid_complete', 'valid_missing_attachment', 'missing_json', 'invalid_json']);

        $attachmentCount = fake()->numberBetween(0, 3);
        $attachments = [];

        for ($a = 0; $a < $attachmentCount; $a++) {
            $attachments[] = [
                'filename' => fake()->unique()->word().'.'.fake()->fileExtension(),
                'url' => fake()->url(),
                'path' => '/uploads/'.fake()->word(),
                'createdAt' => fake()->dateTimeThisYear()->format('Y-m-d\TH:i:s'),
            ];
        }

        fake()->unique(true);

        $cardData = [
            'id' => (string) $cardId,
            'title' => $cardTitle,
            'attachments' => $attachments,
        ];

        if ($scenario === 'missing_json') {
            $result = $verifier->verify($pipeId, $cardId, $cardTitle);

            expect($result->ok)->toBeFalse();
            expect($result->issues)->not->toBeEmpty();

            continue;
        }

        if ($scenario === 'invalid_json') {
            Storage::disk('local')->put(
                "pipefy-backup/{$pipeId}/cards/{$cardId}.json",
                'not-valid-json{{{',
            );

            $result = $verifier->verify($pipeId, $cardId, $cardTitle);

            expect($result->ok)->toBeFalse();
            expect($result->issues)->not->toBeEmpty();

            continue;
        }

        Storage::disk('local')->put(
            "pipefy-backup/{$pipeId}/cards/{$cardId}.json",
            json_encode($cardData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        );

        if ($scenario === 'valid_complete') {
            foreach ($attachments as $att) {
                Storage::disk('local')->put(
                    "pipefy-backup/{$pipeId}/attachments/{$cardId}/{$att['filename']}",
                    'file-content',
                );
            }

            $result = $verifier->verify($pipeId, $cardId, $cardTitle);

            expect($result->ok)->toBeTrue();
            expect($result->issues)->toBeEmpty();
        } else {
            if (count($attachments) > 0) {
                $skipIndex = fake()->numberBetween(0, count($attachments) - 1);

                foreach ($attachments as $idx => $att) {
                    if ($idx === $skipIndex) {
                        continue;
                    }
                    Storage::disk('local')->put(
                        "pipefy-backup/{$pipeId}/attachments/{$cardId}/{$att['filename']}",
                        'file-content',
                    );
                }

                $result = $verifier->verify($pipeId, $cardId, $cardTitle);

                expect($result->ok)->toBeFalse();
                expect($result->issues)->not->toBeEmpty();
            } else {
                $result = $verifier->verify($pipeId, $cardId, $cardTitle);

                expect($result->ok)->toBeTrue();
            }
        }
    }
});
