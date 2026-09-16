<?php

namespace App\Console\Commands;

use App\Exceptions\PipefyApiException;
use App\Services\PipefyService;
use Illuminate\Console\Command;

use function Laravel\Prompts\select;

class PipefyPipesCommand extends Command
{
    protected $signature = 'pipefy:pipes';

    protected $description = 'Lista os pipes disponíveis no Pipefy';

    public function handle(PipefyService $pipefy): int
    {
        try {
            $defaultOrgId = config('services.pipefy.organization_id');

            if ($defaultOrgId) {
                $selectedId = (int) $defaultOrgId;
                $this->info("Usando organização padrão: {$selectedId}");
            } else {
                $organizations = $pipefy->getOrganizations();

                if (empty($organizations)) {
                    $this->warn('Nenhuma organização encontrada.');

                    return self::SUCCESS;
                }

                $options = [];
                foreach ($organizations as $org) {
                    $options[$org['id']] = $org['name'];
                }

                $selectedId = (int) select(
                    label: 'Selecione uma organização:',
                    options: $options,
                );
            }

            $pipes = $pipefy->getPipes($selectedId);

            if (empty($pipes)) {
                $this->info('Nenhum pipe encontrado nesta organização.');

                return self::SUCCESS;
            }

            $this->table(['ID', 'Nome'], array_map(
                fn (array $pipe) => [$pipe['id'], $pipe['name']],
                $pipes,
            ));

            return self::SUCCESS;
        } catch (PipefyApiException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
