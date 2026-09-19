<script setup lang="ts">
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
import Checkbox from '@/Components/Checkbox.vue';
import AuthLayout from '@/Layouts/AuthLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps<{
    canResetPassword?: boolean;
    status?: string;
}>();

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => {
            form.reset('password');
        },
    });
};
</script>

<template>
    <AuthLayout>
        <Head :title="t('account.login')" />

        <div v-if="status" class="mb-5 rounded-lg border border-success/30 bg-success/10 px-4 py-3 text-sm font-medium text-success">
            {{ status }}
        </div>

        <div class="mb-8">
            <h1 class="text-2xl font-bold tracking-tight text-ink-primary">{{ t('auth.loginTitle') }}</h1>
            <p class="mt-1.5 text-sm text-ink-secondary">{{ t('auth.loginSubtitle') }}</p>
        </div>

        <form @submit.prevent="submit" class="space-y-5">
            <div>
                <InputLabel for="email" :value="t('account.email')" />
                <TextInput
                    id="email"
                    type="email"
                    class="mt-1.5 block w-full"
                    v-model="form.email"
                    required
                    autofocus
                    autocomplete="username"
                    :placeholder="t('auth.emailPlaceholder')"
                />
                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <div>
                <InputLabel for="password" :value="t('account.password')" />
                <TextInput
                    id="password"
                    type="password"
                    class="mt-1.5 block w-full"
                    v-model="form.password"
                    required
                    autocomplete="current-password"
                    :placeholder="t('auth.passwordPlaceholder')"
                />
                <InputError class="mt-2" :message="form.errors.password" />
            </div>

            <div class="flex items-center justify-between gap-3">
                <label class="flex cursor-pointer items-center">
                    <Checkbox name="remember" v-model:checked="form.remember" />
                    <span class="ms-2 text-sm text-ink-secondary">{{ t('account.remember') }}</span>
                </label>

                <Link
                    v-if="canResetPassword"
                    :href="route('password.request')"
                    class="rounded-md text-sm font-medium text-primary underline-offset-2 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                >
                    {{ t('account.forgotLink') }}
                </Link>
            </div>

            <PrimaryButton
                class="w-full justify-center py-2.5"
                :class="{ 'opacity-25': form.processing }"
                :disabled="form.processing"
            >
                {{ t('account.login') }}
            </PrimaryButton>
        </form>
    </AuthLayout>
</template>
