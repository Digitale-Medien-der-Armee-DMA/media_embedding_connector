<template>
	<div class="mec-backfill">
		<p class="mec-backfill__status">
			<span :class="['mec-badge', `mec-badge--${phase.tone}`]">{{ phase.label }}</span>
			<span v-if="lastActivity" class="mec-hint">{{ lastActivity }}</span>
		</p>

		<div v-if="usersTotal > 0" class="mec-backfill__progress">
			<NcProgressBar :value="userPercent"
				:aria-label="t('Backfill progress')"
				size="medium" />
			<span class="mec-hint">
				{{ t('{done} of {total} users scanned', { done: usersDone, total: usersTotal }) }}
			</span>
		</div>

		<div class="mec-metrics">
			<div v-for="metric in metrics" :key="metric.label" class="mec-metric">
				<span class="mec-metric__value">{{ metric.value }}</span>
				<span class="mec-metric__label">{{ metric.label }}</span>
			</div>
		</div>

		<NcNoteCard v-if="stalled" type="warning">
			{{ t('No scan progress for more than 15 minutes. Check that Nextcloud cron or the worker command is running.') }}
		</NcNoteCard>

		<NcNoteCard v-if="backfill.last_error" type="warning" :heading="t('Last scan error')">
			<p>{{ describeError(backfill.last_error) }}</p>
			<p v-if="skippedRoots > 0">
				{{ t('{count} scan locations were skipped after repeated failures.', { count: skippedRoots }) }}
			</p>
		</NcNoteCard>
	</div>
</template>

<script setup>
import { computed } from 'vue';
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard';
import NcProgressBar from '@nextcloud/vue/components/NcProgressBar';
import { t } from '../l10n.js';

const STALLED_AFTER_SECONDS = 15 * 60;

const props = defineProps({
	backfill: { type: Object, default: () => ({}) },
	/** Current time in seconds; injectable for tests. */
	now: { type: Number, default: () => Math.floor(Date.now() / 1000) },
});

const counters = computed(() => props.backfill.counters ?? {});
const usersTotal = computed(() => Number(props.backfill.users_total ?? 0));
const usersDone = computed(() => Math.min(usersTotal.value, Number(props.backfill.user_offset ?? 0)));
const userPercent = computed(() => {
	if (props.backfill.status === 'completed') {
		return 100;
	}
	return usersTotal.value > 0 ? Math.floor((usersDone.value / usersTotal.value) * 100) : 0;
});
const skippedRoots = computed(() => Number(counters.value.roots_skipped ?? 0));

const running = computed(() => props.backfill.status === 'running' && !props.backfill.paused);

const phase = computed(() => {
	const status = props.backfill.status ?? 'idle';
	if (status === 'completed') {
		return { label: t('Completed'), tone: 'success' };
	}
	if (status === 'idle') {
		return { label: t('Not started'), tone: 'neutral' };
	}
	if (props.backfill.paused) {
		return { label: t('Paused'), tone: 'warning' };
	}
	if (props.backfill.throttled_at) {
		return { label: t('Waiting for the queue'), tone: 'info' };
	}
	return { label: t('Scanning'), tone: 'info' };
});

const stalled = computed(() => {
	const updatedAt = Number(props.backfill.updated_at ?? 0);
	return running.value && updatedAt > 0 && props.now - updatedAt > STALLED_AFTER_SECONDS;
});

const lastActivity = computed(() => {
	if (props.backfill.status === 'completed' && props.backfill.completed_at) {
		return t('Finished {time}', { time: formatTime(props.backfill.completed_at) });
	}
	if (props.backfill.updated_at) {
		return t('Last activity {time}', { time: formatTime(props.backfill.updated_at) });
	}
	return '';
});

const metrics = computed(() => [
	{ label: t('Images checked'), value: formatNumber(counters.value.scanned) },
	{ label: t('Queued for indexing'), value: formatNumber(counters.value.queued) },
	{ label: t('Unchanged, not queued'), value: formatNumber(counters.value.unchanged) },
	{
		label: t('Waiting in queue'),
		value: `${formatNumber(props.backfill.queued_backfill)} / ${formatNumber(props.backfill.max_queued)}`,
	},
]);

/**
 * @param {number|undefined|null} value count from the status endpoint
 * @return {string}
 */
function formatNumber(value) {
	return Number(value ?? 0).toLocaleString();
}

/**
 * @param {number} seconds Unix timestamp
 * @return {string}
 */
function formatTime(seconds) {
	return new Date(Number(seconds) * 1000).toLocaleString();
}

/**
 * @param {object} error last_error entry of the backfill status
 * @return {string}
 */
function describeError(error) {
	const summary = [error.exception_class, error.message].filter(Boolean).join(': ');
	return error.at ? `${formatTime(error.at)} · ${summary}` : summary;
}
</script>
