# Pipefy Backup

Backup completo dos pipes do Pipefy (cards, attachments e índice) para disco local, com acompanhamento de progresso e verificação de integridade.

## Visão geral

O sistema baixa todos os cards de cada pipe da organização configurada no Pipefy, salva cada card como JSON em disco, baixa os attachments de cada card e gera um índice consolidado por pipe. O backup é assíncrono (fila de jobs) e acompanha o progresso via banco de dados e API de status.

**O que é salvo:**

- **Cards**: JSON completo de cada card (campos, assignees, comments, phases_history, labels, etc.)
- **Attachments**: arquivos binários baixados de cada card
- **Índice**: `index.json` por pipe com a lista de cards, contagens e data do backup
- **content_length**: tamanho de cada attachment persistido no JSON do card (para verificação de integridade)

## Estrutura de diretórios

Todos os arquivos são salvos em `storage/app/private/pipefy-backup/`:

```
pipefy-backup/
└── {pipeId}/
    ├── cards/
    │   ├── {cardId}.json          # JSON do card com attachments[]
    │   └── index.json             # Índice do pipe (gerado ao concluir)
    └── attachments/
        └── {cardId}/
            └── {pathUuid}/        # UUID do upload no Pipefy
                └── {filename}     # Arquivo binário
```

O `{pathUuid}` é extraído de `attachment.path` (`uploads/{uuid}/{filename}`), que identifica univocamente cada upload no Pipefy. Isso evita que dois attachments de mesmo filename no mesmo card se sobrescrevam.

## Comandos Artisan

### `pipefy:backup-all`

Faz backup de todos os pipes da organização configurada.

```bash
php artisan pipefy:backup-all
```

Opções:
- `--retry` — re-despacha cards que falharam no último backup (mantém os que já foram concluídos)

### `pipefy:backup-status`

Exibe o status dos backups (quantos pipes, cards processados, pendentes, falhos, etc.).

```bash
php artisan pipefy:backup-status
```

### `pipefy:pipes`

Lista os pipes disponíveis na organização configurada.

```bash
php artisan pipefy:pipes
```

### `pipefy:verify-backup`

Verifica a integridade dos arquivos de backup de um ou todos os pipes. Checa existência do JSON do card, existência dos attachments e (quando disponível) tamanho dos attachments contra `content_length` do JSON.

```bash
php artisan pipefy:verify-backup              # verifica todos os pipes
php artisan pipefy:verify-backup --pipe=123456  # verifica um pipe específico
```

### `pipefy:cleanup-attachments`

Remove arquivos de attachment órfãos do esquema de path legado (arquivos diretamente em `attachments/{cardId}/`, não dentro de um subdir `{pathUuid}/`). Após uma re-run com migração, esses arquivos são resquícios e podem ser removidos.

```bash
php artisan pipefy:cleanup-attachments --dry-run  # lista sem remover
php artisan pipefy:cleanup-attachments             # remove os órfãos
```

## Fluxo interno

O backup é orquestrado em duas camadas de jobs:

1. **`BackupPipeJob`** — para cada pipe, itera sobre os cards via API GraphQL (paginação de 50 em 50). Salva cada card como JSON em disco imediatamente (para liberar memória) e despacha um `BackupCardJob` por card.

2. **`BackupCardJob`** — para cada card, busca a lista de attachments via API. Para cada attachment:
   - Faz HEAD request para obter `Content-Length`
   - Se o arquivo já existe no novo path com tamanho correto → **skip** (re-run)
   - Se o arquivo existe no path legado → **move** para o novo path (migração inline)
   - Caso contrário → **baixa** via GET com sink
   - Persiste `content_length` no JSON do card

   Se o card tem attachments com filenames duplicados, todos são baixados fresh (sem migração de path legado), pois o mapeamento entre arquivos legados e uploads seria ambíguo.

3. **Índice** — quando todos os cards de um pipe terminam, o `PipeBackup::recalculateStatus()` gera `index.json` com a lista de cards, contagens e data.

## Sincronia para S3

O backup local pode ser sincronizado para S3 via CLI `aws`:

```bash
aws s3 sync storage/app/private/pipefy-backup s3://seu-bucket/pipefy-backup \
  --delete \
  --exclude "*.tmp"
```

Flags úteis:
- `--delete` — remove no S3 arquivos que não existem mais localmente
- `--exclude` — ignora arquivos temporários
- `--dryrun` — preview sem transferir
- `--storage-class GLACIER` — para arquivamento de longo prazo

## Restauração / leitura de backup

Para localizar e ler os dados de um backup:

1. **Encontrar o pipe**: navegar para `pipefy-backup/{pipeId}/`
2. **Ler o índice**: `pipefy-backup/{pipeId}/cards/index.json` lista todos os cards com id e título
3. **Ler um card**: `pipefy-backup/{pipeId}/cards/{cardId}.json` contém todos os dados do card, incluindo o array `attachments` com `filename`, `path`, `url` e `content_length`
4. **Localizar attachments**: `pipefy-backup/{pipeId}/attachments/{cardId}/{pathUuid}/{filename}` — o `{pathUuid}` está no campo `path` do attachment no JSON (`uploads/{pathUuid}/{filename}`)

```bash
# Exemplo: listar todos os cards de um pipe
cat storage/app/private/pipefy-backup/123456/cards/index.json | jq '.cards[] | {id, title}'

# Exemplo: ler um card específico
cat storage/app/private/pipefy-backup/123456/cards/456789.json | jq '.title, .fields'

# Exemplo: encontrar o path de um attachment
cat storage/app/private/pipefy-backup/123456/cards/456789.json | jq '.attachments[] | {filename, path}'
```

## Configuração

Variáveis de ambiente relevantes (`.env`):

```
PIPEFY_CLIENT_ID=...
PIPEFY_CLIENT_SECRET=...
PIPEFY_TOKEN_URL=https://app.pipefy.com/oauth/token
PIPEFY_API_ENDPOINT=https://api.pipefy.com/graphql
PIPEFY_ORGANIZATION_ID=...
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database
```

## Decisões de design

- [ADR-0001](docs/adr/0001-attachment-path-by-pipefy-upload-uuid.md): Attachment path keyed by Pipefy upload UUID
- Glossário de termos em [CONTEXT.md](CONTEXT.md)
