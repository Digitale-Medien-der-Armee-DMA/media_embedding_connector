<template>
	<NcSettingsSection :name="t('Media Embedding Service')">
		<div class="mec-fields">
			<NcTextField :model-value="originUrl"
				:label="t('Media Embedding Service URL')"
				type="url"
				placeholder="https://embedding.example.com"
				@update:model-value="$emit('update:originUrl', $event)" />

			<NcPasswordField :model-value="token"
				:label="t('Media Embedding Service API token')"
				:placeholder="tokenConfigured ? t('Token configured; leave empty to keep it') : t('External API token')"
				autocomplete="new-password"
				@update:model-value="$emit('update:token', $event)" />
		</div>

		<details class="mec-disclosure mec-disclosure--inline">
			<summary>{{ t('Connection test options') }}</summary>
		<div class="mec-row mec-row--wrap">
			<NcTextField :model-value="String(iterations)"
				:label="t('Connection test iterations')"
				type="number"
				min="1"
				max="20"
				class="mec-row__narrow"
				@update:model-value="$emit('update:iterations', Number($event))" />

			<NcCheckboxRadioSwitch :model-value="includeTextEmbedding"
				@update:model-value="$emit('update:includeTextEmbedding', $event)">
				{{ t('Include text embedding') }}
			</NcCheckboxRadioSwitch>
		</div>

		<p class="mec-hint">{{ t('Resolved API URL:') }} <code>{{ apiBaseUrl }}</code></p>
		</details>
		<div class="mec-row">
			<NcButton variant="secondary" :disabled="testing" @click="$emit('test')">
				<template #icon>
					<NcLoadingIcon v-if="testing" :size="20" />
					<LanConnectIcon v-else :size="20" />
				</template>
				{{ t('Test Media Embedding Service') }}
			</NcButton>
		</div>

		<NcNoteCard v-if="result" :type="result.type">
			{{ result.message }}
		</NcNoteCard>

	</NcSettingsSection>
</template>

<script setup>
import NcButton from '@nextcloud/vue/components/NcButton';
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch';
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon';
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard';
import NcPasswordField from '@nextcloud/vue/components/NcPasswordField';
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection';
import NcTextField from '@nextcloud/vue/components/NcTextField';
import LanConnectIcon from 'vue-material-design-icons/LanConnect.vue';
import { t } from '../l10n.js';

defineProps({
	originUrl: { type: String, required: true },
	token: { type: String, required: true },
	tokenConfigured: { type: Boolean, default: false },
	iterations: { type: Number, default: 5 },
	includeTextEmbedding: { type: Boolean, default: true },
	apiBaseUrl: { type: String, default: '' },
	testing: { type: Boolean, default: false },
	result: { type: Object, default: null },
});

defineEmits([
	'update:originUrl',
	'update:token',
	'update:iterations',
	'update:includeTextEmbedding',
	'test',
]);
</script>
