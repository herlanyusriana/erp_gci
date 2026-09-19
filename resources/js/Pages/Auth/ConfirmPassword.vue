<script setup lang="ts">
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
import AuthLayout from '@/Layouts/AuthLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({
    password: '',
});

const submit = () => {
    form.post(route('password.confirm'), {
        onFinish: () => {
            form.reset();
        },
    });
};
</script>

<template>
    <AuthLayout>
        <Head :title="t('account.confirmPassword')" />

        <div class="mb-8">
            <h1 class="text-2xl font-bold tracking-tight text-ink-primary">{{ t('account.confirmPassword') }}</h1>
            <p class="mt-1.5 text-sm leading-relaxed text-ink-secondary">{{ t('account.secureArea') }}</p>
        </div>

        <form @submit.prevent="submit" class="space-y-5">
            <div>
                <InputLabel for="password" :value="t('account.password')" />
                <TextInput
                    id="password"
                    type="password"
                    class="mt-1.5 block w-full"
                    v-model="form.password"
                    required
                    autocomplete="current-password"
                    autofocus
                />
                <InputError class="mt-2" :message="form.errors.password" />
            </div>

            <PrimaryButton
                class="w-full justify-center py-2.5"
                :class="{ 'opacity-25': form.processing }"
                :disabled="form.processing"
            >
                {{ t('account.confirm') }}
            </PrimaryButton>
        </form>
    </AuthLayout>
</template>
