<template>
	<NcSettingsSection :name="t('Elasticsearch 8.12+')"
		:description="t('Store image vectors in a separate Elasticsearch index.')">
		<div class="mec-fields">
			<NcTextField :model-value="url"
				:label="t('Elasticsearch URL')"
				type="url"
				placeholder="https://elasticsearch.example.com:9200"
				@update:model-value="$emit('update:url', $event)" />

			<NcTextField :model-value="username"
				:label="t('Username')"
				:placeholder="usernameConfigured ? t('Username configured; leave empty to keep it') : ''"
				autocomplete="username"
				@update:model-value="$emit('update:username', $event)" />

			<NcPasswordField :model-value="password"
				:label="t('Password')"
				:placeholder="passwordConfigured ? t('Password configured; leave empty to keep it') : ''"
				autocomplete="new-password"
				@update:model-value="$emit('update:password', $event)" />

			<NcPasswordField :model-value="apiKey"
				:label="t('API key')"
				:placeholder="apiKeyConfigured ? t('API key configured; leave empty to keep it') : ''"
				autocomplete="new-password"
				@update:model-value="$emit('update:apiKey', $event)" />
		</div>

		<NcCheckboxRadioSwitch :model-value="verifyTls"
			type="switch"
			@update:model-value="$emit('update:verifyTls', $event)">
			{{ t('Verify TLS certificates') }}
		</NcCheckboxRadioSwitch>
		<NcCheckboxRadioSwitch :model-value="allowPrivateNetworks"
			type="switch"
			@update:model-value="$emit('update:allowPrivateNetworks', $event)">
			{{ t('Allow configured services on private networks') }}
		</NcCheckboxRadioSwitch>

		<div class="mec-row">
			<NcButton variant="secondary" :disabled="testing" @click="$emit('test')">
				<template #icon>
					<NcLoadingIcon v-if="testing" :size="20" />
					<DatabaseSearchIcon v-else :size="20" />
				</template>
				{{ t('Test Elasticsearch') }}
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
import DatabaseSearchIcon from 'vue-material-design-icons/DatabaseSearch.vue';
import { t } from '../l10n.js';

defineProps({
	url: { type: String, required: true },
	username: { type: String, required: true },
	usernameConfigured: { type: Boolean, default: false },
	password: { type: String, required: true },
	passwordConfigured: { type: Boolean, default: false },
	apiKey: { type: String, required: true },
	apiKeyConfigured: { type: Boolean, default: false },
	verifyTls: { type: Boolean, default: true },
	allowPrivateNetworks: { type: Boolean, default: false },
	testing: { type: Boolean, default: false },
	result: { type: Object, default: null },
});

defineEmits([
	'update:url',
	'update:username',
	'update:password',
	'update:apiKey',
	'update:verifyTls',
	'update:allowPrivateNetworks',
	'test',
]);
</script>
