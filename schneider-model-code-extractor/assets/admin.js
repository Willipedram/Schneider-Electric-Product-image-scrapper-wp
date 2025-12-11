(function ($) {
    const startButton = $('#sme-start');
    const stopButton = $('#sme-stop');
    const progressBar = $('#sme-progress-bar');
    const progressText = $('#sme-progress-text');
    const logContainer = $('#sme-log');
    const eta = $('#sme-eta');
    const withCountEl = $('#sme-with-count');
    const missingCountEl = $('#sme-missing-count');

    let pollTimer = null;
    let startTime = null;
    let missingRemaining = parseInt(smeExtractor.missingCount, 10) || 0;
    let withCount = parseInt(smeExtractor.withCode, 10) || 0;
    let totalToProcess = parseInt(smeExtractor.totalProducts, 10) || 0;
    let processed = parseInt(smeExtractor.state.processed, 10) || 0;

    function formatNumber(num) {
        return num.toLocaleString ? num.toLocaleString('fa-IR') : num;
    }

    function appendLog(lines) {
        lines.forEach((line) => {
            logContainer.append(`<p>${line}</p>`);
        });
        logContainer.scrollTop(logContainer[0].scrollHeight);
    }

    function updateProgress(total) {
        const percent = total === 0 ? 0 : Math.min(100, Math.round((processed / total) * 100));
        progressBar.css('width', `${percent}%`);
        progressText.text(`${percent}% (${processed}/${total})`);

        if (startTime) {
            const elapsed = (Date.now() - startTime) / 1000;
            const rate = processed / (elapsed || 1);
            const remaining = total - processed;
            const etaSeconds = rate > 0 ? Math.round(remaining / rate) : 0;
            eta.text(etaSeconds > 0 ? `${etaSeconds} ثانیه باقی‌مانده` : '');
        }
    }

    function updateStats(counts) {
        if (!counts) return;

        if (typeof counts.with_code !== 'undefined') {
            withCount = parseInt(counts.with_code, 10) || 0;
        }
        if (typeof counts.missing !== 'undefined') {
            missingRemaining = parseInt(counts.missing, 10) || 0;
        }

        withCountEl.text(formatNumber(withCount));
        missingCountEl.text(formatNumber(missingRemaining));
    }

    function updateButtons(running) {
        startButton.prop('disabled', running || missingRemaining === 0);
        stopButton.prop('disabled', !running);
    }

    function syncState(state, counts) {
        processed = parseInt(state.processed, 10) || 0;
        totalToProcess = parseInt(state.total, 10) || totalToProcess;
        startTime = startTime || (state.running ? Date.now() : null);
        updateStats(counts);
        updateProgress(totalToProcess || missingRemaining || smeExtractor.totalProducts || 0);
        if (state.last_logs && state.last_logs.length) {
            appendLog(state.last_logs);
        }
        if (!state.running && missingRemaining === 0) {
            progressBar.css('width', '100%');
            progressText.text('پایان یافت');
            eta.text('');
        }
        updateButtons(state.running);
    }

    function pollStatus() {
        $.post(
            smeExtractor.ajaxUrl,
            {
                action: 'sme_status_processing',
                nonce: smeExtractor.nonce,
            },
            (response) => {
                if (!response.success) {
                    appendLog([response.data || 'خطای وضعیت رخ داد.']);
                    clearInterval(pollTimer);
                    pollTimer = null;
                    updateButtons(false);
                    return;
                }

                const { state, counts } = response.data;
                syncState(state, counts);

                if (!state.running) {
                    clearInterval(pollTimer);
                    pollTimer = null;
                }
            }
        ).fail(() => {
            appendLog(['مشکل در دریافت وضعیت.']);
        });
    }

    startButton.on('click', () => {
        if (missingRemaining === 0) {
            appendLog(['همه محصولات دارای کد هستند.']);
            return;
        }

        logContainer.empty();
        eta.text('');
        progressBar.css('width', '0%');
        progressText.text('0%');
        appendLog(['در حال شروع پردازش پس‌زمینه...']);
        updateButtons(true);

        $.post(
            smeExtractor.ajaxUrl,
            {
                action: 'sme_start_processing',
                nonce: smeExtractor.nonce,
            },
            (response) => {
                if (!response.success) {
                    appendLog([response.data || 'خطا در شروع پردازش.']);
                    updateButtons(false);
                    return;
                }

                const { state, counts } = response.data;
                processed = 0;
                startTime = Date.now();
                totalToProcess = state.total || counts.missing || totalToProcess;
                syncState(state, counts);

                if (!pollTimer) {
                    pollTimer = setInterval(pollStatus, 4000);
                }
            }
        ).fail(() => {
            appendLog(['درخواست شروع با مشکل مواجه شد.']);
            updateButtons(false);
        });
    });

    stopButton.on('click', () => {
        $.post(
            smeExtractor.ajaxUrl,
            {
                action: 'sme_stop_processing',
                nonce: smeExtractor.nonce,
            },
            (response) => {
                if (response && response.success) {
                    appendLog(['پردازش متوقف شد.']);
                    syncState(response.data.state, response.data.counts);
                } else {
                    appendLog(['امکان توقف پردازش نبود.']);
                }
            }
        ).fail(() => {
            appendLog(['درخواست توقف با مشکل مواجه شد.']);
        }).always(() => {
            updateButtons(false);
            if (pollTimer) {
                clearInterval(pollTimer);
                pollTimer = null;
            }
        });
    });

    if (smeExtractor.state && smeExtractor.state.running) {
        appendLog(['پردازش پس‌زمینه در حال اجرا است...']);
        updateButtons(true);
        pollTimer = setInterval(pollStatus, 4000);
    } else {
        syncState(smeExtractor.state || {}, {
            with_code: withCount,
            missing: missingRemaining,
        });
    }
})(jQuery);
