/* Exercise the browser bridge's small-chunk transfer contract with mocked WP AJAX. */
'use strict';

const assert = require('assert');
const requests = [];
global.window = global;
global.cpicData = {
    ajaxUrl: '/wp-admin/admin-ajax.php',
    nonce: 'test-nonce',
    nonceField: '_ajax_nonce',
    i18n: { error: 'Request failed.' },
    isEditor: false
};
global.CPICCanvasRenderer = {};
global.jQuery = function () {};
global.fetch = async function (url, options) {
    const fields = Array.from(options.body.entries());
    const values = Object.fromEntries(fields.filter((entry) => typeof entry[1] === 'string'));
    const file = fields.find((entry) => entry[0] === 'chunk')[1];
    requests.push({ url, values, fileSize: file.size });
    return new Response(JSON.stringify({ success: true, data: { received: Number(values.chunk_index) + 1 } }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' }
    });
};

require('../js/cpic-canvas.js');

(async function () {
    const totalBytes = 599288;
    const canvas = {
        toBlob(callback) { callback(new Blob([new Uint8Array(totalBytes)], { type: 'image/png' })); }
    };
    const result = await global.cpicSaveCanvas(canvas, {
        post_id: 42,
        template_hash: 'template-hash',
        input_hash: 'input-hash'
    });
    assert.strictEqual(requests.length, 3, 'PNG is sent in three sequential chunks');
    assert.deepStrictEqual(requests.map((request) => request.fileSize), [262144, 262144, 75000]);
    requests.forEach((request, index) => {
        assert.strictEqual(request.url, '/wp-admin/admin-ajax.php');
        assert.strictEqual(request.values.action, 'cpic_store_rendered_chunk');
        assert.strictEqual(request.values._ajax_nonce, 'test-nonce');
        assert.strictEqual(request.values.post_id, '42');
        assert.strictEqual(request.values.chunk_index, String(index));
        assert.strictEqual(request.values.chunk_count, '3');
        assert.strictEqual(request.values.total_size, String(totalBytes));
        assert.match(request.values.transfer_id, /^[a-f0-9]{32}$/);
    });
    assert.strictEqual(result.received, 3);
    console.log('PASS: browser bridge sends authenticated 256 KB PNG chunks sequentially.');
})().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
