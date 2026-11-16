import { build } from 'esbuild';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { spawnSync } from 'node:child_process';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);

const directory = await mkdtemp(join(tmpdir(), 'cte-table-tests-'));
try {
    const output = join(directory, 'table-tests.cjs');
    await build({
        entryPoints: ['tests/frontend/table.test.tsx'],
        outfile: output,
        bundle: true,
        platform: 'node',
        format: 'cjs',
        jsx: 'automatic',
    });
    const result = spawnSync(process.execPath, ['--test', output], { stdio: 'inherit' });
    process.exitCode = result.status ?? 1;
    if (process.exitCode === 0) {
        const dialogs = join(directory, 'dialog-tests.cjs');
        await build({
            entryPoints: ['tests/frontend/dialog.test.tsx'],
            outfile: dialogs,
            bundle: true,
            platform: 'node',
            format: 'cjs',
            jsx: 'automatic',
            plugins: [
                {
                    name: 'dom-test-runtime',
                    setup(builder) {
                        builder.onResolve(
                            { filter: /^(jsdom|react(?:\/.*)?|react-dom(?:\/.*)?)$/ },
                            (args) => ({ path: require.resolve(args.path), external: true }),
                        );
                    },
                },
            ],
        });
        const checks = spawnSync(process.execPath, ['--test', dialogs], { stdio: 'inherit' });
        process.exitCode = checks.status ?? 1;
    }
} finally {
    await rm(directory, { recursive: true, force: true });
}
