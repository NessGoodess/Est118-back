<?php

namespace App\Console\Commands;

use App\Enums\ServiceAbility;
use App\Models\User;
use Illuminate\Console\Command;

class CreateServiceToken extends Command
{
    protected $signature = 'service:create-token
                            {--user= : ID or email of the service user}
                            {--ability= : Token ability}
                            {--name= : Token name}
                            {--revoke : Revoke previous tokens with the same name}
                            {--default : Create default NFC service token}';

    protected $description = 'Create a service token for external integrations (e.g., NFC reader)';

    public function handle(): int
    {
        // --- Default Mode ---
        if ($this->option('default')) {
            $user = User::where('email', 'nfc-service@est118.edu.mx')->first();

            if (!$user) {
                $this->error('Default NFC service user not found. Run the ServiceUserSeeder first.');
                return self::FAILURE;
            }

            $tokenName = 'nfc-reader';
            $ability = ServiceAbility::NFC_READER->value;

            // Revoke previous tokens automatically
            $count = $user->tokens()->where('name', $tokenName)->delete();
            $this->info("Revoked {$count} previous default token(s).");

            $token = $user->createToken($tokenName, [$ability]);

            $this->newLine();
            $this->info('Default NFC token created successfully');
            $this->line('<fg=green>' . $token->plainTextToken . '</>');
            $this->newLine();
            return self::SUCCESS;
        }

        // --- Normal Mode ---
        $userId = $this->option('user');
        $ability = $this->option('ability') ?? ServiceAbility::NFC_READER->value;
        $tokenName = $this->option('name') ?? 'service-token';

        if (!$userId) {
            $this->error('You must specify --user=ID or use --default');
            return self::FAILURE;
        }

        $user = User::where('id', $userId)
                    ->orWhere('email', $userId)
                    ->first();

        if (!$user) {
            $this->error("User {$userId} not found");
            return self::FAILURE;
        }

        if ($this->option('revoke')) {
            $count = $user->tokens()->where('name', $tokenName)->delete();
            $this->info("Revoked {$count} previous token(s) named '{$tokenName}'");
        }

        $token = $user->createToken($tokenName, [$ability]);

        $this->newLine();
        $this->info('Token created successfully');
        $this->newLine();
        $this->table(['Property', 'Value'], [
            ['User', $user->name . ' (ID: ' . $user->id . ')'],
            ['Token Name', $tokenName],
            ['Ability', $ability],
        ]);

        $this->newLine();
        $this->warn('Save this token securely. It will not be shown again:');
        $this->line('<fg=green>' . $token->plainTextToken . '</>');
        $this->newLine();

        return self::SUCCESS;
    }
}
