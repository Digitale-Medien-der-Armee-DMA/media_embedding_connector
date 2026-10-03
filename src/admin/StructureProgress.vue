<template>
	<div class="mec-backfill">
		<p><strong>{{ t('Update search index · Schema {from} → {to}', { from: migration.schema_from ?? 1, to: migration.schema_to ?? 2 }) }}</strong></p>
		<p>{{ t('Status: {status}', { status: phase }) }}</p>
		<p>{{ t('Processed: {done} / {total}', { done: Number(migration.processed ?? 0).toLocaleString(), total: Number(migration.total ?? 0).toLocaleString() }) }}</p>
		<div class="mec-row mec-row--wrap">
			<NcButton variant="secondary" :disabled="busy" @click="$emit('download-errors')">{{ t('Download errors') }}</NcButton>
			<NcButton variant="secondary" :disabled="busy" @click="$emit('control', 'restart')">{{ t('Restart') }}</NcButton>
			<NcButton variant="secondary" :disabled="busy || migration.paused" @click="$emit('control', 'pause')">{{ t('Pause') }}</NcButton>
			<NcButton variant="secondary" :disabled="busy || (!migration.paused && !migration.has_errors && migration.status !== 'failed' && migration.status !== 'idle')" @click="$emit('control', 'resume')">{{ t('Resume') }}</NcButton>
		</div>
	</div>
</template>

<script setup>
import { computed } from 'vue';
import NcButton from '@nextcloud/vue/components/NcButton';
import { t } from '../l10n.js';
const props = defineProps({ migration: { type: Object, required: true }, busy: Boolean });
defineEmits(['control', 'download-errors']);
const phase = computed(() => {
	if (props.migration.paused) return t('Paused');
	if (props.migration.restart_pending) return t('Adding structure metadata');
	if (props.migration.status === 'failed' || props.migration.has_errors) return t('Update failed');
	if (props.migration.repair_pending) return t('Adding structure metadata');
	if (props.migration.status === 'completed') return t('Completed');
	if (props.migration.status === 'idle') return t('Not started');
	return t('Adding structure metadata');
});
</script>
