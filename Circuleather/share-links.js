/*
 * Exports the detail page's prepared snapshot card as a PNG using html2canvas.
 * Temporarily places a visible clone in the document because hidden nodes cannot be captured reliably.
 * The clone is always removed and the export button restored after success or failure.
 */

(() => {
    const controls = document.querySelector('[data-share-controls]');
    if (!controls) return;

    const exportButton = controls.querySelector('[data-export-png]');
    const exportCard = controls.querySelector('[data-share-export-card]');
    const status = controls.querySelector('[data-share-status]');

    const showStatus = (message) => {
        status.textContent = message;
        status.hidden = !message;
    };

    const getExportFileName = () => {
        const detailSku = document.querySelector('.detail-sku')?.textContent || document.title || 'circuleather';
        const baseName = detailSku.trim().replace(/[^a-zA-Z0-9-_]+/g, '-').replace(/-+/g, '-').replace(/^-|-$/g, '') || 'circuleather';
        return `${baseName}.png`;
    };

    exportButton?.addEventListener('click', async () => {
        if (!window.html2canvas || !exportCard) {
            showStatus(controls.dataset.exportErrorLabel || 'PNG export is unavailable on this page.');
            return;
        }

        exportButton.disabled = true;
        showStatus(controls.dataset.exportGeneratingLabel || 'Creating PNG...');

        // Use a fixed-size clone for consistent PNG output while retaining the original off-screen card.
        const captureNode = exportCard.cloneNode(true);
        captureNode.style.position = 'relative';
        captureNode.style.left = '0';
        captureNode.style.top = '0';
        captureNode.style.visibility = 'visible';
        captureNode.style.display = 'block';
        captureNode.style.opacity = '1';
        captureNode.style.zIndex = '99999';
        captureNode.style.width = '920px';
        captureNode.style.maxWidth = '920px';
        captureNode.style.margin = '0 auto';
        captureNode.style.padding = '0';
        captureNode.style.pointerEvents = 'none';
        document.body.appendChild(captureNode);

        try {
            const canvas = await window.html2canvas(captureNode, {
                backgroundColor: '#ffffff',
                scale: 2,
                useCORS: true,
                allowTaint: true,
                logging: false,
                width: 920,
                height: captureNode.scrollHeight || 600,
            });

            const link = document.createElement('a');
            link.download = getExportFileName();
            link.href = canvas.toDataURL('image/png');
            link.click();
            showStatus(controls.dataset.exportLabel || 'PNG export created.');
        } catch (error) {
            console.error('PNG export failed:', error);
            showStatus(controls.dataset.exportErrorLabel || 'PNG export could not be created.');
        // Cleanup is required even if canvas rendering or file generation fails.
        } finally {
            captureNode.remove();
            exportButton.disabled = false;
        }
    });
})();