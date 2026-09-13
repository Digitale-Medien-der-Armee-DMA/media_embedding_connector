<template>
	<article class="mec-tile"
		:class="{ 'mec-tile--reference': result.is_reference, 'mec-tile--info-open': infoOpen }"
		:style="style">
		<a class="mec-tile__link"
			:href="result.file_url"
			:aria-label="result.name"
			@click="open">
			<img ref="image"
				class="mec-tile__image"
				:src="result.thumbnail_url"
				alt=""
				loading="lazy"
				@load="reportRatio"
				@error="reportRatio">
		</a>

		<NcButton class="mec-tile__info"
			variant="tertiary"
			:aria-label="t('Show filename and relevance')"
			:aria-expanded="infoOpen ? 'true' : 'false'"
			@click="infoOpen = !infoOpen">
			<template #icon>
				<InformationOutlineIcon :size="18" />
			</template>
		</NcButton>

		<div class="mec-tile__touch-details">
			{{ result.name }} · {{ score }}
		</div>

		<div class="mec-tile__overlay">
			<span class="mec-tile__name" :title="result.path || result.name">{{ result.name }}</span>
			<span class="mec-tile__score" :title="t('Relevance: {score}', { score })">{{ score }}</span>
			<span v-if="result.is_reference" class="mec-tile__badge">{{ t('Reference image') }}</span>

			<NcButton class="mec-tile__action"
				variant="tertiary"
				:aria-label="t('More similar images')"
				:title="t('More similar images')"
				@click="$emit('similar', result)">
				<template #icon>
					<ImageSearchOutlineIcon :size="16" />
				</template>
			</NcButton>
			<NcButton class="mec-tile__action"
				variant="tertiary"
				:href="result.file_url"
				:aria-label="t('Show {name} in Files', { name: result.name })"
				:title="t('Show in Files')">
				<template #icon>
					<FolderOutlineIcon :size="16" />
				</template>
			</NcButton>
		</div>
	</article>
</template>

<script setup>
import { computed, ref } from 'vue';
import NcButton from '@nextcloud/vue/components/NcButton';
import FolderOutlineIcon from 'vue-material-design-icons/FolderOutline.vue';
import ImageSearchOutlineIcon from 'vue-material-design-icons/ImageSearchOutline.vue';
import InformationOutlineIcon from 'vue-material-design-icons/InformationOutline.vue';
import { t } from '../l10n.js';

const props = defineProps({
	result: { type: Object, required: true },
	style: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['similar', 'open', 'ratio']);

const image = ref(null);
const infoOpen = ref(false);

const score = computed(() => {
	const value = Number(props.result.score);
	const percentage = Number.isFinite(value) ? value * 100 : 0;
	return `${percentage.toLocaleString(undefined, {
		minimumFractionDigits: 1,
		maximumFractionDigits: 1,
	})}%`;
});

/**
 * Report the natural aspect ratio so the grid can justify the row.
 */
function reportRatio() {
	const element = image.value;
	if (!element?.naturalWidth || !element?.naturalHeight) {
		emit('ratio', 1.5);
		return;
	}
	emit('ratio', Math.max(0.2, Math.min(5, element.naturalWidth / element.naturalHeight)));
}

/**
 * Hand the click to the parent, which opens the Viewer when it is available
 * and otherwise lets the browser follow the link to Files.
 *
 * @param {MouseEvent} event originating click
 */
function open(event) {
	emit('open', { result: props.result, event });
}
</script>
