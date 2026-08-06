<?php

it('does not expose deferred authentication endpoints', function (string $method, string $uri) {
    $this->call($method, $uri)->assertNotFound();
})->with([
    'registration form' => ['GET', '/register'],
    'registration submission' => ['POST', '/register'],
    'forgot password form' => ['GET', '/forgot-password'],
    'forgot password submission' => ['POST', '/forgot-password'],
    'reset password form' => ['GET', '/reset-password/example-token'],
    'reset password submission' => ['POST', '/reset-password'],
    'verification notice' => ['GET', '/email/verify'],
    'verification link' => ['GET', '/email/verify/1/example-hash'],
    'verification resend' => ['POST', '/email/verification-notification'],
    'password confirmation form' => ['GET', '/user/confirm-password'],
    'password confirmation submission' => ['POST', '/user/confirm-password'],
    'two factor challenge form' => ['GET', '/two-factor-challenge'],
    'two factor challenge submission' => ['POST', '/two-factor-challenge'],
    'two factor setup' => ['POST', '/user/two-factor-authentication'],
    'two factor recovery codes' => ['GET', '/user/two-factor-recovery-codes'],
    'passkey discovery' => ['GET', '/.well-known/passkey-endpoints'],
    'passkey options' => ['GET', '/passkeys/login/options'],
    'passkey login' => ['POST', '/passkeys/login'],
    'passkey registration options' => ['GET', '/user/passkeys/options'],
    'passkey registration' => ['POST', '/user/passkeys'],
]);

it('does not allow self service account deletion', function () {
    $this->delete('/settings/profile')->assertMethodNotAllowed();
});
