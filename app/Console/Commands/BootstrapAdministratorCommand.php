<?php

namespace App\Console\Commands;

use App\Actions\BootstrapAdministrator;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\form;
use function Laravel\Prompts\progress;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

#[Signature('admin:bootstrap
            {--name= : Real name of the first administrator}
            {--email= : Email address of the first administrator}')]
#[Description('Create the first administrator and issue a one-time password')]
class BootstrapAdministratorCommand extends Command
{
    public function __construct(private readonly BootstrapAdministrator $bootstrapAdministrator)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (User::query()->exists()) {
            warning('An account already exists. Administrator bootstrap was not run.');

            return self::SUCCESS;
        }

        $identity = $this->identity();

        if ($identity === null) {
            return self::INVALID;
        }

        $this->components->twoColumnDetail('Name', $identity['name']);
        $this->components->twoColumnDetail('Email', $identity['email']);

        if ($this->isInteractive() && ! confirm(
            label: 'Create this administrator?',
            default: true,
            hint: 'A one-time password will be shown after the account is created.',
        )) {
            warning('Administrator bootstrap cancelled. No account was created.');

            return self::SUCCESS;
        }

        $results = progress(
            label: 'Creating administrator',
            steps: [$identity],
            callback: fn (array $validatedIdentity): ?array => $this->bootstrapAdministrator->handle(
                $validatedIdentity['name'],
                $validatedIdentity['email'],
            ),
        );
        $result = $results[0] ?? null;

        if ($result === null) {
            warning('An account already exists. Administrator bootstrap was not run.');

            return self::SUCCESS;
        }

        $this->components->success('Administrator created.');
        warning('Store this one-time password securely. It will not be shown again.');
        table(['Credential', 'Value'], [['One-time password', $result['password']]]);

        return self::SUCCESS;
    }

    /**
     * @return array{name: string, email: string}|null
     */
    private function identity(): ?array
    {
        $nameOption = $this->option('name');
        $emailOption = $this->option('email');
        $name = is_string($nameOption) ? self::normalizeName($nameOption) : '';
        $email = is_string($emailOption) ? self::normalizeEmail($emailOption) : '';

        if (! $this->isInteractive()) {
            if ($name === '' || $email === '') {
                $this->components->error('The --name and --email options are required with --no-interaction.');

                return null;
            }

            return $this->validateIdentity($name, $email);
        }

        /** @var array{name: string, email: string} $identity */
        $identity = form()
            ->text(
                label: 'Administrator name',
                default: $name,
                required: true,
                validate: fn (string $value): ?string => $this->validationError('name', self::normalizeName($value)),
                transform: self::normalizeName(...),
                name: 'name',
            )
            ->text(
                label: 'Administrator email',
                default: $email,
                required: true,
                validate: fn (string $value): ?string => $this->validationError('email', self::normalizeEmail($value)),
                transform: self::normalizeEmail(...),
                name: 'email',
            )
            ->submit();

        return [
            'name' => self::normalizeName($identity['name']),
            'email' => self::normalizeEmail($identity['email']),
        ];
    }

    /**
     * @return array{name: string, email: string}|null
     */
    private function validateIdentity(string $name, string $email): ?array
    {
        $validator = Validator::make(
            ['name' => $name, 'email' => $email],
            self::identityRules(),
        );

        if ($validator->fails()) {
            $this->components->error($validator->errors()->first());

            return null;
        }

        return ['name' => $name, 'email' => $email];
    }

    private function validationError(string $field, string $value): ?string
    {
        $validator = Validator::make([$field => $value], [$field => self::identityRules()[$field]]);

        return $validator->errors()->first($field) ?: null;
    }

    /**
     * @return array{name: array<int, string>, email: array<int, string>}
     */
    private static function identityRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
        ];
    }

    private static function normalizeName(string $name): string
    {
        return Str::of($name)->trim()->value();
    }

    private static function normalizeEmail(string $email): string
    {
        return Str::of($email)->trim()->lower()->value();
    }

    private function isInteractive(): bool
    {
        return $this->input->isInteractive()
            && (app()->runningUnitTests() || (defined('STDIN') && stream_isatty(STDIN)));
    }
}
