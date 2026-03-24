/**
 * LLM Bot Monitor — Admin Dashboard JS
 * Canvas bar chart + select-all checkbox
 */
(function () {
	'use strict';

	/* ── Bar Chart ── */
	function drawChart() {
		var canvas = document.getElementById('llm-chart');
		if (!canvas || !canvas.dataset.chart) return;

		var data;
		try {
			data = JSON.parse(canvas.dataset.chart);
		} catch (e) {
			return;
		}
		if (!data.length) return;

		// High-DPI support
		var dpr    = window.devicePixelRatio || 1;
		var rect   = canvas.parentElement.getBoundingClientRect();
		var width  = rect.width - 40; // account for panel padding
		var height = 260;

		canvas.width  = width * dpr;
		canvas.height = height * dpr;
		canvas.style.width  = width + 'px';
		canvas.style.height = height + 'px';

		var ctx = canvas.getContext('2d');
		ctx.scale(dpr, dpr);

		var padLeft   = 40;
		var padBottom = 30;
		var padTop    = 10;
		var chartW    = width - padLeft - 10;
		var chartH    = height - padBottom - padTop;

		var max = Math.max.apply(null, data.map(function (d) { return d.hits; }));
		if (max === 0) max = 1;

		var barW = chartW / data.length;

		// Y-axis gridlines
		ctx.strokeStyle = '#f0f0f1';
		ctx.lineWidth   = 1;
		var steps = 4;
		for (var s = 0; s <= steps; s++) {
			var y = padTop + chartH - (chartH * s / steps);
			ctx.beginPath();
			ctx.moveTo(padLeft, y);
			ctx.lineTo(padLeft + chartW, y);
			ctx.stroke();

			// Y-axis labels
			ctx.fillStyle = '#646970';
			ctx.font      = '10px -apple-system, BlinkMacSystemFont, sans-serif';
			ctx.textAlign = 'right';
			ctx.fillText(Math.round(max * s / steps), padLeft - 6, y + 3);
		}

		// Bars
		data.forEach(function (d, i) {
			var h = (d.hits / max) * chartH;
			var x = padLeft + i * barW;
			var y = padTop + chartH - h;

			ctx.fillStyle = '#2271b1';
			ctx.fillRect(x + 1, y, Math.max(barW - 2, 1), h);
		});

		// X-axis labels (every 5th day)
		ctx.fillStyle = '#646970';
		ctx.font      = '10px -apple-system, BlinkMacSystemFont, sans-serif';
		ctx.textAlign = 'center';
		data.forEach(function (d, i) {
			if (i % 5 === 0 || i === data.length - 1) {
				var x = padLeft + i * barW + barW / 2;
				ctx.fillText(d.day.slice(5), x, height - 8); // "MM-DD"
			}
		});
	}

	/* ── Select All Checkbox ── */
	function setupSelectAll() {
		var selectAll = document.getElementById('llm-select-all');
		if (!selectAll) return;

		var checkboxes = document.querySelectorAll('input[name="log_ids[]"]');

		selectAll.addEventListener('change', function () {
			for (var i = 0; i < checkboxes.length; i++) {
				checkboxes[i].checked = selectAll.checked;
			}
		});

		for (var i = 0; i < checkboxes.length; i++) {
			checkboxes[i].addEventListener('change', function () {
				var allChecked = true;
				for (var j = 0; j < checkboxes.length; j++) {
					if (!checkboxes[j].checked) { allChecked = false; break; }
				}
				selectAll.checked = allChecked;
			});
		}
	}

	/* ── Copy to Clipboard ── */
	function setupCopyButtons() {
		document.querySelectorAll('.llm-copy-btn').forEach(function(btn) {
			btn.addEventListener('click', function() {
				var targetId = this.getAttribute('data-target');
				var textarea = document.getElementById(targetId);
				if (!textarea) return;

				var text = textarea.value;
				var button = this;
				var originalText = button.textContent;

				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(text).then(function() {
						button.textContent = 'Kopiert!';
						button.classList.add('copied');
						setTimeout(function() {
							button.textContent = originalText;
							button.classList.remove('copied');
						}, 2000);
					}).catch(function() {
						textarea.select();
						document.execCommand('copy');
						button.textContent = 'Kopiert!';
						button.classList.add('copied');
						setTimeout(function() {
							button.textContent = originalText;
							button.classList.remove('copied');
						}, 2000);
					});
				} else {
					// Fallback
					textarea.select();
					document.execCommand('copy');
					button.textContent = 'Kopiert!';
					button.classList.add('copied');
					setTimeout(function() {
						button.textContent = originalText;
						button.classList.remove('copied');
					}, 2000);
				}
			});
		});
	}

	/* ── Init ── */
	document.addEventListener('DOMContentLoaded', function () {
		drawChart();
		setupSelectAll();
		setupCopyButtons();
	});

	// Redraw chart on resize
	var resizeTimer;
	window.addEventListener('resize', function () {
		clearTimeout(resizeTimer);
		resizeTimer = setTimeout(drawChart, 150);
	});
})();
