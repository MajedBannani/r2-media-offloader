(() => {
	const statusEl = document.getElementById('r2mo-status');
	if (!statusEl) {
		return;
	}

	const statusTextEl = document.getElementById('r2mo-status-text');

	const isCompletionMessage = (message) => {
		const normalized = message.toLowerCase();
		if (normalized.includes('complete') || normalized.includes('completed') || normalized.includes('finished')) {
			return true;
		}

		const match = normalized.match(/processed:\s*(\d+),\s*skipped:\s*(\d+),\s*failed:\s*(\d+)/);
		if (match) {
			const processed = Number(match[1]);
			const skipped = Number(match[2]);
			const failed = Number(match[3]);
			const total = processed + skipped + failed;
			return total > 0;
		}

		return false;
	};

	const markComplete = (statusType = '') => {
		statusEl.classList.add('is-complete');
		if (statusType) {
			statusEl.classList.add(`is-${statusType}`);
		}

		const spinner = statusEl.querySelector('.spinner');
		if (spinner) {
			spinner.classList.remove('is-active');
		}
	};

	const setRunning = (message) => {
		if (!message || !statusTextEl) {
			return;
		}

		statusTextEl.textContent = message;
		statusEl.classList.add('is-visible', 'is-running');
		statusEl.classList.remove('is-success', 'is-error', 'is-info', 'is-warning', 'is-complete');

		const spinner = statusEl.querySelector('.spinner');
		if (spinner) {
			spinner.classList.add('is-active');
		}
	};

	const resetIdle = () => {
		statusEl.classList.remove('is-visible', 'is-running', 'is-complete', 'is-success', 'is-error', 'is-info', 'is-warning');
		if (statusTextEl) {
			statusTextEl.textContent = '';
		}

		const spinner = statusEl.querySelector('.spinner');
		if (spinner) {
			spinner.classList.remove('is-active');
		}
	};

	const showStatus = (message, type = '') => {
		if (!message || !statusTextEl) {
			return;
		}

		statusTextEl.textContent = message;
		statusEl.classList.add('is-visible');
		statusEl.classList.remove('is-success', 'is-error', 'is-info', 'is-warning', 'is-complete', 'is-running');

		if (type) {
			statusEl.classList.add(`is-${type}`);
		}

		const spinner = statusEl.querySelector('.spinner');
		if (spinner) {
			spinner.classList.add('is-active');
		}

		const shouldComplete = isCompletionMessage(message) || ['success', 'error', 'warning', 'info'].includes(type);
		if (shouldComplete) {
			markComplete(type);
			setTimeout(() => {
				resetIdle();
			}, 800);
		}
	};

	const dataMessage = statusEl.getAttribute('data-message') || '';
	const dataType = statusEl.getAttribute('data-status') || '';
	if (dataMessage) {
		showStatus(dataMessage, dataType);
	}

	const actionForms = document.querySelectorAll('.r2mo-action-form');
	actionForms.forEach((form) => {
		form.addEventListener('submit', () => {
			const label = form.getAttribute('data-r2mo-action-label') || 'Processing…';
			setRunning(label);

			const button = form.querySelector('input[type="submit"], button[type="submit"]');
			if (button) {
				if (button.tagName.toLowerCase() === 'input') {
					button.setAttribute('data-original-label', button.value);
					button.value = form.getAttribute('data-r2mo-processing-label') || 'Processing…';
				} else {
					button.setAttribute('data-original-label', button.textContent || '');
					button.textContent = form.getAttribute('data-r2mo-processing-label') || 'Processing…';
				}
				button.disabled = true;
			}
		});
	});
})();
