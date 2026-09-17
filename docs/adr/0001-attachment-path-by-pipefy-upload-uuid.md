# Attachment path keyed by Pipefy upload UUID

O esquema anterior (`attachments/{cardId}/{filename}`) causava perda silenciosa de dados em cards com attachments de mesmo filename — o segundo download sobrescrevia o primeiro. A varredura do disco encontrou 1.072 cards com filenames duplicados (5.031 entradas) e 20.005 arquivos órfãos (`_1.pdf`) resquícios de uma versão antiga que evitava colisão com sufixo.

Decidimos mudar o path para `attachments/{cardId}/{pathUuid}/{filename}`, onde `pathUuid` é o UUID extraído de `attachment.path` (`uploads/{uuid}/{filename}`), que identifica univocamente cada upload no Pipefy e é estável entre chamadas (ao contrário de `url`, que tem `expires_on` + `signature`).

## Considered Options

- **Sufixo `_1`, `_2`...** (esquema legado): preserva arquivos existentes, mas a identidade continua "nome dentro do card" — frágil, e o skip-if-exists não consegue mapear com segurança num re-run.
- **UUID do upload no path** (escolhido): elimina colisão e perda de vez; identidade estável; permite skip-if-exists confiável.
- **Dedup por hash de conteúdo**: máximo de economia de espaço, mas quebra o mapeamento 1:1 card→arquivo e adiciona complexidade desnecessária para um backup.

## Consequences

- Os 83.407 arquivos existentes precisam ser migrados do path velho para o novo. A migração é inline no `BackupCardJob` durante a re-run: para cada attachment, se o arquivo não existe no novo path mas existe no path velho `{cardId}/{filename}`, move; caso contrário, baixa.
- Cards com filenames duplicados (1.072) não têm mapeamento ambíguo resolvível — todos os attachments desses cards são re-baixados (~5.031 downloads extras num universo de 67.133).
- Após a migração, qualquer arquivo diretamente em `attachments/{cardId}/` (não dentro de um subdir de uuid) é órfão e é removido pelo comando `pipefy:cleanup-attachments`.
- O `verify-backup` precisa ser atualizado para usar a nova assinatura de `BackupPaths::attachment`.
