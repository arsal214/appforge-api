<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class MakeVaultKey extends Command
{
    protected $signature = 'appforge:vault-key';

    protected $description = 'Generate a development master key for the credential vault.';

    public function handle(): int
    {
        $key = base64_encode(random_bytes(32));

        $this->components->info('Add this to your .env:');
        $this->line('');
        $this->line("VAULT_LOCAL_KEY={$key}");
        $this->line('');
        $this->components->warn(
            'Development only. Production uses a KMS and refuses to boot without one.'
        );

        return self::SUCCESS;
    }
}
