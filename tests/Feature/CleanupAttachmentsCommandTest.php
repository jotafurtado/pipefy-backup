<?php

use Illuminate\Support\Facades\Storage;

test('removes orphan files at cardId level and preserves files under pathUuid subdirectory', function () {
    Storage::fake('local');

    $pipeId = 12345;
    $cardId = 99999;
    $pathUuid = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

    // Orphan: file directly at cardId level (legacy path scheme)
    Storage::disk('local')->put(
        "pipefy-backup/{$pipeId}/attachments/{$cardId}/orphan.pdf",
        'legacy-content',
    );

    // Valid: file inside pathUuid subdirectory (new path scheme)
    Storage::disk('local')->put(
        "pipefy-backup/{$pipeId}/attachments/{$cardId}/{$pathUuid}/valid.pdf",
        'new-content',
    );

    $this->artisan('pipefy:cleanup-attachments')
        ->expectsOutputToContain('1 arquivo(s) órfão(s) removido(s)')
        ->assertSuccessful();

    expect(Storage::disk('local')->exists("pipefy-backup/{$pipeId}/attachments/{$cardId}/orphan.pdf"))
        ->toBeFalse('orphan file should be deleted');
    expect(Storage::disk('local')->exists("pipefy-backup/{$pipeId}/attachments/{$cardId}/{$pathUuid}/valid.pdf"))
        ->toBeTrue('valid file under pathUuid should be preserved');
});

test('dry-run lists orphan files without deleting them', function () {
    Storage::fake('local');

    $pipeId = 54321;
    $cardId = 88888;
    $pathUuid = '11111111-2222-3333-4444-555555555555';

    Storage::disk('local')->put(
        "pipefy-backup/{$pipeId}/attachments/{$cardId}/orphan1.pdf",
        'legacy-1',
    );

    Storage::disk('local')->put(
        "pipefy-backup/{$pipeId}/attachments/{$cardId}/orphan2.pdf",
        'legacy-2',
    );

    Storage::disk('local')->put(
        "pipefy-backup/{$pipeId}/attachments/{$cardId}/{$pathUuid}/keep.pdf",
        'new-content',
    );

    $this->artisan('pipefy:cleanup-attachments', ['--dry-run' => true])
        ->expectsOutputToContain('orphan1.pdf')
        ->expectsOutputToContain('orphan2.pdf')
        ->expectsOutputToContain('2 arquivo(s) órfão(s) encontrado(s)')
        ->assertSuccessful();

    expect(Storage::disk('local')->exists("pipefy-backup/{$pipeId}/attachments/{$cardId}/orphan1.pdf"))
        ->toBeTrue('dry-run must not delete orphan1');
    expect(Storage::disk('local')->exists("pipefy-backup/{$pipeId}/attachments/{$cardId}/orphan2.pdf"))
        ->toBeTrue('dry-run must not delete orphan2');
    expect(Storage::disk('local')->exists("pipefy-backup/{$pipeId}/attachments/{$cardId}/{$pathUuid}/keep.pdf"))
        ->toBeTrue('valid file must remain untouched');
});

test('reports nothing when there are no orphan files', function () {
    Storage::fake('local');

    $pipeId = 99999;
    $cardId = 77777;
    $pathUuid = '22222222-3333-4444-5555-666666666666';

    Storage::disk('local')->put(
        "pipefy-backup/{$pipeId}/attachments/{$cardId}/{$pathUuid}/valid.pdf",
        'new-content',
    );

    $this->artisan('pipefy:cleanup-attachments')
        ->expectsOutputToContain('Nenhum arquivo órfão encontrado')
        ->assertSuccessful();

    expect(Storage::disk('local')->exists("pipefy-backup/{$pipeId}/attachments/{$cardId}/{$pathUuid}/valid.pdf"))
        ->toBeTrue();
});
