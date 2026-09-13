<template>
	<div class="mec-admin">
		<NcSettingsSection :name="t('Media Embedding Connector')"
			:description="t('Configure image search and indexing.')">
			<NcNoteCard v-if="notification" :type="notification.type">
				{{ notification.message }}
			</NcNoteCard>
		</NcSettingsSection>

		<ServiceConnectionSection v-model:origin-url="form.medialabOriginUrl"
			v-model:token="form.medialabToken"
			v-model:iterations="testIterations"
			v-model:include-text-embedding="includeTextEmbedding"
			:token-configured="state.medialab_token_configured"
			:api-base-url="state.api_base_url"
			:testing="testing.service"
			:result="results.service"
			@test="testService" />

		<ElasticsearchSection v-model:url="form.elasticsearchUrl"
			v-model:username="form.elasticsearchUsername"
			v-model:password="form.elasticsearchPassword"
			v-model:api-key="form.elasticsearchApiKey"
			v-model:verify-tls="form.elasticsearchVerifyTls"
			v-model:allow-private-networks="form.allowPrivateNetworks"
			:username-configured="state.elasticsearch_username_configured"
			:password-configured="state.elasticsearch_password_configured"
			:api-key-configured="state.elasticsearch_api_key_configured"
			:testing="testing.elasticsearch"
			:result="results.elasticsearch"
			@test="testElasticsearch" />

		<IndexingSection v-model:alias="form.indexAlias"
			v-model:max-parallel="form.maxParallelEmbedRequests"
			v-model:batch-size="form.imageBatchSize"
			v-model:batch-parallel="form.imageBatchMaxParallelRequestsPerToken"
			v-model:batch-timeout="form.imageBatchRequestTimeout"
			v-model:allowed-mime-types="form.allowedImageMimeTypes"
			v-model:disabled-mime-types="form.disabledImageMimeTypes"
			:indexing-enabled="indexingEnabled"
			:busy="busy"
			@prepare-index="prepareIndex"
			@activate-index="activateIndex"
			@toggle-indexing="toggleIndexing"
			@start-backfill="startBackfill"
			@pause-backfill="setBackfillPaused(true)"
			@resume-backfill="setBackfillPaused(false)"
			@retry-jobs="retryJobs">
		<template #save>
		<div class="mec-save mec-save--inline">
			<NcButton variant="primary" :disabled="saving" @click="save">
				<template #icon>
					<NcLoadingIcon v-if="saving" :size="20" />
					<ContentSaveIcon v-else :size="20" />
				</template>
				{{ t('Save configuration') }}
			</NcButton>
		</div>
		</template>
		</IndexingSection>

		<StatusSection :status="status"
			:search-apps="searchApps"
			:loading="statusLoading"
			@refresh="refreshStatus"
			@delete-index="deleteIndex" />

		<details class="mec-disclosure">
			<summary>{{ t('Embedding diagnostics') }}</summary>
		<DiagnosticsSection v-model:file-id="skipFileId"
			v-model:reason="skipReason"
			v-model:probe-text="probeText"
			:output="output"
			:busy="busy"
			@reset-skip-markers="resetSkipMarkers"
			@probe-image="probeImage"
			@probe-text="probeTextEmbedding"
			@inspect-contract="inspectContract" />
		</details>

		<details class="mec-disclosure">
			<summary>{{ t('Data processing and privacy') }}</summary>
			<ul class="mec-facts">
				<li v-for="fact in privacyFacts" :key="fact">
					{{ fact }}
				</li>
			</ul>
		</details>

		<NcDialog v-if="confirmation"
			:open="true"
			:name="confirmation.title"
			:message="confirmation.message"
			:buttons="confirmationButtons"
			@update:open="cancelConfirmation" />
	</div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import NcButton from '@nextcloud/vue/components/NcButton';
