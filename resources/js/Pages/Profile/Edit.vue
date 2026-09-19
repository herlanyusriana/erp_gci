<script setup lang="ts">
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
import AppLayout from '@/Layouts/AppLayout.vue';
import BackButton from '@/Components/BackButton.vue';
import DeleteUserForm from './Partials/DeleteUserForm.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
import { Head } from '@inertiajs/vue3';

defineProps<{
    mustVerifyEmail?: boolean;
    status?: string;
}>();
</script>

<template>
    <Head :title="t('account.profile')" />

    <AppLayout>
        <BackButton :href="route('launcher')" class="mb-4" />

        <div class="mb-6">
            <h1 class="text-2xl font-bold tracking-tight text-ink-primary">{{ t('account.profile') }}</h1>
            <p class="mt-1 text-sm text-ink-secondary">{{ t('account.profileHelp') }}</p>
        </div>

        <div class="max-w-3xl space-y-6">
            <div class="rounded-xl border border-borderline bg-surface p-6">
                <UpdateProfileInformationForm
                    :must-verify-email="mustVerifyEmail"
                    :status="status"
                    class="max-w-xl"
                />
            </div>

            <div class="rounded-xl border border-borderline bg-surface p-6">
                <UpdatePasswordForm class="max-w-xl" />
            </div>

            <div class="rounded-xl border border-borderline bg-surface p-6">
                <DeleteUserForm class="max-w-xl" />
            </div>
        </div>
    </AppLayout>
</template>
