<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class CreateServiceToken extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'service:create-token
                            {--user= : ID of the service user}
                            {--ability=service:nfc-reader : Token ability}
                            {--name=service-token : Token name}
                            {--revoke : Revoke previous tokens with the same name}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a service token for external integrations (e.g., NFC reader)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $userId = $this->option('user');
        $ability = $this->option('ability');
        $tokenName = $this->option('name');

        if (!$userId) {
            $this->error('You must specify --user=ID');
            return self::FAILURE;
        }

        $user = User::find($userId);

        if (!$user) {
            $this->error("User ID {$userId} not found");
            return self::FAILURE;
        }

        // Revoke previous tokens if requested
        if ($this->option('revoke')) {
            $count = $user->tokens()->where('name', $tokenName)->delete();
            $this->info("Revoked {$count} previous token(s) named '{$tokenName}'");
        }

        // Create new token
        $token = $user->createToken($tokenName, [$ability]);

        $this->newLine();
        $this->info('✅ Token created successfully');
        $this->newLine();

        $this->table(['Property', 'Value'], [
            ['User', $user->name . ' (ID: ' . $user->id . ')'],
            ['Token Name', $tokenName],
            ['Ability', $ability],
        ]);

        $this->newLine();
        $this->warn('⚠️  Save this token securely. It will not be shown again:');
        $this->newLine();
        $this->line('<fg=green>' . $token->plainTextToken . '</>');
        $this->newLine();

        $this->info('Usage in FastAPI:');
        $this->line('  headers = {"Authorization": "Bearer ' . $token->plainTextToken . '"}');
        $this->newLine();

        return self::SUCCESS;
    }
}
