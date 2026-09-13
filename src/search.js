import { createApp } from 'vue';
import { loadState } from '@nextcloud/initial-state';
import SearchApp from './search/SearchApp.vue';
import './search/search.css';
import { APP_ID } from './l10n.js';

createApp(SearchApp, { state: loadState(APP_ID, 'search') })
	.mount('#media-embedding-connector-search');
