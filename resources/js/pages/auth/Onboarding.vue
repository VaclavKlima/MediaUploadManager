<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { LogOut, ShieldCheck } from '@lucide/vue';
import OnboardingController from '@/actions/App/Http/Controllers/OnboardingController';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';

const props = defineProps<{
    passwordRules: string;
}>();

defineOptions({
    layout: {
        title: 'Replace your one-time password',
        description:
            'Choose a private password before continuing to the application.',
    },
});
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head title="Secure your account" />

        <Alert>
            <ShieldCheck />
            <AlertTitle>One final security step</AlertTitle>
            <AlertDescription>
                Your setup or recovery password can only be used for this first
                sign-in. Replace it now with a password you have not used for
                this account.
            </AlertDescription>
        </Alert>

        <Form
            v-bind="OnboardingController.update.form()"
            :reset-on-error="['password', 'password_confirmation']"
            class="flex flex-col gap-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-5">
                <div class="grid gap-2">
                    <Label for="password">New password</Label>
                    <PasswordInput
                        id="password"
                        name="password"
                        required
                        autofocus
                        autocomplete="new-password"
                        placeholder="New password"
                        :passwordrules="props.passwordRules"
                    />
                    <p class="text-xs text-muted-foreground">
                        {{ props.passwordRules }}. Use a unique password that is
                        not the one-time password.
                    </p>
                    <InputError :message="errors.password" />
                </div>

                <div class="grid gap-2">
                    <Label for="password_confirmation">Confirm password</Label>
                    <PasswordInput
                        id="password_confirmation"
                        name="password_confirmation"
                        required
                        autocomplete="new-password"
                        placeholder="Confirm new password"
                        :passwordrules="props.passwordRules"
                    />
                    <InputError :message="errors.password_confirmation" />
                </div>
            </div>

            <Button
                type="submit"
                class="w-full"
                :disabled="processing"
                data-test="complete-onboarding-button"
            >
                <Spinner v-if="processing" />
                Save password and continue
            </Button>
        </Form>

        <Link
            :href="logout()"
            method="post"
            as="button"
            class="inline-flex items-center justify-center gap-2 text-sm text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
        >
            <LogOut class="size-4" />
            Log out
        </Link>
    </div>
</template>