import NcDialog from '@nextcloud/vue/components/NcDialog';
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon';
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard';
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection';
import ContentSaveIcon from 'vue-material-design-icons/ContentSave.vue';
import DiagnosticsSection from './DiagnosticsSection.vue';
import ElasticsearchSection from './ElasticsearchSection.vue';
import IndexingSection from './IndexingSection.vue';
import ServiceConnectionSection from './ServiceConnectionSection.vue';
import StatusSection from './StatusSection.vue';
import { post, request } from '../api.js';
import { t } from '../l10n.js';

const props = defineProps({ state: { type: Object, required: true } });
const state = props.state;

const form = reactive({
	medialabOriginUrl: state.medialab_origin_url ?? '',
	medialabToken: '',
	indexAlias: state.index_alias ?? '',
	maxParallelEmbedRequests: Number(state.max_parallel_embed_requests ?? 4),
	imageBatchSize: Number(state.image_batch_size ?? 8),
	imageBatchMaxParallelRequestsPerToken: Number(state.image_batch_max_parallel_requests_per_token ?? 8),
	imageBatchRequestTimeout: Number(state.image_batch_request_timeout ?? 120),
	allowedImageMimeTypes: state.allowed_image_mime_types ?? '',
	disabledImageMimeTypes: state.disabled_image_mime_types ?? '',
	elasticsearchUrl: state.elasticsearch_url ?? '',
	elasticsearchUsername: '',
	elasticsearchPassword: '',
	elasticsearchApiKey: '',
	elasticsearchVerifyTls: Boolean(state.elasticsearch_verify_tls),
	allowPrivateNetworks: Boolean(state.allow_private_networks),
});

const testIterations = ref(5);
const includeTextEmbedding = ref(true);
const indexingEnabled = ref(Boolean(state.indexing_enabled));
const searchApps = ref(state.search_plugins?.known_apps ?? []);

const saving = ref(false);
const busy = ref(false);
const statusLoading = ref(false);
const testing = reactive({ service: false, elasticsearch: false });
const results = reactive({ service: null, elasticsearch: null });
const status = ref({});
const notification = ref(null);
const output = ref('');
const confirmation = ref(null);

const skipFileId = ref('');
const skipReason = ref('');
const probeText = ref('');

const privacyFacts = [
	t('Supported image content and text queries are sent to the configured Media Embedding Service.'),
	t('Filenames, paths, users, groups, shares, and ACL data remain in Nextcloud.'),
	t('Temporary query images are not added to Nextcloud Files or Elasticsearch.'),
	t('The Nextcloud operator is responsible for documenting the configured services, hosting locations, retention, and legal basis.'),
];

const confirmationButtons = computed(() => [
	{ label: t('Cancel'), callback: cancelConfirmation },
	{ label: confirmation.value?.confirmLabel ?? t('Confirm'), variant: 'primary', callback: acceptConfirmation },
]);

/**
 * Serialise the form the way the save and connection-test endpoints expect.
 *
 * @return {URLSearchParams}
 */
function formBody() {
	const body = new URLSearchParams();
	body.set('medialab_origin_url', form.medialabOriginUrl);
	body.set('medialab_token', form.medialabToken);
	body.set('index_alias', form.indexAlias);
	body.set('max_parallel_embed_requests', String(form.maxParallelEmbedRequests));
	body.set('image_batch_size', String(form.imageBatchSize));
	body.set('image_batch_max_parallel_requests_per_token', String(form.imageBatchMaxParallelRequestsPerToken));
	body.set('image_batch_request_timeout', String(form.imageBatchRequestTimeout));
	body.set('allowed_image_mime_types', form.allowedImageMimeTypes);
	body.set('disabled_image_mime_types', form.disabledImageMimeTypes);
	body.set('elasticsearch_config_source', 'custom');
	body.set('elasticsearch_url', form.elasticsearchUrl);
	body.set('elasticsearch_username', form.elasticsearchUsername);
	body.set('elasticsearch_password', form.elasticsearchPassword);
	body.set('elasticsearch_api_key', form.elasticsearchApiKey);
	if (form.elasticsearchVerifyTls) {
		body.set('elasticsearch_verify_tls', '1');
	}
	if (form.allowPrivateNetworks) {
		body.set('allow_private_networks', '1');
	}
	return body;
}

