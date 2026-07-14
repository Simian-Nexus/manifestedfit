/* Manifested Fit blog — hero slider (vanilla, no deps). */
(function () {
	'use strict';

	document.querySelectorAll('.mf-hero').forEach(function (hero) {
		var slides = hero.querySelectorAll('.mf-slide');
		var dots = hero.querySelectorAll('.mf-dot');
		if (slides.length < 2) { return; }

		var current = 0;
		var timer = null;
		var INTERVAL = 6000;

		function show(i) {
			current = (i + slides.length) % slides.length;
			slides.forEach(function (s, k) { s.classList.toggle('is-active', k === current); });
			dots.forEach(function (d, k) { d.classList.toggle('is-active', k === current); });
		}

		function play() {
			stop();
			timer = setInterval(function () { show(current + 1); }, INTERVAL);
		}
		function stop() {
			if (timer) { clearInterval(timer); timer = null; }
		}

		dots.forEach(function (d) {
			d.addEventListener('click', function () {
				show(parseInt(d.dataset.slide, 10));
				play();
			});
		});
		var prev = hero.querySelector('.mf-prev');
		var next = hero.querySelector('.mf-next');
		if (prev) { prev.addEventListener('click', function () { show(current - 1); play(); }); }
		if (next) { next.addEventListener('click', function () { show(current + 1); play(); }); }

		hero.addEventListener('mouseenter', stop);
		hero.addEventListener('mouseleave', play);
		hero.addEventListener('touchstart', stop, { passive: true });

		// Swipe support.
		var startX = null;
		hero.addEventListener('touchstart', function (e) { startX = e.touches[0].clientX; }, { passive: true });
		hero.addEventListener('touchend', function (e) {
			if (startX === null) { return; }
			var dx = e.changedTouches[0].clientX - startX;
			if (Math.abs(dx) > 40) { show(current + (dx < 0 ? 1 : -1)); }
			startX = null;
			play();
		}, { passive: true });

		if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
			play();
		}
	});
})();
