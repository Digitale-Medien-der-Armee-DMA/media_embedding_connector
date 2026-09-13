<template>
	<NcSettingsSection :name="t('Operational status')">
		<div class="mec-metrics">
			<div v-for="metric in metrics" :key="metric.label" class="mec-metric">
				<span class="mec-metric__value">{{ metric.value }}</span>
				<span class="mec-metric__label">{{ metric.label }}</span>
			</div>
		</div>

		<div class="mec-row">
			<NcButton :disabled="loading" @click="$emit('refresh')">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<RefreshIcon v-else :size="20" />
				</template>
				{{ t('Refresh status') }}
			</NcButton>
		</div>

		<h4 class="mec-subheading">
			{{ t('Managed indices') }}
		</h4>
		<ul v-if="indices.length" class="mec-list">
			<li v-for="index in indices" :key="index.index" class="mec-list__row">
				<code class="mec-list__name">{{ index.index }}</code>
				<span class="mec-list__meta">{{ describeIndex(index) }}</span>
				<NcButton variant="tertiary"
					:aria-label="t('Delete')"
					:title="t('Delete')"
					@click="$emit('delete-index', index.index)">
					<template #icon>
						<DeleteIcon :size="20" />
					</template>
				</NcButton>
			</li>
		</ul>
		<NcEmptyContent v-else :name="t('Managed indices')" :description="t('No index has been prepared yet.')">
			<template #icon>
				<DatabaseOutlineIcon :size="20" />
			</template>
		</NcEmptyContent>

		<h4 class="mec-subheading">
			{{ t('Detected Nextcloud search apps') }}
		</h4>
		<ul class="mec-list">
			<li v-for="app in searchApps" :key="app.app_id" class="mec-list__row">
				<code class="mec-list__name">{{ app.app_id }}</code>
				<span class="mec-list__meta">{{ app.role }}</span>
				<span class="mec-list__meta">
					{{ t('Installed') }}: {{ app.installed ? t('yes') : t('no') }} ·
					{{ t('Enabled') }}: {{ app.enabled ? t('yes') : t('no') }}
				</span>
			</li>
		</ul>

		<h4 class="mec-subheading">
			{{ t('Recent audit events') }}
		</h4>
		<ul class="mec-list">
			<li v-for="(event, position) in recentAudit" :key="position" class="mec-list__row">
				<code class="mec-list__name">{{ event.action }}</code>
				<span class="mec-list__meta">{{ event.result }}</span>
			</li>
		</ul>
	</NcSettingsSection>
</template>

<script setup>
import { computed } from 'vue';
import NcButton from '@nextcloud/vue/components/NcButton';
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent';
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon';
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection';
import DatabaseOutlineIcon from 'vue-material-design-icons/DatabaseOutline.vue';
import DeleteIcon from 'vue-material-design-icons/Delete.vue';
import RefreshIcon from 'vue-material-design-icons/Refresh.vue';
import { t } from '../l10n.js';

const props = defineProps({
	status: { type: Object, default: () => ({}) },
	searchApps: { type: Array, default: () => [] },
	loading: { type: Boolean, default: false },
});

defineEmits(['refresh', 'delete-index']);

const metrics = computed(() => {
	const jobs = props.status.jobs ?? {};
	return [
		{ label: t('Indexing'), value: props.status.indexing_enabled ? t('Enabled') : t('Disabled') },
		{ label: t('Indexed files'), value: props.status.indexed_files ?? 0 },
		{ label: t('Queued jobs'), value: jobs.queued ?? 0 },
		{ label: t('Running jobs'), value: jobs.running ?? 0 },
		{ label: t('Failed jobs'), value: jobs.failed ?? 0 },
		{ label: t('Skipped files'), value: props.status.skip_markers?.total ?? 0 },
	];
});

const indices = computed(() => props.status.indices ?? []);
const recentAudit = computed(() => (props.status.audit ?? []).slice(0, 10));

/**
 * Describe an Elasticsearch index by document count and stored size.
 *
 * @param {object} index index entry from the status endpoint
 * @return {string}
 */
function describeIndex(index) {
	return `${index['docs.count'] ?? 0} / ${index['store.size'] ?? ''}`;
}
</script>
