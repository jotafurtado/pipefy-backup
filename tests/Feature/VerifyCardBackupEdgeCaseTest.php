<?php

use App\Jobs\VerifyCardBackupJob;
use Illuminate\Support\Facades\Storage;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('verify fails when card json is missing', function () {
    Storage::fake('local');

    $job = new VerifyCardBackupJob(12345, 99999, 'Card Ausente');
    $result = $job->handle();

    expect($result['ok'])->toBeFalse();
    expect($result['issues'])->toHaveCount(1);
    expect($result['issues'][0])->toContain('JSON ausente');
});

test('verify fails when card json is invalid', function () {
    Storage::fake('local');

    Storage::disk('local')->put('pipefy-backup/12345/cards/99999.json', '{invalid json!!!');

    $job = new VerifyCardBackupJob(12345, 99999, 'Card Inválido');
    $result = $job->handle();

    expect($result['ok'])->toBeFalse();
    expect($result['issues'])->toHaveCount(1);
    expect($result['issues'][0])->toContain('JSON inválido');
});

test('verify fails when card json is empty object', function () {
    Storage::fake('local');

    Storage::disk('local')->put('pipefy-backup/12345/cards/99999.json', '{}');

    $job = new VerifyCardBackupJob(12345, 99999, 'Card Vazio');
    $result = $job->handle();

    expect($result['ok'])->toBeFalse();
    expect($result['issues'][0])->toContain('JSON inválido');
});

test('verify fails when attachment is missing', function () {
    Storage::fake('local');

    $cardData = [
        'id' => '99999',
        'title' => 'Card com Attachment',
        'attachments' => [
            ['filename' => 'doc.pdf', 'url' => 'https://example.com/doc.pdf', 'path' => '/uploads/doc.pdf'],
        ],
    ];

    Storage::disk('local')->put(
        'pipefy-backup/12345/cards/99999.json',
        json_encode($cardData),
    );

    $job = new VerifyCardBackupJob(12345, 99999, 'Card com Attachment');
    $result = $job->handle();

    expect($result['ok'])->toBeFalse();
    expect($result['issues'])->toHaveCount(1);
    expect($result['issues'][0])->toContain('Attachment ausente');
    expect($result['issues'][0])->toContain('doc.pdf');
});

test('command fails when index json is missing', function () {
    Storage::fake('local');

    $this->artisan('pipefy:verify-backup', ['pipe_id' => 12345])
        ->expectsOutputToContain('index.json não encontrado')
        ->assertExitCode(1);
});

test('command succeeds with valid complete backup', function () {
    Storage::fake('local');

    $pipeId = 12345;
    $cardData = [
        'id' => '100',
        'title' => 'Card OK',
        'attachments' => [
            ['filename' => 'file.pdf', 'url' => 'https://example.com/file.pdf', 'path' => '/uploads/file.pdf'],
        ],
    ];

    Storage::disk('local')->put(
        "pipefy-backup/{$pipeId}/cards/index.json",
        json_encode(['cards' => [['id' => 100, 'title' => 'Card OK']]]),
    );

    Storage::disk('local')->put(
        "pipefy-backup/{$pipeId}/cards/100.json",
        json_encode($cardData),
    );

    Storage::disk('local')->put(
        "pipefy-backup/{$pipeId}/attachments/100/file.pdf",
        'content',
    );

    $this->artisan('pipefy:verify-backup', ['pipe_id' => $pipeId])
        ->expectsOutputToContain("Pipe {$pipeId}:")
        ->assertExitCode(0);
});
