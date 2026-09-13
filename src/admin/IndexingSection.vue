<template>
	<NcSettingsSection :name="t('Index settings')">
		<div class="mec-fields">
			<NcTextField :model-value="alias"
				:label="t('Photo index alias')"
				@update:model-value="$emit('update:alias', $event)" />

			<NcTextField :model-value="String(maxParallel)"
				:label="t('Maximum parallel embedding requests')"
				type="number"
				min="1"
				max="32"
				@update:model-value="$emit('update:maxParallel', Number($event))" />

			<NcTextField :model-value="String(batchSize)"
				:label="t('Image batch size')"
				type="number"
				min="1"
				max="64"
				@update:model-value="$emit('update:batchSize', Number($event))" />

			<NcTextField :model-value="String(batchParallel)"
				:label="t('Parallel image batch requests per token')"
				type="number"
				min="1"
				max="32"
				@update:model-value="$emit('update:batchParallel', Number($event))" />

			<NcTextField :model-value="String(batchTimeout)"
				:label="t('Image batch request timeout')"
				type="number"
				min="10"
				max="600"
				@update:model-value="$emit('update:batchTimeout', Number($event))" />

			<NcTextField class="mec-field--wide" :model-value="allowedMimeTypes"
				:label="t('Allowed image formats')"
				placeholder="image/jpeg, image/png, image/webp"
				spellcheck="false"
				@update:model-value="$emit('update:allowedMimeTypes', $event)" />

			<NcTextField class="mec-field--wide" :model-value="disabledMimeTypes"
				:label="t('Disabled image formats')"
				placeholder="image/heic, image/x-nikon-nef"
				spellcheck="false"
				@update:model-value="$emit('update:disabledMimeTypes', $event)" />
		</div>

		<p class="mec-hint">
			{{ t('Comma-separated MIME types. Allowed formats are indexed and shown in results; disabled formats are always skipped.') }}
		</p>

		<slot name="save" />

		<h4 class="mec-subheading">
			{{ t('Index operations') }}
		</h4>
		<div class="mec-row mec-row--wrap">
			<NcButton variant="secondary" :disabled="busy" @click="$emit('prepare-index')">
				<template #icon>
					<DatabasePlusIcon :size="20" />
				</template>
				{{ t('Prepare index') }}
			</NcButton>
			<NcButton variant="secondary" :disabled="busy" @click="$emit('activate-index')">
				<template #icon>
					<DatabaseCheckIcon :size="20" />
				</template>
				{{ t('Activate prepared index') }}
			</NcButton>
			<NcButton :variant="indexingEnabled ? 'warning' : 'primary'"
				:disabled="busy"
				@click="$emit('toggle-indexing')">
				<template #icon>
					<PlayIcon v-if="!indexingEnabled" :size="20" />
					<PauseIcon v-else :size="20" />
				</template>
				{{ indexingEnabled ? t('Disable indexing') : t('Enable indexing') }}
			</NcButton>
			</div>
		<h4 class="mec-subheading">{{ t('Existing images') }}</h4>
		<div class="mec-row mec-row--wrap">
			<NcButton variant="secondary" :disabled="busy" @click="$emit('start-backfill')">
				<template #icon>
					<ImageMultipleIcon :size="20" />
				</template>
				{{ t('Start backfill') }}
			</NcButton>
			<NcButton variant="secondary" :disabled="busy" @click="$emit('pause-backfill')">
				<template #icon>
					<PauseIcon :size="20" />
				</template>
				{{ t('Pause backfill') }}
			</NcButton>
			<NcButton variant="secondary" :disabled="busy" @click="$emit('resume-backfill')">
				<template #icon>
					<PlayIcon :size="20" />
				</template>
				{{ t('Resume backfill') }}
			</NcButton>
			<NcButton variant="secondary" :disabled="busy" @click="$emit('retry-jobs')">
				<template #icon>
					<RefreshIcon :size="20" />
				</template>
				{{ t('Retry failed jobs') }}
			</NcButton>
		</div>
	</NcSettingsSection>
</template>

<script setup>
import NcButton from '@nextcloud/vue/components/NcButton';
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection';
import NcTextField from '@nextcloud/vue/components/NcTextField';
import DatabaseCheckIcon from 'vue-material-design-icons/DatabaseCheck.vue';
import DatabasePlusIcon from 'vue-material-design-icons/DatabasePlus.vue';
import ImageMultipleIcon from 'vue-material-design-icons/ImageMultiple.vue';
import PauseIcon from 'vue-material-design-icons/Pause.vue';
import PlayIcon from 'vue-material-design-icons/Play.vue';
import RefreshIcon from 'vue-material-design-icons/Refresh.vue';
import { t } from '../l10n.js';

defineProps({
	alias: { type: String, required: true },
	maxParallel: { type: Number, required: true },
	batchSize: { type: Number, required: true },
	batchParallel: { type: Number, required: true },
	batchTimeout: { type: Number, required: true },
	allowedMimeTypes: { type: String, required: true },
	disabledMimeTypes: { type: String, required: true },
	indexingEnabled: { type: Boolean, default: false },
	busy: { type: Boolean, default: false },
});

defineEmits([
	'update:alias',
	'update:maxParallel',
	'update:batchSize',
	'update:batchParallel',
	'update:batchTimeout',
	'update:allowedMimeTypes',
	'update:disabledMimeTypes',
	'prepare-index',
	'activate-index',
	'toggle-indexing',
	'start-backfill',
	'pause-backfill',
	'resume-backfill',
	'retry-jobs',
]);
</script>
