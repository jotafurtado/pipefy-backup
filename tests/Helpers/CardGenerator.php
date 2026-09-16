<?php

namespace Tests\Helpers;

class CardGenerator
{
    public static function generateRandomCard(bool $includeAttachments = false): array
    {
        $faker = fake();

        $assigneeCount = $faker->numberBetween(0, 3);
        $assignees = [];
        for ($i = 0; $i < $assigneeCount; $i++) {
            $assignees[] = [
                'id' => (string) $faker->unique()->numberBetween(1, 99999),
                'name' => $faker->name(),
            ];
        }

        $commentCount = $faker->numberBetween(0, 5);
        $comments = [];
        for ($i = 0; $i < $commentCount; $i++) {
            $comments[] = ['text' => $faker->sentence()];
        }

        $fieldCount = $faker->numberBetween(0, 8);
        $fields = [];
        for ($i = 0; $i < $fieldCount; $i++) {
            $fields[] = [
                'name' => $faker->words(2, true),
                'value' => $faker->sentence(),
            ];
        }

        $labelCount = $faker->numberBetween(0, 4);
        $labels = [];
        for ($i = 0; $i < $labelCount; $i++) {
            $labels[] = ['name' => $faker->word()];
        }

        $phaseCount = $faker->numberBetween(0, 5);
        $phasesHistory = [];
        for ($i = 0; $i < $phaseCount; $i++) {
            $phasesHistory[] = [
                'phase' => ['name' => $faker->words(2, true)],
                'firstTimeIn' => $faker->dateTimeThisYear()->format('c'),
                'lastTimeOut' => $faker->optional(0.7)->passthrough(
                    $faker->dateTimeThisYear()->format('c')
                ),
            ];
        }

        $card = [
            'id' => (string) $faker->unique()->numberBetween(1, 999999),
            'title' => $faker->sentence(),
            'assignees' => $assignees,
            'comments' => $comments,
            'comments_count' => count($comments),
            'current_phase' => ['name' => $faker->words(2, true)],
            'done' => $faker->boolean(),
            'due_date' => $faker->optional()->date(),
            'fields' => $fields,
            'labels' => $labels,
            'phases_history' => $phasesHistory,
            'url' => $faker->url(),
        ];

        if ($includeAttachments) {
            $attachmentCount = $faker->numberBetween(0, 4);
            $attachments = [];
            for ($i = 0; $i < $attachmentCount; $i++) {
                $attachments[] = [
                    'id' => (string) $faker->unique()->numberBetween(1, 999999),
                    'filename' => $faker->lexify('????????').'.'.$faker->fileExtension(),
                    'url' => $faker->url(),
                    'createdAt' => $faker->dateTimeThisYear()->format('c'),
                    'path' => '/uploads/'.$faker->lexify('????????').'.'.$faker->fileExtension(),
                ];
            }
            $card['attachments'] = $attachments;
        }

        return $card;
    }
}
