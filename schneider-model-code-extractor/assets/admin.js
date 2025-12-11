(function ($) {
    const startButton = $('#sme-start');
    const progressBar = $('#sme-progress-bar');
    const progressText = $('#sme-progress-text');
    const logContainer = $('#sme-log');
    const eta = $('#sme-eta');
    const withCountEl = $('#sme-with-count');
    const missingCountEl = $('#sme-missing-count');

    let offset = 0;
    let processed = 0;
    let startTime = null;
    let missingRemaining = parseInt(smeExtractor.missingCount, 10) || 0;
    let withCount = parseInt(smeExtractor.withCode, 10) || 0;

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

    function updateStats(updatedCount) {
        if (!updatedCount) return;

        withCount += updatedCount;
        missingRemaining = Math.max(0, missingRemaining - updatedCount);

        const formatNumber = (num) => (num.toLocaleString ? num.toLocaleString('fa-IR') : num);
        withCountEl.text(formatNumber(withCount));
        missingCountEl.text(formatNumber(missingRemaining));
    }

    function processBatch() {
        const total = parseInt(smeExtractor.totalProducts, 10) || 0;
        const batchSize = parseInt(smeExtractor.batchSize, 10) || 20;

        $.post(
            smeExtractor.ajaxUrl,
            {
                action: 'sme_process_products',
                nonce: smeExtractor.nonce,
                offset,
                batchSize,
                totalProducts: total,
            },
            (response) => {
                if (!response.success) {
                    appendLog([response.data || 'خطایی رخ داد.']);
                    startButton.prop('disabled', false);
                    return;
                }

                const data = response.data;
                processed += data.processed;
                offset = data.offset;

                appendLog(data.logs || []);
                updateProgress(total);
                updateStats(data.updated);

                if (data.complete || processed >= total || missingRemaining <= 0) {
                    progressBar.css('width', '100%');
                    progressText.text('پایان یافت');
                    eta.text('');
                    startButton.prop('disabled', false);
                    return;
                }

                setTimeout(processBatch, 250);
            }
        ).fail(() => {
            appendLog(['درخواست با مشکل مواجه شد.']);
            startButton.prop('disabled', false);
        });
    }

    startButton.on('click', () => {
        if (missingRemaining === 0) {
            appendLog(['همه محصولات دارای کد هستند.']);
            return;
        }
        processed = 0;
        offset = 0;
        startTime = Date.now();
        logContainer.empty();
        eta.text('');
        progressBar.css('width', '0%');
        progressText.text('0%');
        startButton.prop('disabled', true);
        processBatch();
    });
})(jQuery);
