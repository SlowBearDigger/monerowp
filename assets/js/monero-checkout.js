// Live Monero payment status for the order page.
(function () {
	var host = document.getElementById('monero-gateway-status');
	if (!host) return;
	var url = host.getAttribute('data-poll');
	var already = host.getAttribute('data-paid') === '1';
	var rawRedirect = host.getAttribute('data-redirect') || '';
	// Restrict redirects to HTTP(S) or same-origin relative paths.
	var redirect = (/^https?:\/\//i.test(rawRedirect) || /^\/[^/]/.test(rawRedirect)) ? rawRedirect : '';
	if (!url) return;

	var L = window.monero_gatewayL10n || {};
	var STEPS = [L.watching || 'Watching', L.detected || 'Detected', L.confirming || 'Confirming', L.confirmed || 'Confirmed'];

	var wrap = document.createElement('div'); wrap.className = 'mg-prog';
	// The message, not the decorative stepper, announces live status.
	var steps = document.createElement('div'); steps.className = 'mg-steps'; steps.setAttribute('aria-hidden', 'true');
	var dots = [];
	for (var i = 0; i < STEPS.length; i++) {
		if (i > 0) { var bar = document.createElement('span'); bar.className = 'bar'; steps.appendChild(bar); }
		var s = document.createElement('span'); s.className = 's';
		var d = document.createElement('span'); d.className = 'dot';
		var t = document.createElement('span'); t.textContent = STEPS[i];
		s.appendChild(d); s.appendChild(t); steps.appendChild(s); dots.push(s);
	}
	var msg = document.createElement('div'); msg.className = 'mg-msg';
	msg.setAttribute('role', 'status');
	msg.setAttribute('aria-live', 'polite');
	msg.setAttribute('aria-atomic', 'true');
	var tip = document.createElement('div'); tip.className = 'mg-tip';
	wrap.appendChild(steps); wrap.appendChild(msg); wrap.appendChild(tip);
	host.textContent = ''; host.appendChild(wrap);

	function fmt(n) { return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ','); }

	function findPay() {
		return document.querySelector('.monero-gateway-panel monero-pay') || document.querySelector('monero-pay');
	}
	var payInit = findPay();
	var originalAmount = payInit ? payInit.getAttribute('amount') : null;
	var originalLabel = payInit ? payInit.getAttribute('label') : null;

	function syncPayWidget(d) {
		var el = findPay();
		if (!el) return;
		var st = d && d.status;
		// Hide payment instructions once funds are in flight.
		if (d.terminal || d.paid || st === 'mempool' || st === 'unconfirmed' || st === 'locked' || st === 'confirming') {
			el.style.display = 'none';
			return;
		}
		var amount, isTopup;
		if (st === 'partial' && d.shortfallXmr) {
			amount = String(d.shortfallXmr);
			isTopup = true;
		} else if (originalAmount) {
			amount = String(originalAmount);
			isTopup = false;
		} else {
			el.style.display = '';
			return;
		}
		var wantLabel = originalLabel || '';
		if (isTopup && wantLabel) wantLabel = wantLabel + ' (top-up)';
		var curAmount = el.getAttribute('amount');
		var curLabel = el.getAttribute('label') || '';
		if (curAmount === amount && curLabel === wantLabel) {
			el.style.display = '';
			return;
		}
		var addr = el.getAttribute('address');
		if (!addr || !el.parentNode) return;
		var next = document.createElement('monero-pay');
		next.setAttribute('address', addr);
		next.setAttribute('amount', amount);
		if (wantLabel) next.setAttribute('label', wantLabel);
		['theme', 'lang'].forEach(function (a) {
			var v = el.getAttribute(a);
			if (v) next.setAttribute(a, v);
		});
		el.parentNode.replaceChild(next, el);
	}

	function stepFor(d) {
		if (d.paid || d.status === 'paid') return 3;
		if (d.status === 'unconfirmed' || d.status === 'partial' || d.status === 'locked' || d.status === 'confirming') return 2;
		if (d.status === 'mempool') return 1;
		return 0;
	}
	function paint(d) {
		var active = stepFor(d);
		var bars = steps.querySelectorAll('.bar');
		for (var i = 0; i < dots.length; i++) {
			dots[i].className = 's' + (i < active ? ' done' : (i === active ? ' active' : ''));
			if (i > 0) bars[i - 1].className = 'bar' + (i <= active ? ' done' : '');
		}
		var text;
		if (d.terminal) {
			msg.style.color = '#b91c1c';
			if (d.status === 'refunded') text = L.mRefunded || 'This order was refunded. Payment monitoring has stopped.';
			else if (d.status === 'failed') text = L.mFailed || 'This order failed. Payment monitoring has stopped.';
			else text = L.mCancelled || 'This order was cancelled. Payment monitoring has stopped.';
		}
		else if (d.paid) { text = '✓ ' + (L.paid || 'Payment confirmed'); msg.style.color = '#15803d'; }
		else {
			msg.style.color = '#b45309';
			if (d.status === 'mempool') text = L.mMempool || 'Payment detected — waiting for the first confirmation.';
			else if (d.status === 'unconfirmed' || d.status === 'confirming') text = (L.mConfirming || 'Confirming — {c}/{m} confirmations.').replace('{c}', d.confirmations != null ? d.confirmations : 0).replace('{m}', d.minConfirmations != null ? d.minConfirmations : 1);
			else if (d.status === 'partial') text = (L.mPartial || 'Received {r} XMR — send {s} more (QR updated).').replace('{r}', d.receivedXmr != null ? d.receivedXmr : '?').replace('{s}', d.shortfallXmr || '?');
			else if (d.status === 'locked') text = L.mLocked || 'Funds received — maturing on-chain…';
			else if (d.reachable === false) text = L.mConnecting || 'Connecting to the payment scanner…';
			else if (d.syncing) text = L.mSyncing || 'Node catching up to the blockchain — your payment will appear here shortly.';
			else text = L.mWatching || 'Watching the blockchain for your payment…';
		}
		msg.textContent = text;
		tip.textContent = (d.tipHeight ? ((L.block || 'Latest block') + ' #' + fmt(d.tipHeight)) : '');
		syncPayWidget(d);
	}

	if (already) { paint({ paid: true }); return; }
	paint({ status: 'pending' });

	// Back off while waiting, pause when hidden, and stop on terminal states.
	var stopped = false, timer = null, started = Date.now(), lastStatus = null;
	var MAX_MS = 6 * 60 * 60 * 1000;
	function interval() {
		var elapsed = Date.now() - started;
		if (elapsed > 300000) return 30000;
		if (elapsed > 60000) return 15000;
		return 6000;
	}
	function schedule() { if (stopped) return; clearTimeout(timer); timer = setTimeout(run, interval()); }
	function hardStop() {
		msg.style.color = '#b45309';
		msg.textContent = L.mStopped || 'This page stopped refreshing — reload to check status';
		stopped = true;
		clearTimeout(timer);
	}
	function run() {
		if (stopped) return;
		if (Date.now() - started > MAX_MS) {
			// Keep polling while a payment is in flight.
			if (lastStatus !== 'partial' && lastStatus !== 'mempool' && lastStatus !== 'unconfirmed' &&
				lastStatus !== 'locked' && lastStatus !== 'confirming') {
				hardStop();
				return;
			}
		}
		if (document.hidden) { schedule(); return; }
		fetch(url, { headers: { 'Accept': 'application/json' } })
			.then(function (r) { return r.json(); })
			.then(function (d) {
				if (d && d.status) lastStatus = d.status;
				paint(d || {});
				if (d && d.terminal) { stopped = true; clearTimeout(timer); return; }
				if (d && d.paid) {
					stopped = true;
					clearTimeout(timer);
					setTimeout(function () { redirect ? (window.location.href = redirect) : location.reload(); }, 1800);
					return;
				}
				schedule();
			})
			.catch(function () { schedule(); });
	}
	document.addEventListener('visibilitychange', function () {
		if (!document.hidden && !stopped) { clearTimeout(timer); timer = setTimeout(run, 400); }
	});
	timer = setTimeout(run, 1200);
})();
