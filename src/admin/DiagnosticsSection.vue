<template>
	<NcSettingsSection :name="t('Embedding diagnostics')"
		:description="t('Test embeddings and inspect diagnostic results.')">
		<h4 class="mec-subheading">
			{{ t('Skip markers') }}
		</h4>
		<div class="mec-row mec-row--wrap">
			<NcTextField :model-value="fileId"
				:label="t('Nextcloud file ID')"
				inputmode="numeric"
				class="mec-row__narrow"
				@update:model-value="$emit('update:fileId', $event)" />
			<NcSelect :model-value="reason"
				:options="reasonOptions"
				:input-label="t('Any reason')"
				:clearable="true"
				class="mec-row__select"
				@update:model-value="$emit('update:reason', $event)" />
			<NcButton :disabled="busy" @click="$emit('reset-skip-markers')">
				<template #icon>
					<BackupRestoreIcon :size="20" />
				</template>
				{{ t('Reset skip markers') }}
			</NcButton>
		</div>

		<h4 class="mec-subheading">
			{{ t('Embedding diagnostics') }}
		</h4>
		<div class="mec-row mec-row--wrap">
			<input ref="imageInput"
				type="file"
				accept="image/*"
				class="mec-file-input">
			<NcButton :disabled="busy" @click="emitProbeImage">
				<template #icon>
					<ImageIcon :size="20" />
				</template>
				{{ t('Embed test image') }}
			</NcButton>
		</div>
		<div class="mec-row mec-row--wrap">
			<NcTextField :model-value="probeText"
				:label="t('Test text')"
				maxlength="2000"
				@update:model-value="$emit('update:probeText', $event)" />
			<NcButton :disabled="busy" @click="$emit('probe-text')">
				<template #icon>
					<FormatTextIcon :size="20" />
				</template>
				{{ t('Embed test text') }}
			</NcButton>
			<NcButton :disabled="busy" @click="$emit('inspect-contract')">
				<template #icon>
					<FileDocumentOutlineIcon :size="20" />
				</template>
				{{ t('Inspect contract') }}
			</NcButton>
		</div>

		<pre v-if="output" class="mec-output">{{ output }}</pre>
	</NcSettingsSection>
</template>

<script setup>
import { ref } from 'vue';
import NcButton from '@nextcloud/vue/components/NcButton';
import NcSelect from '@nextcloud/vue/components/NcSelect';
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection';
import NcTextField from '@nextcloud/vue/components/NcTextField';
import BackupRestoreIcon from 'vue-material-design-icons/BackupRestore.vue';
import FileDocumentOutlineIcon from 'vue-material-design-icons/FileDocumentOutline.vue';
import FormatTextIcon from 'vue-material-design-icons/FormatText.vue';
import ImageIcon from 'vue-material-design-icons/Image.vue';
import { t } from '../l10n.js';

defineProps({
	fileId: { type: String, default: '' },
	reason: { type: String, default: '' },
	probeText: { type: String, default: '' },
	output: { type: String, default: '' },
	busy: { type: Boolean, default: false },
});

const emit = defineEmits([
	'update:fileId',
	'update:reason',
	'update:probeText',
	'reset-skip-markers',
	'probe-image',
	'probe-text',
	'inspect-contract',
]);

/* Skip reasons are backend identifiers and deliberately not translated. */
const reasonOptions = [
	'image_too_large',
	'image_pixel_limit_exceeded',
	'unsupported_image_type',
	'invalid_image',
];

const imageInput = ref(null);

/**
 * Hand the selected file to the parent, which owns the upload.
 */
function emitProbeImage() {
	const file = imageInput.value?.files?.[0];
	if (file) {
		emit('probe-image', file);
	}
}
</script>