/**
 * Turn a failed API result into a message an administrator can act on.
 *
 * @param {{ok: boolean, payload: object}} result API result
 * @return {string}
 */
function describeFailure(result) {
	const payload = result.payload ?? {};
	if (Array.isArray(payload.errors) && payload.errors[0]) {
		return payload.errors[0].message || payload.errors[0].code;
	}
	return payload.error || t('Connection failed.');
}

/**
 * Show the raw response so diagnostics stay inspectable.
 *
 * @param {object} payload API payload
 */
function showOutput(payload) {
	output.value = JSON.stringify(payload, null, 2);
}

/**
 * Record the outcome of a connection test.
 *
 * @param {string} key which test was run
 * @param {{ok: boolean, payload: object}} result API result
 */
function recordTestResult(key, result) {
	const ok = result.ok && result.payload?.success !== false;
	results[key] = ok
		? { type: 'success', message: t('Connection successful') }
		: { type: 'error', message: describeFailure(result) };
}

/**
 * Run an action that refreshes the status afterwards.
 *
 * @param {Function} action returns the API promise
 */
async function run(action) {
	busy.value = true;
	try {
		const result = await action();
		showOutput(result.payload);
		await refreshStatus();
		return result;
	} finally {
		busy.value = false;
	}
}

/**
 * Ask for confirmation before a destructive or expensive operation.
 *
 * @param {object} options dialog copy
 * @return {Promise<boolean>}
 */
function confirm(options) {
	return new Promise((resolve) => {
		confirmation.value = { ...options, resolve };
	});
}

/** Resolve the open confirmation dialog with a rejection. */
function cancelConfirmation() {
	confirmation.value?.resolve(false);
	confirmation.value = null;
}

/** Resolve the open confirmation dialog with an approval. */
function acceptConfirmation() {
	confirmation.value?.resolve(true);
	confirmation.value = null;
}

/** Persist the configuration form. */
async function save() {
	saving.value = true;
	try {
		const result = await post(state.save_url, formBody());
		showOutput(result.payload);
		notification.value = result.ok
			? { type: 'success', message: t('Configuration saved.') }
			: { type: 'error', message: t('Configuration could not be saved.') };
		if (result.ok) {
			form.medialabToken = '';
			form.elasticsearchUsername = '';
			form.elasticsearchPassword = '';
			form.elasticsearchApiKey = '';
		}
	} finally {
		saving.value = false;
	}
}

/** Probe the Media Embedding Service with the values currently in the form. */
async function testService() {
	testing.service = true;
	results.service = null;
	try {
		const body = formBody();
		body.set('iterations', String(testIterations.value));
		body.set('include_text_embedding', includeTextEmbedding.value ? '1' : '0');
		const result = await post(state.test_medialab_url, body);
		recordTestResult('service', result);
		showOutput(result.payload);
	} finally {
		testing.service = false;
	}
}

/** Probe Elasticsearch with the values currently in the form. */
async function testElasticsearch() {
	testing.elasticsearch = true;
	results.elasticsearch = null;
	try {
		const result = await post(state.test_elasticsearch_url, formBody());
		recordTestResult('elasticsearch', result);
		showOutput(result.payload);
	} finally {
		testing.elasticsearch = false;
	}
}

/** Reload the operational status. */
async function refreshStatus() {
	statusLoading.value = true;
	try {
		const result = await request(state.status_url);
		if (result.ok) {
			status.value = result.payload;
			indexingEnabled.value = Boolean(result.payload.indexing_enabled);
		} else {
			showOutput(result.payload);
		}
	} finally {
		statusLoading.value = false;
	}
}

