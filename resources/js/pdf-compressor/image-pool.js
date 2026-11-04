// The compressor's workers (image-worker.js — an entry of the build of its own, its address given by the page): one
// for each processor core the computer can spare (up to six), so several pictures are made smaller at once. Where
// workers cannot run (or do not start within a few seconds), the pictures are made smaller on the page's own thread
// instead, one at a time.
import { encodeImage } from './encode-image.js';

const STARTUP = 5000;

export async function imagePool(workerUrl) {
    const count = Math.max(2, Math.min(6, (navigator.hardwareConcurrency || 4) - 1));
    const workers = workerUrl && typeof OffscreenCanvas !== 'undefined' && typeof Worker !== 'undefined' ? await start(workerUrl, count) : [];
    const pending = new Map();
    const load = workers.map(() => 0);
    let nextId = 0;

    workers.forEach((worker, index) => {
        worker.onmessage = ({ data }) => {
            const job = pending.get(data.id);

            if (job) {
                pending.delete(data.id);
                load[index]--;
                data.error ? job.reject(new Error(data.error)) : job.resolve(data.result);
            }
        };
        worker.onerror = (event) => {
            event.preventDefault();

            for (const [id, job] of pending) {
                if (job.index === index) {
                    pending.delete(id);
                    load[index]--;
                    job.reject(new Error(event.message || 'The worker stopped'));
                }
            }
        };
    });

    return {
        // How many pictures to have on the way at once: enough to keep every worker busy.
        size: workers.length ? workers.length * 2 : 1,

        // The picture made smaller ({ kind, bytes[, channels] }), or null when it would not be smaller enough.
        run(job) {
            if (! workers.length) {
                return encodeImage(job);
            }

            const index = load.indexOf(Math.min(...load));
            const id = nextId++;
            const transfer = job.source.kind === 'bitmap' ? [job.source.bitmap] : [job.source.bytes.buffer];
            load[index]++;

            return new Promise((resolve, reject) => {
                pending.set(id, { resolve, reject, index });
                workers[index].postMessage({ ...job, id }, transfer);
            });
        },

        close() {
            workers.forEach((worker) => worker.terminate());
            pending.forEach((job) => job.reject(new Error('Closed')));
            pending.clear();
        },
    };
}

// The workers, once every one has said it is ready; none if they could not start.
async function start(url, count) {
    const workers = [];

    try {
        for (let i = 0; i < count; i++) {
            workers.push(new Worker(url, { type: 'module' }));
        }

        await Promise.all(
            workers.map(
                (worker) =>
                    new Promise((resolve, reject) => {
                        const timer = setTimeout(() => reject(new Error('The worker did not start')), STARTUP);
                        worker.onmessage = ({ data }) => data === 'ready' && (clearTimeout(timer), resolve());
                        worker.onerror = (event) => (clearTimeout(timer), event.preventDefault(), reject(new Error(event.message)));
                    }),
            ),
        );

        return workers;
    } catch {
        workers.forEach((worker) => worker.terminate());

        return [];
    }
}
