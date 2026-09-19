<script setup lang="ts">
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
import AuthLayout from '@/Layouts/AuthLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps<{
    status?: string;
}>();

const form = useForm({
    email: '',
});

const submit = () => {
    form.post(route('password.email'));
};
</script>

<template>
    <AuthLayout>
        <Head :title="t('account.forgotPassword')" />

        <div class="mb-8">
            <h1 class="text-2xl font-bold tracking-tight text-ink-primary">{{ t('account.forgotPassword') }}</h1>
            <p class="mt-1.5 text-sm leading-relaxed text-ink-secondary">{{ t('account.forgotHelp') }}</p>
        </div>

        <div v-if="status" class="mb-5 rounded-lg border border-success/30 bg-success/10 px-4 py-3 text-sm font-medium text-success">
            {{ status }}
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
                />
                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <PrimaryButton
                class="w-full justify-center py-2.5"
                :class="{ 'opacity-25': form.processing }"
                :disabled="form.processing"
            >
                {{ t('account.resetLink') }}
            </PrimaryButton>

            <div class="text-center">
                <Link
                    :href="route('login')"
                    class="rounded-md text-sm font-medium text-primary underline-offset-2 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                >
                    {{ t('auth.backToLogin') }}
                </Link>
            </div>
        </form>
    </AuthLayout>
</template>
