// A worker of the PDF compressor: pictures made smaller here (encode-image.js), off the page's own thread, while
// other workers do others. Says it is ready once loaded, then answers each job by its id.
import { encodeImage } from './encode-image.js';

self.onmessage = async ({ data: job }) => {
    try {
        const result = await encodeImage(job);
        self.postMessage({ id: job.id, result }, result ? [result.bytes.buffer] : []);
    } catch (error) {
        self.postMessage({ id: job.id, error: String(error?.message ?? error) });
    }
};

self.postMessage('ready');
