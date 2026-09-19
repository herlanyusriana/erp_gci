<script setup lang="ts">
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
import { computed } from 'vue';
import AuthLayout from '@/Layouts/AuthLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps<{
    status?: string;
}>();

const form = useForm({});

const submit = () => {
    form.post(route('verification.send'));
};

const verificationLinkSent = computed(
    () => props.status === 'verification-link-sent',
);
</script>

<template>
    <AuthLayout>
        <Head :title="t('account.verifyEmail')" />

        <div class="mb-8">
            <h1 class="text-2xl font-bold tracking-tight text-ink-primary">{{ t('account.verifyEmail') }}</h1>
            <p class="mt-1.5 text-sm leading-relaxed text-ink-secondary">{{ t('account.verifyHelp') }}</p>
        </div>

        <div
            v-if="verificationLinkSent"
            class="mb-5 rounded-lg border border-success/30 bg-success/10 px-4 py-3 text-sm font-medium text-success"
        >
            {{ t('account.verificationSent') }}
        </div>

        <form @submit.prevent="submit" class="space-y-5">
            <PrimaryButton
                class="w-full justify-center py-2.5"
                :class="{ 'opacity-25': form.processing }"
                :disabled="form.processing"
            >
                {{ t('account.resendVerification') }}
            </PrimaryButton>

            <div class="text-center">
                <Link
                    :href="route('logout')"
                    method="post"
                    as="button"
                    class="rounded-md text-sm font-medium text-ink-secondary underline-offset-2 hover:text-primary hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                >
                    {{ t('account.logout') }}
                </Link>
            </div>
        </form>
    </AuthLayout>
</template>
