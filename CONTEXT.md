# Pipefy Backup

Backup completo dos pipes do Pipefy (cards, attachments e índice) para disco local, com acompanhamento de progresso e verificação de integridade.

## Language

**Pipe Backup**:
Backup completo de um pipe: seus cards, os attachments dos cards e o índice.
_Avoid_: pipe dump, pipe export

**Card Backup**:
Backup de um card individual com seus attachments.
_Avoid_: card dump, card export

**Batch**:
Conjunto de Pipe Backups despachados juntos pelo backup-all.
_Avoid_: run, job group, lote

**Attachment**:
Arquivo anexado a um card, baixado para disco durante o Card Backup.
_Avoid_: anexo, file

**Verification**:
Checagem de integridade dos arquivos em disco contra o índice.
_Avoid_: validation, check