/** Create a new index, asking for confirmation when the model changed. */
async function prepareIndex() {
	const result = await run(() => post(state.prepare_index_url));
	const code = result.payload?.errors?.[0]?.code;
	if (!result.ok && code === 'model_change_confirmation_required') {
		const accepted = await confirm({
			title: t('Prepare index'),
			message: t('A model change was detected. Prepare a new index?'),
			confirmLabel: t('Prepare index'),
		});
		if (accepted) {
			const body = new URLSearchParams();
			body.set('confirm_model_change', '1');
			await run(() => post(state.prepare_index_url, body));
		}
	}
}

/** Point the search alias at the prepared index. */
async function activateIndex() {
	const accepted = await confirm({
		title: t('Activate prepared index'),
		message: t('Activate the prepared index for search?'),
		confirmLabel: t('Activate prepared index'),
	});
	if (accepted) {
		await run(() => post(state.activate_index_url));
	}
}

/** Enable or disable indexing. */
async function toggleIndexing() {
	const next = !indexingEnabled.value;
	const body = new URLSearchParams();
	body.set('enabled', next ? '1' : '0');
	const result = await run(() => post(state.indexing_url, body));
	if (result.ok) {
		indexingEnabled.value = next;
	}
}

/** Queue the initial backfill over existing files. */
async function startBackfill() {
	const accepted = await confirm({
		title: t('Start backfill'),
		message: t('Start indexing existing images?'),
		confirmLabel: t('Start backfill'),
	});
	if (accepted) {
		await run(() => post(state.start_backfill_url));
	}
}

/**
 * Pause or resume the running backfill.
 *
 * @param {boolean} paused desired state
 */
async function setBackfillPaused(paused) {
	const body = new URLSearchParams();
	body.set('paused', paused ? '1' : '0');
	await run(() => post(state.pause_backfill_url, body));
}

/** Requeue jobs that exhausted their retries. */
async function retryJobs() {
	await run(() => post(state.retry_jobs_url));
}

/**
 * Delete one managed Elasticsearch index.
 *
 * @param {string} name index name
 */
async function deleteIndex(name) {
	const accepted = await confirm({
		title: t('Delete'),
		message: t('Delete index {index}?', { index: name }),
		confirmLabel: t('Delete'),
	});
	if (!accepted) {
		return;
	}
	const body = new URLSearchParams();
	body.set('index_name', name);
	await run(() => post(state.delete_index_url, body));
}

/** Clear skip markers by file ID or by reason. */
async function resetSkipMarkers() {
	const body = new URLSearchParams();
	const fileId = skipFileId.value.trim();
	if (fileId !== '') {
		body.set('nextcloud_file_id', fileId);
	} else if (skipReason.value) {
		body.set('reason', skipReason.value);
	}
	await run(() => post(state.reset_skip_markers_url, body));
}

/**
 * Send one image through the Media Embedding Service.
 *
 * @param {File} file image chosen in the diagnostics section
 */
async function probeImage(file) {
	const body = new FormData();
	body.append('image', file);
	busy.value = true;
	try {
		const result = await post(state.probe_image_url, body);
		showOutput(result.payload);
	} finally {
		busy.value = false;
	}
}

/** Send one text query through the Media Embedding Service. */
async function probeTextEmbedding() {
	const body = new URLSearchParams();
	body.set('text', probeText.value.trim());
	busy.value = true;
	try {
		const result = await post(state.probe_text_url, body);
		showOutput(result.payload);
	} finally {
		busy.value = false;
	}
}

/** Fetch the service contract the connector negotiated. */
async function inspectContract() {
	busy.value = true;
	try {
		const result = await request(state.probe_url);
		showOutput(result.payload);
	} finally {
		busy.value = false;
	}
}

onMounted(refreshStatus);
</script>
