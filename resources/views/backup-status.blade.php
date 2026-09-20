<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Pipefy Backup — Acompanhamento da fila</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-variant-numeric: tabular-nums; }
        .scroll-thin::-webkit-scrollbar { height: 8px; width: 8px; }
        .scroll-thin::-webkit-scrollbar-thumb { background: #334155; border-radius: 8px; }
        .row-detail[hidden] { display: none; }
        tr.pipe-row { cursor: pointer; }
        tr.pipe-row:hover td { background-color: #0f172a; }
    </style>
</head>
<body class="bg-slate-950 text-slate-200 min-h-screen">
<div class="max-w-7xl mx-auto py-6 px-4 space-y-6">

    <!-- Header -->
    <header class="flex flex-wrap items-center gap-3 justify-between">
        <div class="flex items-center gap-3">
            <div class="w-9 h-9 rounded-xl bg-emerald-500/15 border border-emerald-500/30 flex items-center justify-center">
                <span class="w-2.5 h-2.5 rounded-full bg-emerald-400 animate-pulse" id="live-dot"></span>
            </div>
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Pipefy Backup</h1>
                <p class="text-xs text-slate-400">Acompanhamento da fila e do batch em tempo real</p>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2 text-xs">
            <span class="px-2.5 py-1.5 rounded-lg bg-slate-900 border border-slate-800 text-slate-300">
                Batch <span class="font-mono text-slate-100" id="batch-id">{{ $batch_id ?? '—' }}</span>
            </span>
            <span class="px-2.5 py-1.5 rounded-lg bg-slate-900 border border-slate-800 text-slate-300">
                Fila <span class="font-mono text-slate-100" id="queue-conn">{{ $queue['connection'] ?? '—' }} / {{ $queue['name'] ?? 'default' }}</span>
            </span>
            <a href="/pulse" class="px-2.5 py-1.5 rounded-lg bg-slate-900 border border-slate-800 text-sky-300 hover:border-sky-500/50 transition-colors">Pulse</a>
            <button id="pause-btn" onclick="togglePause()" class="px-2.5 py-1.5 rounded-lg bg-slate-900 border border-slate-800 text-slate-300 hover:border-slate-600 transition-colors">Pausar</button>
            <span class="px-2.5 py-1.5 rounded-lg bg-slate-900 border border-slate-800 text-slate-400">atualiza em <span id="countdown" class="text-slate-100 font-semibold">4</span>s</span>
            <button id="retry-btn" onclick="retryFailed()" class="hidden px-3 py-1.5 rounded-lg bg-rose-600 hover:bg-rose-500 text-white font-medium transition-colors">Reprocessar falhas</button>
            <button id="retry-errors-btn" onclick="retryErrored()" class="hidden px-3 py-1.5 rounded-lg bg-amber-600 hover:bg-amber-500 text-white font-medium transition-colors">Reprocessar parciais</button>
        </div>
    </header>

    @if (!$batch_id)
        <div class="rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-200">
            Nenhum batch de backup encontrado ainda. A fila abaixo continua ao vivo — dispare um <span class="font-mono">pipefy:backup-all</span> para começar.
        </div>
    @endif

    <!-- KPI cards -->
    <section class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-5 gap-3">
        <div class="rounded-xl bg-slate-900 border border-slate-800 p-4 xl:col-span-2">
            <div class="flex items-baseline justify-between mb-1">
                <span class="text-xs uppercase tracking-wide text-slate-400">Progresso geral · cards</span>
                <span class="text-2xl font-bold text-white" id="k-progress-pct">{{ $cards['percent'] ?? 0 }}%</span>
            </div>
            <div class="w-full bg-slate-800 rounded-full h-3 overflow-hidden mb-2">
                <div id="k-progress-bar" class="h-3 rounded-full transition-all duration-500 bg-emerald-500" style="width: {{ $cards['percent'] ?? 0 }}%"></div>
            </div>
            <p class="text-xs text-slate-400"><span id="k-progress-text" class="text-slate-200 font-semibold">{{ $cards['done'] ?? 0 }} / {{ $cards['total'] ?? 0 }}</span> cards concluídos · <span id="k-remaining">{{ $cards['remaining'] ?? 0 }}</span> restantes</p>
        </div>
        <div class="rounded-xl bg-slate-900 border border-slate-800 p-4">
            <span class="text-xs uppercase tracking-wide text-slate-400">Fila agora</span>
            <div class="text-2xl font-bold text-white mt-1"><span id="q-pending">{{ $queue['pending'] ?? '—' }}</span> <span class="text-sm font-normal text-slate-400">pendentes</span></div>
            <p class="text-xs text-slate-400 mt-1"><span id="q-failed" class="text-rose-300 font-semibold">{{ $queue['failed'] ?? '—' }}</span> com falha · <span id="q-processing">{{ $cards['processing'] ?? 0 }}</span> cards processando</p>
        </div>
        <div class="rounded-xl bg-slate-900 border border-slate-800 p-4">
            <span class="text-xs uppercase tracking-wide text-slate-400">Ritmo · ETA</span>
            <div class="text-2xl font-bold text-white mt-1"><span id="k-throughput">{{ $cards['throughput_per_min'] ?? 0 }}</span> <span class="text-sm font-normal text-slate-400">cards/min</span></div>
            <p class="text-xs text-slate-400 mt-1">ETA <span id="k-eta" class="text-slate-200 font-semibold">—</span></p>
            <canvas id="spark" width="220" height="36" class="w-full h-9 mt-2"></canvas>
        </div>
        <div class="rounded-xl bg-slate-900 border border-slate-800 p-4">
            <span class="text-xs uppercase tracking-wide text-slate-400">Pipes do batch</span>
            <div class="flex gap-3 mt-2 text-center">
                <div><div id="count-completed" class="text-xl font-bold text-emerald-300">{{ $summary['completed'] ?? 0 }}</div><div class="text-[11px] text-slate-400">ok</div></div>
                <div><div id="count-processing" class="text-xl font-bold text-sky-300">{{ $summary['processing'] ?? 0 }}</div><div class="text-[11px] text-slate-400">ativos</div></div>
                <div><div id="count-pending" class="text-xl font-bold text-amber-300">{{ $summary['pending'] ?? 0 }}</div><div class="text-[11px] text-slate-400">fila</div></div>
                <div><div id="count-failed" class="text-xl font-bold text-rose-300">{{ $summary['failed'] ?? 0 }}</div><div class="text-[11px] text-slate-400">falha</div></div>
            </div>
            <p class="text-xs text-slate-500 mt-2">iniciado <span id="batch-started">{{ $batch['started_at'] ?? '—' }}</span></p>
        </div>
        <div class="rounded-xl bg-slate-900 border border-slate-800 p-4">
            <span class="text-xs uppercase tracking-wide text-slate-400">Anexos &amp; erros</span>
            <div class="text-2xl font-bold text-white mt-1" id="k-attachments">{{ $cards['attachments_total'] ?? 0 }}</div>
            <p class="text-xs text-slate-400 mt-1">anexos baixados · <span id="k-errors" class="text-rose-300 font-semibold">{{ $cards['errors_total'] ?? 0 }}</span> erros · <span id="k-cards-failed" class="text-rose-300 font-semibold">{{ $cards['failed'] ?? 0 }}</span> cards falhos</p>
        </div>
    </section>

    <!-- Main grid -->
    <section class="grid grid-cols-1 xl:grid-cols-3 gap-3">
        <!-- Pipes -->
        <div class="xl:col-span-2 rounded-xl bg-slate-900 border border-slate-800 overflow-hidden">
            <div class="flex flex-wrap items-center gap-2 p-4 border-b border-slate-800">
                <h2 class="text-sm font-semibold text-white mr-auto">Pipes <span id="pipes-count" class="text-slate-400 font-normal"></span></h2>
                <input id="filter-search" oninput="renderPipes()" placeholder="Buscar pipe..." class="text-xs bg-slate-950 border border-slate-800 rounded-lg px-2.5 py-1.5 w-40 placeholder:text-slate-600 focus:outline-none focus:border-sky-500/60">
                <select id="filter-status" onchange="renderPipes()" class="text-xs bg-slate-950 border border-slate-800 rounded-lg px-2 py-1.5 focus:outline-none">
                    <option value="">Todos os status</option>
                    <option value="processing">Processando</option>
                    <option value="pending">Pendentes</option>
                    <option value="completed">Concluídos</option>
                    <option value="completed_with_errors">Parciais</option>
                    <option value="failed">Falhas</option>
                </select>
                <select id="filter-sort" onchange="renderPipes()" class="text-xs bg-slate-950 border border-slate-800 rounded-lg px-2 py-1.5 focus:outline-none">
                    <option value="progress">Menor progresso</option>
                    <option value="errors">Mais erros</option>
                    <option value="name">Nome A–Z</option>
                </select>
                <label class="text-xs text-slate-400 flex items-center gap-1.5"><input id="filter-errors" type="checkbox" onchange="renderPipes()" class="accent-rose-500"> só com erros</label>
            </div>
            <div class="overflow-x-auto scroll-thin">
                <table class="w-full text-sm min-w-[720px]">
                    <thead class="text-left text-[11px] uppercase tracking-wide text-slate-500 border-b border-slate-800">
                        <tr>
                            <th class="px-4 py-2.5 font-medium">Pipe</th>
                            <th class="px-4 py-2.5 font-medium">Status</th>
                            <th class="px-4 py-2.5 font-medium w-56">Progresso</th>
                            <th class="px-4 py-2.5 font-medium text-right">Cards</th>
                            <th class="px-4 py-2.5 font-medium text-right">Anexos</th>
                            <th class="px-4 py-2.5 font-medium text-right">Erros</th>
                        </tr>
                    </thead>
                    <tbody id="pipes-tbody">
                        <tr><td colspan="6" class="px-4 py-8 text-center text-slate-500 text-xs">Carregando...</td></tr>
                    </tbody>
                </table>
            </div>
            <p class="px-4 py-2.5 text-[11px] text-slate-500 border-t border-slate-800">Clique numa linha para ver o detalhe (etapa atual, contadores por status e mensagem de erro).</p>
        </div>

        <!-- Errors -->
        <div class="rounded-xl bg-slate-900 border border-slate-800 overflow-hidden flex flex-col">
            <div class="p-4 border-b border-slate-800 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-white">Erros recentes</h2>
                <span id="errors-count" class="text-[11px] px-2 py-0.5 rounded-full bg-slate-800 text-slate-300">0</span>
            </div>
            <ul id="errors-list" class="divide-y divide-slate-800/80 overflow-y-auto scroll-thin max-h-[560px] text-xs">
                <li class="px-4 py-6 text-center text-slate-500">Nenhum erro até agora. Bom sinal.</li>
            </ul>
        </div>
    </section>

    <footer class="flex flex-wrap gap-2 items-center justify-between text-[11px] text-slate-500 pb-4">
        <span>Dica: acompanhe workers e jobs lentos no <a href="/pulse" class="text-sky-400 hover:underline">Pulse</a> enquanto esta página mostra o avanço do backup.</span>
        <span id="last-update">última atualização —</span>
    </footer>
</div>

<script>
    let paused = false;
    let countdown = 4;
    let lastData = null;
    let doneHistory = [];
    const POLL_SECONDS = 4;

    const STATUS_LABEL = {
        completed: 'Concluído',
        completed_with_errors: 'Parcial',
        processing: 'Processando',
        pending: 'Pendente',
        failed: 'Falha',
    };
    const BADGE = {
        completed: 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30',
        completed_with_errors: 'bg-orange-500/15 text-orange-300 border-orange-500/30',
        processing: 'bg-sky-500/15 text-sky-300 border-sky-500/30',
        pending: 'bg-amber-500/15 text-amber-300 border-amber-500/30',
        failed: 'bg-rose-500/15 text-rose-300 border-rose-500/30',
    };

    function esc(s) {
        return String(s ?? '').replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function fmtNum(n) {
        if (n === null || n === undefined) return '—';
        return Number(n).toLocaleString('pt-BR');
    }
    function fmtEta(sec) {
        if (sec === null || sec === undefined) return '—';
        if (sec < 60) return sec + 's';
        const m = Math.round(sec / 60);
        if (m < 60) return m + ' min';
        return Math.floor(m / 60) + 'h ' + (m % 60) + 'min';
    }
    function fmtTime(iso) {
        if (!iso) return '—';
        try { return new Date(iso).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit', second: '2-digit' }); }
        catch (e) { return iso; }
    }
    function badge(status) {
        const cls = BADGE[status] || 'bg-slate-500/15 text-slate-300 border-slate-500/30';
        return '<span class="inline-block px-2 py-0.5 text-[11px] font-medium rounded-full border ' + cls + '">' + esc(STATUS_LABEL[status] || status) + '</span>';
    }

    function togglePause() {
        paused = !paused;
        document.getElementById('pause-btn').textContent = paused ? 'Retomar' : 'Pausar';
        document.getElementById('live-dot').classList.toggle('animate-pulse', !paused);
        if (!paused) { countdown = 0; }
    }

    function cardChips(cs) {
        const parts = [];
        if (cs.completed > 0) parts.push('<span class="text-emerald-300">' + cs.completed + ' ok</span>');
        if (cs.completed_with_errors > 0) parts.push('<span class="text-orange-300">' + cs.completed_with_errors + ' parciais</span>');
        if (cs.processing > 0) parts.push('<span class="text-sky-300">' + cs.processing + ' proc.</span>');
        if (cs.pending > 0) parts.push('<span class="text-amber-300">' + cs.pending + ' pend.</span>');
        if (cs.failed > 0) parts.push('<span class="text-rose-300">' + cs.failed + ' falha</span>');
        return parts.length ? parts.join(' · ') : '<span class="text-slate-500">sem cards</span>';
    }

    function applyFilters(backups) {
        const q = document.getElementById('filter-search').value.trim().toLowerCase();
        const st = document.getElementById('filter-status').value;
        const onlyErr = document.getElementById('filter-errors').checked;
        const sort = document.getElementById('filter-sort').value;
        let list = backups.filter(function (b) {
            if (q && !(b.pipe_name || '').toLowerCase().includes(q)) return false;
            if (st && b.status !== st) return false;
            if (onlyErr && !(b.errors_count > 0 || b.status === 'failed' || (b.card_status_summary && b.card_status_summary.failed > 0))) return false;
            return true;
        });
        list.sort(function (a, b) {
            if (sort === 'errors') return (b.errors_count || 0) - (a.errors_count || 0);
            if (sort === 'name') return String(a.pipe_name || '').localeCompare(String(b.pipe_name || ''));
            return (a.progress_percent || 0) - (b.progress_percent || 0);
        });
        return list;
    }

    function renderPipes() {
        if (!lastData || !lastData.backups) return;
        const list = applyFilters(lastData.backups);
        document.getElementById('pipes-count').textContent = '· ' + list.length + ' de ' + lastData.backups.length;
        const tbody = document.getElementById('pipes-tbody');
        if (!list.length) {
            tbody.innerHTML = '<tr><td colspan="6" class="px-4 py-8 text-center text-slate-500 text-xs">Nada por aqui com esses filtros.</td></tr>';
            return;
        }
        tbody.innerHTML = list.map(function (b, i) {
            const cs = b.card_status_summary || {};
            const detail = []
                .concat(b.current_step ? ['<div><span class="text-slate-500">Etapa:</span> <span class="text-sky-300">' + esc(b.current_step) + '</span></div>'] : [])
                .concat(['<div class="mt-1">' + cardChips(cs) + '</div>'])
                .concat(b.error_message ? ['<div class="mt-1 text-rose-300 break-words">' + esc(b.error_message) + '</div>'] : [])
                .concat(['<div class="mt-1 text-slate-500">início ' + fmtTime(b.started_at) + ' · fim ' + fmtTime(b.completed_at) + '</div>'])
                .join('');
            return '<tr class="pipe-row border-t border-slate-800/70" onclick="toggleDetail(' + i + ')">' +
                '<td class="px-4 py-3 font-medium text-slate-100">' + esc(b.pipe_name || ('#' + b.pipe_id)) + '<div class="text-[11px] font-normal text-slate-500 font-mono">pipe ' + esc(b.pipe_id) + '</div></td>' +
                '<td class="px-4 py-3">' + badge(b.status) + '</td>' +
                '<td class="px-4 py-3"><div class="flex justify-between text-[11px] text-slate-400 mb-1"><span>' + (b.cards_done || 0) + '/' + (b.cards_total || 0) + '</span><span>' + (b.progress_percent || 0) + '%</span></div>' +
                '<div class="w-full bg-slate-800 rounded-full h-1.5 overflow-hidden"><div class="h-1.5 rounded-full ' + (b.status === 'failed' ? 'bg-rose-500' : 'bg-emerald-500') + '" style="width: ' + (b.progress_percent || 0) + '%"></div></div></td>' +
                '<td class="px-4 py-3 text-right text-slate-300">' + fmtNum(b.cards_count) + '</td>' +
                '<td class="px-4 py-3 text-right text-slate-300">' + fmtNum(b.attachments_count) + '</td>' +
                '<td class="px-4 py-3 text-right ' + (b.errors_count > 0 ? 'text-rose-300 font-semibold' : 'text-slate-500') + '">' + fmtNum(b.errors_count) + '</td></tr>' +
                '<tr class="row-detail border-t border-slate-800/50" id="detail-' + i + '" hidden><td colspan="6" class="px-4 py-3 text-xs bg-slate-950/60">' + detail + '</td></tr>';
        }).join('');
    }

    function toggleDetail(i) {
        const el = document.getElementById('detail-' + i);
        if (el) el.hidden = !el.hidden;
    }

    function renderErrors(errors) {
        const ul = document.getElementById('errors-list');
        document.getElementById('errors-count').textContent = errors.length + (errors.length >= 10 ? '+' : '');
        if (!errors.length) {
            ul.innerHTML = '<li class="px-4 py-6 text-center text-slate-500">Nenhum erro até agora. Bom sinal.</li>';
            return;
        }
        ul.innerHTML = errors.map(function (e) {
            const where = esc(e.pipe_name || '') + (e.card_id ? ' · card ' + esc(e.card_id) : '') + (e.filename ? ' · ' + esc(e.filename) : '');
            return '<li class="px-4 py-2.5"><div class="text-slate-300 font-medium truncate">' + where + '</div>' +
                '<div class="text-rose-300/90 break-words mt-0.5">' + esc(e.message || e.type || 'erro') + '</div>' +
                '<div class="text-slate-500 mt-0.5">' + fmtTime(e.created_at) + '</div></li>';
        }).join('');
    }

    function drawSpark() {
        const cv = document.getElementById('spark');
        if (!cv) return;
        const ctx = cv.getContext('2d');
        ctx.clearRect(0, 0, cv.width, cv.height);
        if (doneHistory.length < 2) return;
        const max = Math.max.apply(null, doneHistory.concat([1]));
        const min = Math.min.apply(null, doneHistory);
        const span = Math.max(max - min, 1);
        ctx.beginPath();
        doneHistory.forEach(function (v, i) {
            const x = (i / (doneHistory.length - 1)) * (cv.width - 4) + 2;
            const y = cv.height - 3 - ((v - min) / span) * (cv.height - 8);
            if (i === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
        });
        ctx.strokeStyle = '#34d399';
        ctx.lineWidth = 1.5;
        ctx.stroke();
    }

    function updateUI(data) {
        lastData = data;
        const c = data.cards || {};
        const q = data.queue || {};
        const s = data.summary || {};

        if (data.batch_id) document.getElementById('batch-id').textContent = data.batch_id;
        document.getElementById('queue-conn').textContent = (q.connection || '—') + ' / ' + (q.name || 'default');
        document.getElementById('q-pending').textContent = fmtNum(q.pending);
        document.getElementById('q-failed').textContent = fmtNum(q.failed);
        document.getElementById('q-processing').textContent = fmtNum(c.processing);

        document.getElementById('k-progress-pct').textContent = (c.percent || 0) + '%';
        document.getElementById('k-progress-bar').style.width = (c.percent || 0) + '%';
        document.getElementById('k-progress-text').textContent = fmtNum(c.done) + ' / ' + fmtNum(c.total);
        document.getElementById('k-remaining').textContent = fmtNum(c.remaining);
        document.getElementById('k-throughput').textContent = c.throughput_per_min ?? 0;
        document.getElementById('k-eta').textContent = fmtEta(c.eta_seconds);
        document.getElementById('k-attachments').textContent = fmtNum(c.attachments_total);
        document.getElementById('k-errors').textContent = fmtNum(c.errors_total);
        document.getElementById('k-cards-failed').textContent = fmtNum(c.failed);

        document.getElementById('count-completed').textContent = s.completed || 0;
        document.getElementById('count-processing').textContent = s.processing || 0;
        document.getElementById('count-pending').textContent = s.pending || 0;
        document.getElementById('count-failed').textContent = s.failed || 0;
        if (data.batch && data.batch.started_at) document.getElementById('batch-started').textContent = fmtTime(data.batch.started_at);

        const retryBtn = document.getElementById('retry-btn');
        retryBtn.classList.toggle('hidden', !((s.failed || 0) > 0 || (c.failed || 0) > 0));
        const retryErrorsBtn = document.getElementById('retry-errors-btn');
        const erroredCount = (c.completed_with_errors || 0);
        retryErrorsBtn.classList.toggle('hidden', !(erroredCount > 0));
        if (!retryErrorsBtn.disabled) {
            retryErrorsBtn.textContent = 'Reprocessar parciais' + (erroredCount > 0 ? ' (' + erroredCount + ')' : '');
        }

        doneHistory.push(c.done || 0);
        if (doneHistory.length > 40) doneHistory.shift();
        drawSpark();

        renderPipes();
        renderErrors(data.errors_recent || []);
        document.getElementById('last-update').textContent = 'última atualização ' + new Date().toLocaleTimeString('pt-BR');
    }

    function pollStatus() {
        fetch('/api/backup-status')
            .then(function (r) { return r.json(); })
            .then(updateUI)
            .catch(function (e) { console.error('Polling error:', e); });
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
            .then(function (r) { return r.json(); })
            .then(function () { pollStatus(); })
            .catch(function (e) { console.error('Retry error:', e); })
            .finally(function () {
                btn.disabled = false;
                btn.textContent = 'Reprocessar falhas';
            });
    }

    function retryErrored() {
        const btn = document.getElementById('retry-errors-btn');
        btn.disabled = true;
        btn.textContent = 'Reprocessando...';
        fetch('/api/backup-retry', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
            body: JSON.stringify({ scope: 'errored' }),
        })
            .then(function (r) { return r.json(); })
            .then(function () { pollStatus(); })
            .catch(function (e) { console.error('Retry error:', e); })
            .finally(function () {
                btn.disabled = false;
                pollStatus();
            });
    }

    setInterval(function () {
        if (paused || document.hidden) return;
        countdown -= 1;
        if (countdown <= 0) { pollStatus(); countdown = POLL_SECONDS; }
        document.getElementById('countdown').textContent = countdown;
    }, 1000);

    pollStatus();
</script>
</body>
</html>
