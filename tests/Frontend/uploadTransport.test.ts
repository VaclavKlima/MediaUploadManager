import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import test from 'node:test';
import { fingerprintUploadFile } from '../../resources/js/lib/uploadTransport.ts';

test('fingerprints only the bounded first and last file windows', async () => {
    const bytes = new TextEncoder().encode('abcdefghijklmnop');
    const file = new File([bytes], 'episode.mkv', { lastModified: 1234 });
    const fingerprint = await fingerprintUploadFile(file, 4);

    assert.deepEqual(fingerprint, {
        filename: 'episode.mkv',
        declared_size: 16,
        last_modified_milliseconds: 1234,
        fingerprint_first_sha256: createHash('sha256')
            .update('abcd')
            .digest('hex'),
        fingerprint_last_sha256: createHash('sha256')
            .update('mnop')
            .digest('hex'),
    });
});

test('uses the complete file for both windows when it is smaller than the bound', async () => {
    const file = new File(['episode'], 'small.mkv');
    const fingerprint = await fingerprintUploadFile(file, 1_024);
    const expected = createHash('sha256').update('episode').digest('hex');

    assert.equal(fingerprint.fingerprint_first_sha256, expected);
    assert.equal(fingerprint.fingerprint_last_sha256, expected);
});
