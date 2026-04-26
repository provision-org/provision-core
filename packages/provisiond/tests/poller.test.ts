import { describe, it, expect } from 'vitest';

describe('activeRuns cleanup pattern', () => {
  it('.finally() removes runId when the task promise resolves', async () => {
    const activeRuns = new Map<string, Promise<void>>();
    const runId = 'task-1';

    const taskPromise = Promise.resolve()
      .catch(() => {})
      .finally(() => {
        activeRuns.delete(runId);
      });
    activeRuns.set(runId, taskPromise);

    expect(activeRuns.size).toBe(1);
    await taskPromise;
    expect(activeRuns.size).toBe(0);
  });

  it('.finally() removes runId when the task promise rejects (caught)', async () => {
    const activeRuns = new Map<string, Promise<void>>();
    const runId = 'task-1';

    const taskPromise = Promise.reject(new Error('boom'))
      .catch(() => {})
      .finally(() => {
        activeRuns.delete(runId);
      });
    activeRuns.set(runId, taskPromise);

    expect(activeRuns.size).toBe(1);
    await taskPromise;
    expect(activeRuns.size).toBe(0);
  });

  it('the previous Promise.race cleanup pattern is broken — never detects settled promises', async () => {
    // Documents the bug that stalled the daemon. The old poller checked
    // whether an in-flight task promise was settled by racing it against
    // a bare resolved sentinel. Microtask ordering guarantees the bare
    // sentinel always wins, so the race ALWAYS resolves to `false`,
    // even when the inner promise is fully settled. With the cleanup
    // never firing, activeRuns grew until maxConcurrent was reached and
    // the daemon went silent forever.
    const settled = Promise.resolve('A');
    await new Promise((r) => setImmediate(r));

    const result = await Promise.race([
      settled.then(() => true, () => true),
      Promise.resolve(false),
    ]);

    expect(result).toBe(false);
  });
});
