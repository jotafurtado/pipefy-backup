<?php

use Tests\Helpers\CardGenerator;

test('json encode then decode produces equivalent card data', function () {
    for ($i = 0; $i < 100; $i++) {
        $originalCard = CardGenerator::generateRandomCard(includeAttachments: true);

        $json = json_encode($originalCard, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        expect($json)->not->toBeFalse();

        $decoded = json_decode($json, true);

        expect($decoded)->toEqual($originalCard);
    }
});
