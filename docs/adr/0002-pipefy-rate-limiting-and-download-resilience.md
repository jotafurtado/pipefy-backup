# Rate Limiting do Pipefy e Resiliência de Download de Anexos

Durante execuções com alta concorrência de workers no Windows, foram observados erros transitórios de rede e de rate limiting ao interagir com o Pipefy:
- **`cURL error 28` (Timeout após 30s)**: o cliente HTTP padrão do Laravel interrompia downloads quando o storage do Pipefy/S3 demorava mais de 30 segundos para iniciar o stream sob carga.
- **`cURL error 56` (Connection was reset)**: o servidor remoto/Cloudflare do Pipefy encerrava abruptamente conexões TCP devido a picos de concorrência com múltiplos workers do mesmo IP.
- **Requisições `HEAD` redundantes**: cada anexo novo disparava um `HEAD` antes do `GET`, dobrando desnecessariamente o volume de requisições contra o storage.
- **Risco de bloqueio da API GraphQL**: o limite do Pipefy é de 500 requisições a cada 30 segundos (com bloqueio severo de 5 minutos caso ultrapassado).

Decidimos implementar uma camada unificada de proteção, resiliência e redução de tráfego.

## Considered Options

- **Fila síncrona com 1 worker único**: elimina concorrência, mas torna o backup de dezenas de milhares de cards inviavelmente lento.
- **Throttling cego com sleep fixo**: adiciona latência artificial mesmo quando a API e o storage estão ociosos.
- **Arquitetura adaptativa (escolhida)**:
  1. **Eliminação de HEAD desnecessário**: se o arquivo não existe em disco, pula o `HEAD` e baixa direto via `GET` (o próprio GET retorna cabeçalho `Content-Length`). Reduz 50% das chamadas HTTP no storage.
  2. **Tolerância e timeouts no download**: `connectTimeout(20)`, `timeout(180)` e `retry` exponencial (1s, 2s, 5s) para `ConnectionException` (cURL 28/56) e erros 429/5xx.
  3. **Download atômico**: arquivos são gravados em `.tmp` e renomeados apenas após sucesso (HTTP 200), evitando arquivos truncados/corrompidos no disco.
  4. **Tratamento de 429 na API GraphQL (`PipefyService`)**: respeito explícito ao cabeçalho `Retry-After` e backoff adaptativo.
  5. **Concorrência balanceada em 4 workers**: redução de 8 para 4 workers no `dev-windows` para manter alta vazão sem saturar sockets do Windows ou acionar defesas do Cloudflare.
  6. **Reprocessamento CLI direcionado**: adição da opção `--retry-errored` no comando `php artisan pipefy:backup-all` para redespachar cards que tiveram falhas pontuais de anexo.

## Consequences

- Redução imediata de 50% no volume de requisições contra `app.pipefy.com/storage/`.
- Eliminação de falhas por desconexão momentânea (`cURL 56`) ou timeout rígido de 30s (`cURL 28`).
- Integridade garantida do disco contra arquivos baixados pela metade.
- Proteção ativa contra penalidades de 5 minutos de bloqueio da API do Pipefy.
- Cards concluídos com erros transitórios de anexos podem ser reprocessados via CLI com:
  ```bash
  php artisan pipefy:backup-all --retry-errored
  ```
