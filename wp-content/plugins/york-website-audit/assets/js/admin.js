(function () {
	'use strict';

	function ready(fn) {
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', fn);
		} else {
			fn();
		}
	}

	ready(function () {
		var form = document.getElementById('ywa-run-form');
		var btn = document.getElementById('ywa-run-btn');
		var status = document.getElementById('ywa-status');
		var progress = document.getElementById('ywa-progress');
		var progressPct = document.getElementById('ywa-progress-pct');
		var progressBar = document.getElementById('ywa-progress-bar');
		var progressMeta = document.getElementById('ywa-progress-meta');
		var progressTrack = document.getElementById('ywa-progress-track');
		var modal = document.getElementById('ywa-modal');
		var modalTitle = document.getElementById('ywa-modal-title');
		var modalBody = document.getElementById('ywa-modal-body');
		var lastFocus = null;
		var running = false;

		function i18n(key, fallback) {
			if (typeof window.YWA !== 'undefined' && window.YWA.i18n && window.YWA.i18n[key]) {
				return window.YWA.i18n[key];
			}
			return fallback;
		}

		function sprintf(template) {
			var args = Array.prototype.slice.call(arguments, 1);
			return String(template).replace(/%(?:\d+\$)?[sd]/g, function () {
				return args.length ? args.shift() : '';
			});
		}

		function setProgress(percent, meta) {
			if (!progress || !progressBar) {
				return;
			}
			var pct = Math.max(0, Math.min(100, Number(percent) || 0));
			progress.classList.add('is-active');
			progress.setAttribute('aria-hidden', 'false');
			progressBar.style.width = pct + '%';
			if (progressPct) {
				progressPct.textContent = Math.round(pct) + '%';
			}
			if (progressTrack) {
				progressTrack.setAttribute('aria-valuenow', String(Math.round(pct)));
			}
			if (progressMeta) {
				progressMeta.textContent = meta || '';
			}
		}

		function hideProgress() {
			if (!progress) {
				return;
			}
			progress.classList.remove('is-active');
			progress.setAttribute('aria-hidden', 'true');
		}

		function getExcludedIds() {
			var checks = document.querySelectorAll('.ywa-exclude__check:checked');
			var ids = [];
			for (var i = 0; i < checks.length; i++) {
				if (checks[i].value) {
					ids.push(checks[i].value);
				}
			}
			return ids;
		}

		function getQueue() {
			var all = window.YWA && window.YWA.categories ? window.YWA.categories : [];
			var excluded = getExcludedIds();
			var excludedMap = {};
			for (var e = 0; e < excluded.length; e++) {
				excludedMap[excluded[e]] = true;
			}
			var queue = [];
			for (var i = 0; i < all.length; i++) {
				var cat = all[i];
				if (cat && cat.id && !excludedMap[cat.id]) {
					queue.push(cat);
				}
			}
			return queue;
		}

		function getFrontTarget() {
			if (!window.YWA || !window.YWA.frontend) {
				return null;
			}
			var siteEl = document.getElementById('ywa-site-id');
			if (siteEl) {
				return {
					site_id: (siteEl.value || '').trim()
				};
			}
			var urlEl = document.getElementById('ywa-target-url');
			var userEl = document.getElementById('ywa-target-user');
			var passEl = document.getElementById('ywa-target-pass');
			if (!urlEl || !userEl || !passEl) {
				return null;
			}
			return {
				url: (urlEl.value || '').trim(),
				user: (userEl.value || '').trim(),
				pass: (passEl.value || '').trim()
			};
		}

		function runStep(index, queue) {
			if (typeof window.YWA === 'undefined') {
				return Promise.reject(new Error('Audit script failed to load. Hard-refresh and try again.'));
			}

			var total = queue.length;
			if (!total) {
				return Promise.reject(new Error(i18n('noneLeft', 'Select at least one category to audit (uncheck fewer exclusions).')));
			}

			var current = queue[index] || {};
			var meta = sprintf(
				i18n('progress', '%s of %s · %s'),
				String(index + 1),
				String(total),
				current.title || current.id || '…'
			);
			setProgress((index / total) * 100, meta);

			var body = new FormData();
			body.append('action', 'ywa_run_audit_step');
			body.append('nonce', window.YWA.nonce);
			body.append('index', String(index));
			body.append('category_id', current.id || '');
			body.append(
				'queue',
				queue
					.map(function (c) {
						return c.id;
					})
					.join(',')
			);

			var target = getFrontTarget();
			if (target) {
				if (target.site_id) {
					body.append('site_id', target.site_id);
				} else {
					body.append('target_url', target.url);
					body.append('target_user', target.user);
					body.append('target_app_password', target.pass);
				}
			}

			return fetch(window.YWA.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: body
			})
				.then(function (res) {
					return res.text().then(function (text) {
						var json;
						try {
							json = JSON.parse(text);
						} catch (e) {
							throw new Error('Unexpected server response while auditing.');
						}
						return json;
					});
				})
				.then(function (json) {
					if (!json || !json.success) {
						var message = json && json.data && json.data.message ? json.data.message : i18n('failed', 'Audit failed.');
						throw new Error(message);
					}
					var data = json.data || {};
					var doneMeta = sprintf(
						i18n('progress', '%s of %s · %s'),
						String(index + 1),
						String(total),
						current.title || current.id || '…'
					);
					setProgress(data.percent || (((index + 1) / total) * 100), doneMeta);
					if (data.done) {
						return data;
					}
					return runStep(typeof data.next_index === 'number' ? data.next_index : index + 1, queue);
				});
		}

		function startAudit(event) {
			if (event) {
				event.preventDefault();
				event.stopPropagation();
			}
			if (running) {
				return;
			}
			if (!btn) {
				return;
			}

			if (window.YWA && window.YWA.frontend) {
				var target = getFrontTarget();
				var targetOk = target && (target.site_id || (target.url && target.user && target.pass));
				if (!targetOk) {
					if (status) {
						status.classList.add('is-visible', 'is-error');
						status.classList.remove('is-loading');
						status.textContent = i18n(
							'targetRequired',
							'Please select a saved website.'
						);
					}
					return;
				}
			}

			var queue = getQueue();
			if (!queue.length) {
				if (status) {
					status.classList.add('is-visible', 'is-error');
					status.classList.remove('is-loading');
					status.textContent = i18n('noneLeft', 'Select at least one category to audit (uncheck fewer exclusions).');
				}
				return;
			}

			running = true;
			btn.disabled = true;
			btn.textContent = i18n('runningBtn', 'Running…');

			if (status) {
				status.classList.add('is-visible', 'is-loading');
				status.classList.remove('is-error');
				status.textContent = i18n('running', 'Running audit…');
			}

			// Show bar immediately so users always see feedback on click.
			setProgress(2, '');

			runStep(0, queue)
				.then(function () {
					setProgress(100, i18n('complete', 'Audit completed. Reloading results…'));
					if (status) {
						status.classList.remove('is-loading');
						status.textContent = i18n('complete', 'Audit completed. Reloading results…');
					}
					window.location.href = (window.YWA && window.YWA.redirect) ? window.YWA.redirect : window.location.href;
				})
				.catch(function (err) {
					running = false;
					hideProgress();
					btn.disabled = false;
					btn.textContent = i18n('runLabel', 'Run full audit');
					if (status) {
						status.classList.add('is-visible', 'is-error');
						status.classList.remove('is-loading');
						status.textContent = err && err.message ? err.message : i18n('failed', 'Audit failed.');
					}
				});
		}

		if (btn) {
			btn.addEventListener('click', startAudit);
		}
		if (form) {
			form.addEventListener('submit', startAudit);
		}

		function animateScoreRing(ring) {
			var progressEl = ring.querySelector('.ywa-score-ring__progress');
			var valueEl = ring.querySelector('.ywa-score-ring__value');
			if (!progressEl) {
				return;
			}

			var radius = 54;
			var circumference = 2 * Math.PI * radius;
			var percent = parseFloat(ring.getAttribute('data-percent') || '0');
			if (isNaN(percent)) {
				percent = 0;
			}
			percent = Math.max(0, Math.min(100, percent));
			var offset = circumference * (1 - percent / 100);

			progressEl.style.strokeDasharray = String(circumference);
			progressEl.style.strokeDashoffset = String(circumference);
			progressEl.getBoundingClientRect();
			requestAnimationFrame(function () {
				ring.classList.add('is-animated');
				progressEl.style.strokeDashoffset = String(offset);
			});

			if (valueEl) {
				var target = parseFloat(valueEl.getAttribute('data-target') || ring.getAttribute('data-score') || '0');
				if (isNaN(target)) {
					target = 0;
				}
				var start = null;
				var duration = 900;
				var useInt = Math.abs(target - Math.round(target)) < 0.001;
				function tick(ts) {
					if (start === null) {
						start = ts;
					}
					var t = Math.min(1, (ts - start) / duration);
					var eased = 1 - Math.pow(1 - t, 3);
					var current = target * eased;
					valueEl.textContent = useInt ? String(Math.round(current)) : current.toFixed(2);
					if (t < 1) {
						requestAnimationFrame(tick);
					} else {
						valueEl.textContent = useInt ? String(Math.round(target)) : target.toFixed(2);
					}
				}
				requestAnimationFrame(tick);
			}
		}

		var rings = document.querySelectorAll('.ywa-score-ring');
		for (var i = 0; i < rings.length; i++) {
			animateScoreRing(rings[i]);
		}

		function openCategoryByHash(hash) {
			if (!hash || hash.charAt(0) !== '#') {
				return;
			}
			var target = document.querySelector(hash);
			if (!target || !target.classList.contains('ywa-category')) {
				return;
			}
			target.open = true;
			if (typeof target.scrollIntoView === 'function') {
				target.scrollIntoView({ behavior: 'smooth', block: 'start' });
			}
		}

		var chips = document.querySelectorAll('.ywa-chip[href^="#ywa-cat-"]');
		for (var c = 0; c < chips.length; c++) {
			chips[c].addEventListener('click', function (event) {
				var href = this.getAttribute('href');
				if (!href) {
					return;
				}
				openCategoryByHash(href);
			});
		}
		if (window.location.hash) {
			openCategoryByHash(window.location.hash);
		}

		function openModal(panelId, title, wide) {
			if (!modal || !modalBody) {
				return;
			}
			var panel = document.getElementById(panelId);
			if (!panel) {
				return;
			}
			lastFocus = document.activeElement;
			modalBody.innerHTML = panel.innerHTML;
			if (modalTitle) {
				modalTitle.textContent = title || 'Affected files';
			}
			if (wide) {
				modal.classList.add('ywa-modal--wide');
			} else {
				modal.classList.remove('ywa-modal--wide');
			}
			modal.hidden = false;
			document.body.classList.add('ywa-modal-open');
			var closeBtn = modal.querySelector('.ywa-modal__close');
			if (closeBtn) {
				closeBtn.focus();
			}
		}

		function closeModal() {
			if (!modal || !modalBody) {
				return;
			}
			modal.hidden = true;
			modalBody.innerHTML = '';
			modal.classList.remove('ywa-modal--wide');
			document.body.classList.remove('ywa-modal-open');
			if (lastFocus && typeof lastFocus.focus === 'function') {
				lastFocus.focus();
			}
			lastFocus = null;
		}

		document.addEventListener('click', function (event) {
			var openBtn = event.target.closest('[data-ywa-evidence-open]');
			if (openBtn) {
				event.preventDefault();
				openModal(
					openBtn.getAttribute('data-ywa-evidence-open'),
					openBtn.getAttribute('data-ywa-evidence-title'),
					openBtn.getAttribute('data-ywa-modal-wide') === '1'
				);
				return;
			}
			if (event.target.closest('[data-ywa-modal-close]')) {
				event.preventDefault();
				closeModal();
			}
		});

		document.addEventListener('keydown', function (event) {
			if (event.key === 'Escape' && modal && !modal.hidden) {
				closeModal();
			}
		});
	});
})();
