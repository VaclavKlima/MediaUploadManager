<?php

namespace App\Console\Commands;

use App\Actions\RecoverAdministrator;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\search;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

#[Signature('admin:recover
            {--email= : Email address of the administrator to recover}
            {--enable : Re-enable a disabled administrator}
            {--force : Skip interactive confirmation}')]
#[Description('Issue a new one-time password for an administrator')]
class RecoverAdministratorCommand extends Command
{
    public function __construct(private readonly RecoverAdministrator $recoverAdministrator)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $administrator = $this->administrator();

        if (! $administrator instanceof User) {
            return self::INVALID;
        }

        $enable = (bool) $this->option('enable');
        $this->components->twoColumnDetail('Administrator', $administrator->name.' <'.$administrator->email.'>');
        $this->components->twoColumnDetail('Status', $administrator->isDisabled() ? 'Disabled' : 'Enabled');

        if ($administrator->isDisabled() && $this->isInteractive() && ! $enable) {
            $enable = confirm(
                label: 'Re-enable this administrator?',
                default: false,
                hint: 'Leaving this disabled preserves the current account status.',
            );
        }

        if ($this->isInteractive() && ! $this->option('force') && ! confirm(
            label: 'Issue a new one-time password and revoke existing sessions?',
            default: false,
        )) {
            warning('Administrator recovery cancelled. No account changes were made.');

            return self::SUCCESS;
        }

        $result = $this->recoverAdministrator->handle($administrator, $enable);

        $this->components->success('Administrator recovery credential issued.');
        warning('Store this one-time password securely. It will not be shown again.');
        table(['Credential', 'Value'], [['One-time password', $result['password']]]);

        return self::SUCCESS;
    }

    private function administrator(): ?User
    {
        $emailOption = $this->option('email');
        $email = is_string($emailOption) ? Str::of($emailOption)->trim()->lower()->value() : '';

        if (! $this->isInteractive() && $email === '') {
            $this->components->error('The --email option is required with --no-interaction.');

            return null;
        }

        if ($email !== '') {
            $user = User::query()->where('email', $email)->first();

            if (! $user instanceof User || ! $user->isAdministrator()) {
                $this->components->error('No administrator account matches that email address.');

                return null;
            }

            return $user;
        }

        if (! User::query()->where('is_administrator', true)->exists()) {
            $this->components->error('No administrator accounts are available for recovery.');

            return null;
        }

        $administratorId = search(
            label: 'Select an administrator',
            options: function (string $search): array {
                return User::query()
                    ->where('is_administrator', true)
                    ->when($search !== '', function (Builder $query) use ($search): void {
                        $query->where(function (Builder $query) use ($search): void {
                            $query
                                ->where('name', 'like', '%'.$search.'%')
                                ->orWhere('email', 'like', '%'.$search.'%');
                        });
                    })
                    ->orderBy('name')
                    ->limit(10)
                    ->get()
                    ->mapWithKeys(fn (User $user): array => [
                        $user->id => $user->name.' <'.$user->email.'>'.($user->isDisabled() ? ' — disabled' : ''),
                    ])
                    ->all();
            },
            placeholder: 'Search by name or email',
        );

        return User::query()->where('is_administrator', true)->find($administratorId);
    }

    private function isInteractive(): bool
    {
        return $this->input->isInteractive()
            && (app()->runningUnitTests() || (defined('STDIN') && stream_isatty(STDIN)));
    }
}
