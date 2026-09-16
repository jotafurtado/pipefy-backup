<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Pipefy Backup - Status</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-100 min-h-screen">
    <div class="max-w-6xl mx-auto py-8 px-4">
        <h1 class="text-2xl font-bold text-gray-800 mb-6">Pipefy Backup - Monitoramento</h1>

        @if (!$batchId)
            <div class="bg-white rounded-lg shadow p-8 text-center text-gray-500">
                Nenhum backup encontrado.
            </div>
        @else
            <div id="summary-section" class="bg-white rounded-lg shadow p-6 mb-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-lg font-semibold text-gray-700">
                        Batch: <span class="font-mono text-sm" id="batch-id">{{ $batchId }}</span>
                    </h2>
                    <button id="retry-btn" onclick="retryFailed()"
                        class="hidden bg-red-600 hover:bg-red-700 text-white text-sm font-medium px-4 py-2 rounded transition-colors">
                        Reprocessar Falhas
                    </button>
                </div>
                <div class="mb-4">
                    <div class="flex justify-between text-sm text-gray-600 mb-1">
                        <span>Progresso</span>
                        <span id="progress-text">0 / 0</span>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-4 overflow-hidden">
                        <div id="progress-bar" class="h-4 rounded-full transition-all duration-500 bg-blue-600"
                            style="width: 0%"></div>
                    </div>
                </div>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-center">
                    <div class="bg-green-50 rounded p-3">
                        <div id="count-completed" class="text-2xl font-bold text-green-700">0</div>
                        <div class="text-xs text-green-600">Completados</div>
                    </div>
                    <div class="bg-blue-50 rounded p-3">
                        <div id="count-processing" class="text-2xl font-bold text-blue-700">0</div>
                        <div class="text-xs text-blue-600">Processando</div>
                    </div>
                    <div class="bg-yellow-50 rounded p-3">
                        <div id="count-pending" class="text-2xl font-bold text-yellow-700">0</div>
                        <div class="text-xs text-yellow-600">Pendentes</div>
                    </div>
                    <div class="bg-red-50 rounded p-3">
                        <div id="count-failed" class="text-2xl font-bold text-red-700">0</div>
                        <div class="text-xs text-red-600">Falhas</div>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-gray-600 text-left">
                        <tr>
                            <th class="px-4 py-3 font-medium">Pipe</th>
                            <th class="px-4 py-3 font-medium">Status</th>
                            <th class="px-4 py-3 font-medium">Progresso</th>
                            <th class="px-4 py-3 font-medium text-right">Cards</th>
                            <th class="px-4 py-3 font-medium text-right">Attachments</th>
                            <th class="px-4 py-3 font-medium text-right">Erros</th>
                            <th class="px-4 py-3 font-medium">Mensagem de Erro</th>
                        </tr>
                    </thead>
                    <tbody id="backups-table">
                        @foreach ($backups as $backup)
                            <tr class="border-t border-gray-100" data-id="{{ $backup->id }}">
                                <td class="px-4 py-3 font-medium text-gray-800">{{ $backup->pipe_name }}</td>
                                <td class="px-4 py-3">
                                    @switch($backup->status)
                                        @case('completed')
                                            <span
                                                class="inline-block px-2 py-0.5 text-xs font-medium rounded-full bg-green-100 text-green-800">completed</span>
                                        @break

                                        @case('processing')
                                            <span
                                                class="inline-block px-2 py-0.5 text-xs font-medium rounded-full bg-blue-100 text-blue-800">processing</span>
                                        @break

                                        @case('pending')
                                            <span
                                                class="inline-block px-2 py-0.5 text-xs font-medium rounded-full bg-yellow-100 text-yellow-800">pending</span>
                                        @break

                                        @case('failed')
                                            <span
                                                class="inline-block px-2 py-0.5 text-xs font-medium rounded-full bg-red-100 text-red-800">failed</span>
                                        @break

                                        @default
                                            <span
                                                class="inline-block px-2 py-0.5 text-xs font-medium rounded-full bg-gray-100 text-gray-800">{{ $backup->status }}</span>
                                    @endswitch
                                </td>
                                <td class="px-4 py-3 text-xs text-gray-500">
                                    @if ($backup->status === 'pending')
                                        <span class="text-yellow-600">Aguardando</span>
                                    @elseif ($backup->current_step)
                                        <span class="text-blue-600">{{ $backup->current_step }}</span>
                                    @elseif ($backup->total_cards > 0)
                                        <div class="flex gap-1 items-center flex-wrap">
                                            @php
                                                $cardCounts = $backup->backupCards->groupBy('status')->map->count();
                                                $cPending = $cardCounts->get('pending', 0);
                                                $cProcessing = $cardCounts->get('processing', 0);
                                                $cCompleted = $cardCounts->get('completed', 0);
                                                $cWithErrors = $cardCounts->get('completed_with_errors', 0);
                                                $cFailed = $cardCounts->get('failed', 0);
                                            @endphp
                                            @if ($cCompleted > 0)
                                                <span
                                                    class="inline-block px-1.5 py-0.5 rounded bg-green-100 text-green-700">{{ $cCompleted }}
                                                    ok</span>
                                            @endif
                                            @if ($cWithErrors > 0)
                                                <span
                                                    class="inline-block px-1.5 py-0.5 rounded bg-orange-100 text-orange-700">{{ $cWithErrors }}
                                                    parcial</span>
                                            @endif
                                            @if ($cProcessing > 0)
                                                <span
                                                    class="inline-block px-1.5 py-0.5 rounded bg-blue-100 text-blue-700">{{ $cProcessing }}
                                                    proc.</span>
                                            @endif
                                            @if ($cPending > 0)
                                                <span
                                                    class="inline-block px-1.5 py-0.5 rounded bg-yellow-100 text-yellow-700">{{ $cPending }}
                                                    pend.</span>
                                            @endif
                                            @if ($cFailed > 0)
                                                <span
                                                    class="inline-block px-1.5 py-0.5 rounded bg-red-100 text-red-700">{{ $cFailed }}
                                                    falha</span>
                                            @endif
                                        </div>
                                        <div class="w-full bg-gray-200 rounded-full h-1.5 mt-1">
                                            <div class="bg-green-500 h-1.5 rounded-full"
                                                style="width: {{ min(100, round((($cCompleted + $cWithErrors) / $backup->total_cards) * 100)) }}%">
                                            </div>
                                        </div>
                                    @elseif ($backup->status === 'completed')
                                        <span class="text-green-600">Concluído</span>
                                    @elseif ($backup->status === 'failed')
                                        <span class="text-red-600">Falhou</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right text-gray-600">{{ $backup->cards_count }}</td>
                                <td class="px-4 py-3 text-right text-gray-600">{{ $backup->attachments_count }}</td>
                                <td class="px-4 py-3 text-right text-gray-600">{{ $backup->errors_count }}</td>
                                <td class="px-4 py-3 text-red-600 text-xs max-w-xs truncate">
                                    {{ $backup->error_message }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <script>
        function statusBadge(status) {
            const map = {
                completed: '<span class="inline-block px-2 py-0.5 text-xs font-medium rounded-full bg-green-100 text-green-800">completed</span>',
                processing: '<span class="inline-block px-2 py-0.5 text-xs font-medium rounded-full bg-blue-100 text-blue-800">processing</span>',
                pending: '<span class="inline-block px-2 py-0.5 text-xs font-medium rounded-full bg-yellow-100 text-yellow-800">pending</span>',
                failed: '<span class="inline-block px-2 py-0.5 text-xs font-medium rounded-full bg-red-100 text-red-800">failed</span>',
            };
            return map[status] ||
                '<span class="inline-block px-2 py-0.5 text-xs font-medium rounded-full bg-gray-100 text-gray-800">' +
                status + '</span>';
        }

        function updateUI(data) {
            const s = data.summary;
            document.getElementById('count-completed').textContent = s.completed;
            document.getElementById('count-processing').textContent = s.processing;
            document.getElementById('count-pending').textContent = s.pending;
            document.getElementById('count-failed').textContent = s.failed;

            const done = s.completed + s.failed;
            const pct = s.total > 0 ? Math.round((done / s.total) * 100) : 0;
            document.getElementById('progress-bar').style.width = pct + '%';
            document.getElementById('progress-text').textContent = done + ' / ' + s.total;

            if (s.failed > 0) {
                document.getElementById('progress-bar').classList.remove('bg-blue-600');
                document.getElementById('progress-bar').classList.add('bg-yellow-500');
            } else {
                document.getElementById('progress-bar').classList.remove('bg-yellow-500');
                document.getElementById('progress-bar').classList.add('bg-blue-600');
            }

            const retryBtn = document.getElementById('retry-btn');
            if (retryBtn) {
                retryBtn.classList.toggle('hidden', s.failed === 0);
            }

            const tbody = document.getElementById('backups-table');
            if (tbody && data.backups) {
                tbody.innerHTML = data.backups.map(function(b) {
                    let progressHtml = '';
                    if (b.status === 'pending') {
                        progressHtml = '<span class="text-yellow-600">Aguardando</span>';
                    } else if (b.current_step) {
                        progressHtml = '<span class="text-blue-600">' + b.current_step + '</span>';
                    } else if (b.total_cards > 0 && b.card_status_summary) {
                        const cs = b.card_status_summary;
                        let badges = '';
                        if (cs.completed > 0) badges +=
                            '<span class="inline-block px-1.5 py-0.5 rounded bg-green-100 text-green-700">' + cs
                            .completed + ' ok</span>';
                        if (cs.completed_with_errors > 0) badges +=
                            '<span class="inline-block px-1.5 py-0.5 rounded bg-orange-100 text-orange-700">' + cs
                            .completed_with_errors + ' parcial</span>';
                        if (cs.processing > 0) badges +=
                            '<span class="inline-block px-1.5 py-0.5 rounded bg-blue-100 text-blue-700">' + cs
                            .processing + ' proc.</span>';
                        if (cs.pending > 0) badges +=
                            '<span class="inline-block px-1.5 py-0.5 rounded bg-yellow-100 text-yellow-700">' + cs
                            .pending + ' pend.</span>';
                        if (cs.failed > 0) badges +=
                            '<span class="inline-block px-1.5 py-0.5 rounded bg-red-100 text-red-700">' + cs
                            .failed + ' falha</span>';
                        progressHtml = '<div class="flex gap-1 items-center flex-wrap">' + badges + '</div>';
                        const donePct = Math.min(100, Math.round(((cs.completed + cs.completed_with_errors) / b
                            .total_cards) * 100));
                        progressHtml +=
                            '<div class="w-full bg-gray-200 rounded-full h-1.5 mt-1"><div class="bg-green-500 h-1.5 rounded-full" style="width: ' +
                            donePct + '%"></div></div>';
                    } else if (b.status === 'completed') {
                        progressHtml = '<span class="text-green-600">Concluído</span>';
                    } else if (b.status === 'failed') {
                        progressHtml = '<span class="text-red-600">Falhou</span>';
                    }

                    return '<tr class="border-t border-gray-100">' +
                        '<td class="px-4 py-3 font-medium text-gray-800">' + (b.pipe_name || '') + '</td>' +
                        '<td class="px-4 py-3">' + statusBadge(b.status) + '</td>' +
                        '<td class="px-4 py-3 text-xs text-gray-500">' + progressHtml + '</td>' +
                        '<td class="px-4 py-3 text-right text-gray-600">' + (b.cards_count || 0) + '</td>' +
                        '<td class="px-4 py-3 text-right text-gray-600">' + (b.attachments_count || 0) + '</td>' +
                        '<td class="px-4 py-3 text-right text-gray-600">' + (b.errors_count || 0) + '</td>' +
                        '<td class="px-4 py-3 text-red-600 text-xs max-w-xs truncate">' + (b.error_message || '') +
                        '</td>' +
                        '</tr>';
                }).join('');
            }
        }

        function pollStatus() {
            fetch('/api/backup-status')
                .then(function(r) {
                    return r.json();
                })
                .then(function(data) {
                    updateUI(data);
                })
                .catch(function(e) {
                    console.error('Polling error:', e);
                });
        }

        function retryFailed() {
            const btn = document.getElementById('retry-btn');
            btn.disabled = true;
            btn.textContent = 'Reprocessando...';

            fetch('/api/backup-retry', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                })
                .then(function(r) {
                    return r.json();
                })
                .then(function(data) {
                    btn.disabled = false;
                    btn.textContent = 'Reprocessar Falhas';
                    pollStatus();
                })
                .catch(function(e) {
                    btn.disabled = false;
                    btn.textContent = 'Reprocessar Falhas';
                    console.error('Retry error:', e);
                });
        }

        // Initial load + polling every 5 seconds
        pollStatus();
        setInterval(pollStatus, 5000);
    </script>
</body>

</html>
