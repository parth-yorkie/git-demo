(function () {
	'use strict';

	function ready(fn) {
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', fn);
		} else {
			fn();
		}
	}

	function getConfig() {
		if (typeof window.RWI === 'object' && window.RWI && window.RWI.ajaxUrl) {
			return window.RWI;
		}
		var app = document.getElementById('rwi-app');
		if (!app) {
			return null;
		}
		var raw = app.getAttribute('data-rwi-config');
		if (!raw) {
			return null;
		}
		try {
			return JSON.parse(raw);
		} catch (e) {
			return null;
		}
	}

	function esc(str) {
		return String(str == null ? '' : str)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;');
	}

	function pill(kind, label) {
		return '<span class="rwi-pill rwi-pill--' + esc(kind) + '">' + esc(label) + '</span>';
	}

	function updateLabel(update) {
		switch (update) {
			case 'available':
				return 'Update available';
			case 'current':
				return 'Up to date';
			case 'ahead':
				return 'Newer than .org';
			default:
				return 'Unknown';
		}
	}

	function riskBlock(row) {
		var level = row.risk_level || 'info';
		var notes = Array.isArray(row.risk_notes) ? row.risk_notes : [];
		var html = '<div class="rwi-risk-tag rwi-risk-tag--' + esc(level) + '">' + esc(level) + '</div>';
		html += '<ul class="rwi-risk rwi-risk--' + esc(level) + '">';
		for (var i = 0; i < notes.length; i++) {
			html += '<li>' + esc(notes[i]) + '</li>';
		}
		html += '</ul>';
		return html;
	}

	function rowsHtml(items) {
		if (!items || !items.length) {
			return '<tr><td colspan="5">None found.</td></tr>';
		}
		var html = '';
		for (var i = 0; i < items.length; i++) {
			var row = items[i];
			var version = row.version || '—';
			if (row.latest && row.update === 'available') {
				version += ' → ' + row.latest;
			}
			html +=
				'<tr>' +
				'<td><span class="rwi-name">' +
				esc(row.name) +
				'</span><span class="rwi-slug">' +
				esc(row.slug || row.id || '') +
				'</span></td>' +
				'<td>' +
				pill(row.status === 'active' ? 'active' : 'inactive', row.status || 'inactive') +
				'</td>' +
				'<td>' +
				esc(version) +
				'</td>' +
				'<td>' +
				pill(row.update || 'unknown', updateLabel(row.update)) +
				'</td>' +
				'<td>' +
				riskBlock(row) +
				'</td>' +
				'</tr>';
		}
		return html;
	}

	function tableSection(title, items) {
		return (
			'<section class="rwi-section">' +
			'<h2>' +
			esc(title) +
			'</h2>' +
			'<div class="rwi-table-wrap"><table class="rwi-table"><thead><tr>' +
			'<th>Name</th><th>Status</th><th>Version</th><th>Updates</th><th>Risk notes</th>' +
			'</tr></thead><tbody>' +
			rowsHtml(items) +
			'</tbody></table></div></section>'
		);
	}

	function pluginUpdateBucket(update) {
		if (update === 'available') {
			return 'available';
		}
		if (update === 'current' || update === 'ahead') {
			return 'current';
		}
		return 'unknown';
	}

	function filterPluginsByTab(plugins, tab) {
		var out = [];
		for (var i = 0; i < plugins.length; i++) {
			var plugin = plugins[i];
			if (tab === 'inactive') {
				if (plugin.status === 'inactive') {
					out.push(plugin);
				}
				continue;
			}
			// Update tabs show active plugins only so inactive aren't duplicated across tabs.
			if (plugin.status === 'inactive') {
				continue;
			}
			if (pluginUpdateBucket(plugin.update) === tab) {
				out.push(plugin);
			}
		}
		return out;
	}

	function pluginsSection(plugins) {
		plugins = Array.isArray(plugins) ? plugins : [];
		var available = filterPluginsByTab(plugins, 'available');
		var current = filterPluginsByTab(plugins, 'current');
		var unknown = filterPluginsByTab(plugins, 'unknown');
		var inactive = filterPluginsByTab(plugins, 'inactive');
		var defaultTab = available.length
			? 'available'
			: current.length
				? 'current'
				: unknown.length
					? 'unknown'
					: 'inactive';

		function panel(id, items, active) {
			return (
				'<div class="rwi-tab-panel' +
				(active ? ' is-active' : '') +
				'" data-tab-panel="' +
				esc(id) +
				'"' +
				(active ? '' : ' hidden') +
				'>' +
				'<div class="rwi-table-wrap"><table class="rwi-table"><thead><tr>' +
				'<th>Name</th><th>Status</th><th>Version</th><th>Updates</th><th>Risk notes</th>' +
				'</tr></thead><tbody>' +
				rowsHtml(items) +
				'</tbody></table></div></div>'
			);
		}

		function tabBtn(id, label, count) {
			var on = defaultTab === id;
			return (
				'<button type="button" class="rwi-tab' +
				(on ? ' is-active' : '') +
				'" role="tab" aria-selected="' +
				(on ? 'true' : 'false') +
				'" data-tab="' +
				esc(id) +
				'">' +
				esc(label) +
				' <span class="rwi-tab__count">' +
				esc(count) +
				'</span></button>'
			);
		}

		return (
			'<section class="rwi-section rwi-section--plugins" id="rwi-plugins">' +
			'<h2>Plugins</h2>' +
			'<div class="rwi-tabs" role="tablist" aria-label="Plugin filters">' +
			tabBtn('available', 'Update available', available.length) +
			tabBtn('current', 'Up to date', current.length) +
			tabBtn('unknown', 'Unknown', unknown.length) +
			tabBtn('inactive', 'Inactive', inactive.length) +
			'</div>' +
			panel('available', available, defaultTab === 'available') +
			panel('current', current, defaultTab === 'current') +
			panel('unknown', unknown, defaultTab === 'unknown') +
			panel('inactive', inactive, defaultTab === 'inactive') +
			'</section>'
		);
	}

	function renderResults(data) {
		var site = data.site || {};
		var summary = data.summary || {};
		var html = '';

		html +=
			'<p class="rwi-meta">' +
			esc(site.name || site.url || '') +
			(site.url ? ' · ' + esc(site.url) : '') +
			(site.user ? ' · auth as ' + esc(site.user) : '') +
			'</p>';

		html +=
			'<div class="rwi-summary">' +
			'<div class="rwi-stat"><span class="rwi-stat__n">' +
			esc(summary.plugins_total || 0) +
			'</span><span class="rwi-stat__l">Plugins (' +
			esc(summary.plugins_active || 0) +
			' active / ' +
			esc(summary.plugins_inactive || 0) +
			' inactive)</span></div>' +
			'<div class="rwi-stat"><span class="rwi-stat__n">' +
			esc(summary.themes_total || 0) +
			'</span><span class="rwi-stat__l">Themes (' +
			esc(summary.themes_active || 0) +
			' active / ' +
			esc(summary.themes_inactive || 0) +
			' inactive)</span></div>' +
			'<div class="rwi-stat"><span class="rwi-stat__n">' +
			esc(summary.updates || 0) +
			'</span><span class="rwi-stat__l">Updates available</span></div>' +
			'<div class="rwi-stat"><span class="rwi-stat__n">' +
			esc(summary.high_risk || 0) +
			'</span><span class="rwi-stat__l">High-risk items</span></div>' +
			'</div>';

		html += pluginsSection(data.plugins || []);
		html += tableSection('Themes', data.themes || []);
		html +=
			'<div class="rwi-export">' +
			'<button type="button" class="rwi-export-btn" id="rwi-export-xls">' +
			esc((window.RWI && window.RWI.i18n && window.RWI.i18n.export) || 'Download Excel Report') +
			'</button>' +
			'</div>';
		return html;
	}

	function downloadBase64File(filename, mime, base64) {
		var binary = atob(base64);
		var len = binary.length;
		var bytes = new Uint8Array(len);
		for (var i = 0; i < len; i++) {
			bytes[i] = binary.charCodeAt(i);
		}
		var blob = new Blob([bytes], { type: mime || 'application/vnd.ms-excel' });
		var url = URL.createObjectURL(blob);
		var a = document.createElement('a');
		a.href = url;
		a.download = filename || 'audit_report.xls';
		document.body.appendChild(a);
		a.click();
		a.remove();
		URL.revokeObjectURL(url);
	}

	ready(function () {
		var form = document.getElementById('rwi-form');
		var status = document.getElementById('rwi-status');
		var results = document.getElementById('rwi-results');
		var submit = document.getElementById('rwi-submit');
		var progress = document.getElementById('rwi-progress');
		var progressBar = document.getElementById('rwi-progress-bar');
		var progressPct = document.getElementById('rwi-progress-pct');
		var progressLabel = document.getElementById('rwi-progress-label');
		var progressDetail = document.getElementById('rwi-progress-detail');
		var progressTrack = document.getElementById('rwi-progress-track');
		var progressSteps = document.getElementById('rwi-progress-steps');
		var config = getConfig();

		if (!form || !submit) {
			return;
		}

		function setStatus(message, kind) {
			if (!status) {
				return;
			}
			if (!message) {
				status.hidden = true;
				status.textContent = '';
				status.classList.remove('is-error', 'is-loading');
				return;
			}
			status.hidden = false;
			status.removeAttribute('hidden');
			status.style.display = 'block';
			status.textContent = message;
			status.classList.remove('is-error', 'is-loading');
			if (kind) {
				status.classList.add(kind);
			}
		}

		if (!config || !config.ajaxUrl) {
			setStatus('Scan script failed to load. Hard-refresh this page (Cmd+Shift+R) and try again.', 'is-error');
			return;
		}

		window.RWI = config;
		var running = false;
		var lastReport = null;
		var stepDefs = Array.isArray(config.steps) ? config.steps : [];

		function bindPluginTabs() {
			var root = document.getElementById('rwi-plugins');
			if (!root || root.getAttribute('data-tabs-bound') === '1') {
				return;
			}
			root.setAttribute('data-tabs-bound', '1');
			var tabs = root.querySelectorAll('.rwi-tab');
			var panels = root.querySelectorAll('.rwi-tab-panel');
			for (var i = 0; i < tabs.length; i++) {
				tabs[i].addEventListener('click', function () {
					var id = this.getAttribute('data-tab');
					for (var t = 0; t < tabs.length; t++) {
						var on = tabs[t].getAttribute('data-tab') === id;
						tabs[t].classList.toggle('is-active', on);
						tabs[t].setAttribute('aria-selected', on ? 'true' : 'false');
					}
					for (var p = 0; p < panels.length; p++) {
						var show = panels[p].getAttribute('data-tab-panel') === id;
						panels[p].classList.toggle('is-active', show);
						if (show) {
							panels[p].hidden = false;
							panels[p].removeAttribute('hidden');
						} else {
							panels[p].hidden = true;
						}
					}
				});
			}
		}

		function bindExportButton() {
			var btn = document.getElementById('rwi-export-xls');
			if (!btn || btn.getAttribute('data-bound') === '1') {
				return;
			}
			btn.setAttribute('data-bound', '1');
			btn.addEventListener('click', function () {
				if (!lastReport) {
					setStatus((config.i18n && config.i18n.exportFail) || 'Excel export failed.', 'is-error');
					return;
				}
				var i18n = config.i18n || {};
				btn.disabled = true;
				var prev = btn.textContent;
				btn.textContent = i18n.exporting || 'Preparing Excel…';

				var body = new FormData();
				body.append('action', 'rwi_export_xls');
				body.append('nonce', config.nonce || '');
				body.append('report', JSON.stringify(lastReport));

				fetch(config.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					body: body
				})
					.then(function (res) {
						return res.json();
					})
					.then(function (json) {
						if (!json || !json.success || !json.data || !json.data.content) {
							var msg =
								json && json.data && json.data.message
									? json.data.message
									: i18n.exportFail || 'Excel export failed.';
							throw new Error(msg);
						}
						downloadBase64File(json.data.filename, json.data.mime, json.data.content);
					})
					.catch(function (err) {
						setStatus(err && err.message ? err.message : i18n.exportFail || 'Excel export failed.', 'is-error');
					})
					.finally(function () {
						btn.disabled = false;
						btn.textContent = prev;
					});
			});
		}

		function showProgress() {
			if (!progress) {
				return;
			}
			progress.hidden = false;
			progress.removeAttribute('hidden');
			progress.setAttribute('aria-hidden', 'false');
			progress.style.display = 'block';
			if (progressSteps && stepDefs.length) {
				var html = '';
				for (var i = 0; i < stepDefs.length; i++) {
					html +=
						'<li class="rwi-progress__step" data-step="' +
						esc(stepDefs[i].id) +
						'"><span class="rwi-progress__dot"></span><span>' +
						esc(stepDefs[i].label) +
						'</span></li>';
				}
				progressSteps.innerHTML = html;
			}
		}

		function hideProgress() {
			if (!progress) {
				return;
			}
			progress.hidden = true;
			progress.setAttribute('aria-hidden', 'true');
		}

		function setProgress(percent, label, detail, activeStep) {
			var pct = Math.max(0, Math.min(100, Number(percent) || 0));
			if (progressBar) {
				progressBar.style.width = pct + '%';
			}
			if (progressPct) {
				progressPct.textContent = Math.round(pct) + '%';
			}
			if (progressTrack) {
				progressTrack.setAttribute('aria-valuenow', String(Math.round(pct)));
			}
			if (progressLabel && label) {
				progressLabel.textContent = label;
			}
			if (progressDetail) {
				progressDetail.textContent = detail || '';
			}
			if (progressSteps && activeStep) {
				var items = progressSteps.querySelectorAll('.rwi-progress__step');
				var seenActive = false;
				for (var i = 0; i < items.length; i++) {
					var id = items[i].getAttribute('data-step');
					items[i].classList.remove('is-active', 'is-done');
					if (id === activeStep) {
						items[i].classList.add('is-active');
						seenActive = true;
					} else if (!seenActive) {
						items[i].classList.add('is-done');
					}
				}
			}
		}

		function postStep(payload) {
			var body = new FormData();
			body.append('action', 'rwi_scan_step');
			body.append('nonce', config.nonce || '');
			body.append('step', payload.step || '');
			body.append('scan_id', payload.scan_id || '');
			body.append('offset', String(payload.offset || 0));
			if (payload.site_id) {
				body.append('site_id', payload.site_id);
			} else {
				body.append('url', payload.url || '');
				body.append('username', payload.username || '');
				body.append('app_password', payload.app_password || '');
			}

			return fetch(config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: body
			}).then(function (res) {
				return res.text().then(function (text) {
					var json = null;
					try {
						json = JSON.parse(text);
					} catch (e) {
						throw new Error(
							text && text.indexOf('<') === 0
								? 'Server returned an HTML error instead of JSON. Check that the plugin is active.'
								: (config.i18n && config.i18n.failed) || 'Scan failed.'
						);
					}
					if (!json || !json.success) {
						var msg =
							json && json.data && json.data.message
								? json.data.message
								: (config.i18n && config.i18n.failed) || 'Scan failed.';
						throw new Error(msg);
					}
					return json.data || {};
				});
			});
		}

		function runPipeline(creds) {
			var scanId = '';
			var offset = 0;

			function go(step) {
				return postStep({
					step: step,
					scan_id: scanId,
					offset: offset,
					url: creds.url,
					username: creds.username,
					app_password: creds.app_password
				}).then(function (data) {
					if (data.scan_id) {
						scanId = data.scan_id;
					}
					setProgress(
						typeof data.percent === 'number' ? data.percent : 0,
						data.label || step,
						data.detail || '',
						data.step || step
					);

					if (data.step === 'finalize' && data.report) {
						return data.report;
					}

					var next = data.next;
					if (!next) {
						throw new Error('Scan ended unexpectedly.');
					}

					if (next === data.step || (next === step && !data.done)) {
						offset = typeof data.next_offset === 'number' ? data.next_offset : offset;
					} else {
						offset = typeof data.next_offset === 'number' && !data.done ? data.next_offset : 0;
					}

					// Same enrich step continues with next_offset when not done.
					if (!data.done && (data.step === 'enrich_plugins' || data.step === 'enrich_themes')) {
						offset = typeof data.next_offset === 'number' ? data.next_offset : 0;
						return go(data.step);
					}

					offset = 0;
					return go(next);
				});
			}

			setProgress(2, (config.i18n && config.i18n.scanning) || 'Scanning site…', '', 'connect');
			return go('connect');
		}

		function startScan(event) {
			if (event) {
				event.preventDefault();
				event.stopPropagation();
			}
			if (running) {
				return;
			}

			var siteId = ((document.getElementById('rwi-site-id') || {}).value || '').trim();
			var url = ((document.getElementById('rwi-url') || {}).value || '').trim();
			var username = ((document.getElementById('rwi-username') || {}).value || '').trim();
			var password = ((document.getElementById('rwi-password') || {}).value || '').trim();
			var i18n = config.i18n || {};
			var creds;

			if (document.getElementById('rwi-site-id')) {
				if (!siteId) {
					setStatus(i18n.required || 'Please select a saved website.', 'is-error');
					return;
				}
				creds = { site_id: siteId };
			} else {
				if (!url || !username || !password) {
					setStatus(i18n.required || 'URL, username, and Application Password are required.', 'is-error');
					return;
				}
				creds = {
					url: url,
					username: username,
					app_password: password
				};
			}

			running = true;
			submit.disabled = true;
			if (results) {
				results.hidden = true;
				results.innerHTML = '';
			}
			setStatus('', null);
			showProgress();
			setProgress(1, i18n.scanning || 'Scanning site…', 'Preparing…', 'connect');

			runPipeline(creds)
				.then(function (report) {
					lastReport = report || {};
					setProgress(100, i18n.complete || 'Scan complete.', '', 'finalize');
					setStatus(i18n.complete || 'Scan complete.', null);
					if (results) {
						results.hidden = false;
						results.removeAttribute('hidden');
						results.style.display = 'block';
						results.innerHTML = renderResults(lastReport);
						bindPluginTabs();
						bindExportButton();
						results.scrollIntoView({ behavior: 'smooth', block: 'start' });
					}
					window.setTimeout(hideProgress, 900);
				})
				.catch(function (err) {
					hideProgress();
					setStatus(err && err.message ? err.message : i18n.failed || 'Scan failed.', 'is-error');
				})
				.finally(function () {
					running = false;
					submit.disabled = false;
				});
		}

		submit.addEventListener('click', startScan);
		form.addEventListener('submit', startScan);
	});
})();
